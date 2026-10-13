<?php

namespace App\Services;

use App\Models\Service;
use App\Models\ServicePrice;
use App\Services\Concerns\ValidatesMoney;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns effective-dated, facility-specific service pricing (Phase 7, Step
 * 53) — a price is never edited or overwritten (spec §17/§40); a change is
 * always a new row. `create()` doubles as the versioning mechanism: when the
 * new price's range immediately follows an existing *open-ended* ("current,
 * ongoing") price for the same service+hospital, that old price's own
 * `effective_to` is automatically closed out the day before the new price
 * starts — the exact workflow spec §19/§40's own worked example describes
 * ("Old: TZS 20,000 until 2026-09-30 / New: TZS 25,000 from 2026-10-01").
 * Any other overlap is rejected outright (spec §18/§31): at most one ACTIVE
 * price may ever apply to a given service+hospital+date.
 */
class ServicePricingService
{
    use ValidatesMoney;

    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * @param  array{service_id: int, hospital_id: int, practitioner_id?: int|null, amount: float|string, effective_from: string, effective_to?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function create(array $data, Request $request): ServicePrice
    {
        $amount = $this->validateMoney($data['amount'], 'amount', allowZero: true);
        $from = Carbon::parse($data['effective_from'])->startOfDay();
        $to = filled($data['effective_to'] ?? null) ? Carbon::parse($data['effective_to'])->startOfDay() : null;
        $practitionerId = $data['practitioner_id'] ?? null;

        if ($to !== null && $to->lt($from)) {
            throw ValidationException::withMessages(['effective_to' => 'The end date cannot be before the start date.']);
        }

        return DB::transaction(function () use ($data, $amount, $from, $to, $practitionerId, $request) {
            $service = Service::query()->whereKey($data['service_id'])->lockForUpdate()->firstOrFail();

            // Lock every existing active price for this service+hospital
            // (scoped to the same practitioner — NULL = facility-wide) so
            // two administrators can never both decide "no overlap" against
            // the same stale snapshot (spec §27/§52).
            $existing = ServicePrice::query()
                ->where('service_id', $service->id)
                ->where('hospital_id', $data['hospital_id'])
                ->where('status', ServicePrice::STATUS_ACTIVE)
                ->when($practitionerId !== null,
                    fn ($q) => $q->where('practitioner_id', $practitionerId),
                    fn ($q) => $q->whereNull('practitioner_id'),
                )
                ->lockForUpdate()
                ->get();

            $closeOldPriceTo = null;
            $openEnded = $existing->firstWhere('effective_to', null);

            if ($openEnded) {
                if (! $openEnded->effective_from->startOfDay()->lt($from)) {
                    throw ValidationException::withMessages([
                        'effective_from' => 'This overlaps the current open-ended price for this service and facility.',
                    ]);
                }

                $closeOldPriceTo = $from->copy()->subDay();
            }

            foreach ($existing as $candidate) {
                if ($openEnded && $candidate->is($openEnded)) {
                    continue;
                }

                if ($this->rangesOverlap($candidate->effective_from, $candidate->effective_to, $from, $to)) {
                    throw ValidationException::withMessages([
                        'effective_from' => 'This overlaps an existing price period for this service and facility.',
                    ]);
                }
            }

            if ($closeOldPriceTo !== null) {
                $openEnded->update(['effective_to' => $closeOldPriceTo]);
            }

            $price = ServicePrice::create([
                'service_id' => $service->id,
                'hospital_id' => $data['hospital_id'],
                'practitioner_id' => $practitionerId,
                'amount' => $amount,
                'currency' => config('billing.currency'),
                'effective_from' => $from,
                'effective_to' => $to,
                'status' => ServicePrice::STATUS_ACTIVE,
                'created_by' => $request->user()->id,
            ]);

            $this->auditLog->record(
                'price_created',
                $price,
                null,
                $price->only(['service_id', 'hospital_id', 'practitioner_id', 'amount', 'currency', 'effective_from', 'effective_to']),
                $request,
            );

            if ($closeOldPriceTo !== null) {
                $this->auditLog->record(
                    'price_superseded',
                    $openEnded,
                    ['effective_to' => null],
                    ['effective_to' => $closeOldPriceTo->toDateString()],
                    $request,
                );
            }

            return $price;
        });
    }

    /**
     * Correct the amount of the current (active, open-ended) price in place.
     * The one deliberate exception to "a price is never edited" — history
     * (closed/superseded/cancelled prices) stays immutable and every change
     * is audit-logged with its old/new amount and reason.
     *
     * @throws ValidationException
     */
    public function update(ServicePrice $price, float|string $amount, string $reason, Request $request): ServicePrice
    {
        $amount = $this->validateMoney($amount, 'amount', allowZero: true);

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        $old = null;

        $updated = DB::transaction(function () use ($price, $amount, &$old) {
            $locked = ServicePrice::query()->whereKey($price->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ServicePrice::STATUS_ACTIVE || $locked->effective_to !== null) {
                throw ValidationException::withMessages(['amount' => 'Only the current open-ended price can be edited.']);
            }

            $old = $locked->amount;
            $locked->update(['amount' => $amount]);

            return $locked;
        });

        $this->auditLog->record(
            'price_updated',
            $updated,
            ['amount' => $old],
            ['amount' => $updated->amount, 'reason' => $reason],
            $request,
        );

        return $updated->fresh();
    }

    /**
     * Pull a mistakenly-entered price before (or after) it takes effect —
     * never a delete; the row stays visible with its reason (spec §17/§25).
     *
     * @throws ValidationException
     */
    public function cancel(ServicePrice $price, string $reason, Request $request): ServicePrice
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        $price = DB::transaction(function () use ($price, $reason, $request) {
            $locked = ServicePrice::query()->whereKey($price->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ServicePrice::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['status' => 'This price has already been cancelled.']);
            }

            $locked->update([
                'status' => ServicePrice::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()->id,
                'cancellation_reason' => $reason,
            ]);

            return $locked;
        });

        $this->auditLog->record('price_deactivated', $price, ['status' => ServicePrice::STATUS_ACTIVE], $price->only(['status', 'cancellation_reason']), $request);

        return $price->fresh();
    }

    /**
     * Whether two [from, to] ranges (either `to` possibly null/open-ended)
     * overlap at all.
     */
    protected function rangesOverlap(Carbon $aFrom, ?Carbon $aTo, Carbon $bFrom, ?Carbon $bTo): bool
    {
        $aEndsBeforeBStarts = $aTo !== null && $aTo->startOfDay()->lt($bFrom);
        $bEndsBeforeAStarts = $bTo !== null && $bTo->startOfDay()->lt($aFrom);

        return ! $aEndsBeforeBStarts && ! $bEndsBeforeAStarts;
    }
}
