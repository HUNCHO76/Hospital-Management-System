<?php

namespace App\Services;

use App\Models\Charge;
use App\Services\Concerns\ValidatesMoney;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the billable-line-item lifecycle (Phase 7, Step 52) — create (always
 * PENDING), post (PENDING -> POSTED, the moment it becomes a real financial
 * fact), void (PENDING -> VOIDED, a mistake caught before posting), cancel
 * (POSTED -> CANCELLED, a controlled correction after the fact). This is
 * deliberately the *foundation* only: nothing here is wired to fire
 * automatically from a clinical event (Step 54, "Patient Charges & Charge
 * Capture", owns that integration) — every charge created here is an
 * explicit, traceable, actor-attributed action.
 */
class ChargeService
{
    use ValidatesMoney;

    public function __construct(protected AuditLogService $auditLog, protected PatientAccountService $accounts) {}

    /**
     * Record a new charge against a patient's account, auto-provisioning the
     * account on first use. `source_type`/`source_id` trace the charge back
     * to whatever originated it (spec §13) — both nullable for a genuinely
     * manual/ad-hoc charge, but a caller integrating a real clinical source
     * should always pass them.
     *
     * @param  array{hospital_id: int, patient_id: int, encounter_id?: int|null, service_id?: int|null, source_type?: string|null, source_id?: int|null, service_code_snapshot?: string|null, description: string, notes?: string|null, quantity?: int, unit_price: float|string, discount_amount?: float|string|null, charged_at?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function create(array $data, Request $request): Charge
    {
        $quantity = (int) ($data['quantity'] ?? 1);
        $unitPrice = $this->validateMoney($data['unit_price'], 'unit_price', allowZero: true);
        $discount = $this->validateMoney($data['discount_amount'] ?? 0, 'discount_amount', allowZero: true);

        if ($quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be at least 1.']);
        }

        $gross = round($unitPrice * $quantity, 2);

        if ($discount > $gross) {
            throw ValidationException::withMessages(['discount_amount' => 'Discount cannot exceed the gross amount.']);
        }

        [$charge, $accountWasNew, $account] = DB::transaction(function () use ($data, $quantity, $unitPrice, $discount, $gross, $request) {
            [$account, $accountWasNew] = $this->accounts->findOrCreateForPatient((int) $data['hospital_id'], (int) $data['patient_id'], $request);

            $charge = Charge::create([
                'hospital_id' => $data['hospital_id'],
                'patient_account_id' => $account->id,
                'patient_id' => $data['patient_id'],
                'encounter_id' => $data['encounter_id'] ?? null,
                'service_id' => $data['service_id'] ?? null,
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'service_code_snapshot' => $data['service_code_snapshot'] ?? null,
                'description' => $data['description'],
                'notes' => $data['notes'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'gross_amount' => $gross,
                'discount_amount' => $discount,
                'net_amount' => round($gross - $discount, 2),
                'currency' => $account->currency,
                'status' => Charge::STATUS_PENDING,
                'charged_at' => $data['charged_at'] ?? now(),
                'created_by' => $request->user()->id,
            ]);

            return [$charge, $accountWasNew, $account];
        });

        if ($accountWasNew) {
            $this->auditLog->record(
                'billing_account_created',
                $account,
                null,
                $account->only(['hospital_id', 'patient_id', 'account_number', 'status', 'currency']),
                $request,
            );
        }

        $this->auditLog->record(
            'charge_created',
            $charge,
            null,
            $charge->only(['patient_account_id', 'source_type', 'source_id', 'description', 'quantity', 'unit_price', 'net_amount', 'status']),
            $request,
        );

        return $charge;
    }

    /**
     * Post a PENDING charge — it now counts toward the account's balance,
     * immutably (spec §25: correcting it afterward requires cancel(), never
     * an edit).
     *
     * @throws ValidationException
     */
    public function post(Charge $charge, Request $request): Charge
    {
        $charge = DB::transaction(function () use ($charge, $request) {
            $locked = Charge::query()->whereKey($charge->id)->lockForUpdate()->firstOrFail();

            if (! $locked->canTransitionTo(Charge::STATUS_POSTED)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot post a charge with status \"{$locked->status}\".",
                ]);
            }

            $locked->update([
                'status' => Charge::STATUS_POSTED,
                'posted_at' => now(),
                'updated_by' => $request->user()->id,
            ]);

            return $locked;
        });

        $this->auditLog->record('charge_posted', $charge, ['status' => Charge::STATUS_PENDING], $charge->only(['status', 'posted_at']), $request);

        return $charge->fresh();
    }

    /**
     * Void a PENDING charge — it never became a financial fact, so this
     * requires no reason beyond what the actor supplies (mirrors
     * DispensingService::cancel()'s own reason-required, never-deleted
     * precedent).
     *
     * @throws ValidationException
     */
    public function void(Charge $charge, string $reason, Request $request): Charge
    {
        $charge = $this->transitionToCancelledState($charge, Charge::STATUS_VOIDED, $reason, $request);

        $this->auditLog->record('charge_voided', $charge, ['status' => Charge::STATUS_PENDING], $charge->only(['status', 'cancellation_reason']), $request);

        return $charge;
    }

    /**
     * Cancel a POSTED charge — a controlled correction after the fact
     * (spec §24/§25): the row is never edited or deleted, only its status
     * changes, with a mandatory reason. It immediately stops counting toward
     * the account's balance.
     *
     * @throws ValidationException
     */
    public function cancel(Charge $charge, string $reason, Request $request): Charge
    {
        $charge = $this->transitionToCancelledState($charge, Charge::STATUS_CANCELLED, $reason, $request);

        $this->auditLog->record('charge_cancelled', $charge, ['status' => Charge::STATUS_POSTED], $charge->only(['status', 'cancellation_reason']), $request);

        return $charge;
    }

    /**
     * @throws ValidationException
     */
    protected function transitionToCancelledState(Charge $charge, string $targetStatus, string $reason, Request $request): Charge
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        return DB::transaction(function () use ($charge, $targetStatus, $reason, $request) {
            $locked = Charge::query()->whereKey($charge->id)->lockForUpdate()->firstOrFail();

            if (! $locked->canTransitionTo($targetStatus)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot change a charge with status \"{$locked->status}\" to \"{$targetStatus}\".",
                ]);
            }

            $locked->update([
                'status' => $targetStatus,
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()->id,
                'cancellation_reason' => $reason,
            ]);

            return $locked;
        });
    }
}
