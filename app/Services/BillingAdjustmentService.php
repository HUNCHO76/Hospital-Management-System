<?php

namespace App\Services;

use App\Models\BillingAdjustment;
use App\Models\Invoice;
use App\Services\Concerns\ValidatesMoney;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the billing adjustment lifecycle (Phase 7, Step 58, spec §5) —
 * deliberate, approved changes to the amount a patient owes against an
 * invoice: discounts, write-offs, billing corrections. Adjustments do not
 * touch the invoice's own frozen total_amount; PaymentService::
 * calculateOutstanding() reads them live as credit_adjustments /
 * debit_adjustments and folds them into the patient_outstanding figure.
 */
class BillingAdjustmentService
{
    use ValidatesMoney;

    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Create a billing adjustment against a POSTED invoice (spec §5) — locks
     * the invoice, validates the invoice is still POSTED, and creates the
     * adjustment atomically.
     *
     * @param  array{type: string, direction: string, amount: float|string, reason: string, notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function create(Invoice $invoice, array $data, Request $request): BillingAdjustment
    {
        $amount = $this->validateMoney($data['amount'] ?? null, 'amount', allowZero: false);

        if (! in_array($data['type'] ?? null, BillingAdjustment::TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'Invalid adjustment type.']);
        }

        if (! in_array($data['direction'] ?? null, BillingAdjustment::DIRECTIONS, true)) {
            throw ValidationException::withMessages(['direction' => 'Invalid adjustment direction.']);
        }

        $reason = trim($data['reason'] ?? '');
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        $adjustment = DB::transaction(function () use ($invoice, $amount, $data, $reason, $request) {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Invoice::STATUS_POSTED) {
                throw ValidationException::withMessages([
                    'invoice_id' => "Cannot adjust an invoice with status \"{$locked->status}\".",
                ]);
            }

            return BillingAdjustment::create([
                'invoice_id' => $locked->id,
                'hospital_id' => $locked->hospital_id,
                'patient_id' => $locked->patient_id,
                'patient_account_id' => $locked->patient_account_id,
                'type' => $data['type'],
                'direction' => $data['direction'],
                'amount' => $amount,
                'currency' => $locked->currency,
                'reason' => $reason,
                'adjusted_by' => $request->user()->id,
                'adjusted_at' => now(),
                'notes' => filled($data['notes'] ?? null) ? $data['notes'] : null,
            ]);
        });

        $this->auditLog->record(
            'billing_adjustment_posted',
            $adjustment,
            null,
            [
                'adjustment_number' => $adjustment->adjustment_number,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'type' => $adjustment->type,
                'direction' => $adjustment->direction,
                'amount' => $adjustment->amount,
                'reason' => $adjustment->reason,
            ],
            $request,
        );

        return $adjustment->fresh(['adjustedBy']);
    }

    /**
     * Base query for searchable/filterable adjustments listings.
     *
     * @param  array<string, mixed>  $filters
     */
    public function adjustmentsQuery(array $filters): Builder
    {
        $query = BillingAdjustment::query()
            ->with(['invoice:id,invoice_number', 'hospital:id,name', 'patient:id,first_name,middle_name,last_name,patient_number', 'adjustedBy:id,UserName'])
            ->latest();

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $q) use ($search) {
                $q->where('adjustment_number', 'like', "%{$search}%")
                    ->orWhereHas('invoice', fn ($q2) => $q2->where('invoice_number', 'like', "%{$search}%"))
                    ->orWhereHas('patient', fn ($q2) => $q2->where('patient_number', 'like', "%{$search}%"));
            });
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['direction'])) {
            $query->where('direction', $filters['direction']);
        }

        return $query;
    }

    public function scopeToHospital(Builder $query, ?int $hospitalId): Builder
    {
        if ($hospitalId !== null) {
            $query->where('hospital_id', $hospitalId);
        }

        return $query;
    }
}
