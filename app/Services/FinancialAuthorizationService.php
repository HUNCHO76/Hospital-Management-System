<?php

namespace App\Services;

use App\Models\BillingAdjustment;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentRefund;
use App\Models\PaymentReversal;
use Illuminate\Validation\ValidationException;

/**
 * Determines whether a billable source (a ReceptionVisit, LaboratoryOrderItem,
 * PrescriptionOrderItem, RadiologyOrderItem, etc.) is financially cleared for
 * service execution (Phase 7, Step 59).
 *
 * Two statuses only: STATUS_AUTHORIZED (patient outstanding ≤ 0) or
 * STATUS_PAYMENT_REQUIRED (charge missing, not posted, or invoice balance > 0).
 *
 * The outstanding calculation mirrors PaymentService::calculateOutstanding()
 * exactly — kept here to avoid a circular service dependency. The two must
 * remain in sync if the billing calculation ever changes.
 */
class FinancialAuthorizationService
{
    public const STATUS_AUTHORIZED = 'authorized';

    public const STATUS_PAYMENT_REQUIRED = 'payment_required';

    /**
     * Return the authorization status for a pre-loaded Charge (or null when
     * no charge exists). A null charge is always PAYMENT_REQUIRED.
     */
    public function statusForCharge(?Charge $charge): string
    {
        if ($charge === null) {
            return self::STATUS_PAYMENT_REQUIRED;
        }

        if ($charge->status !== Charge::STATUS_POSTED) {
            return self::STATUS_PAYMENT_REQUIRED;
        }

        if ($charge->invoice_id === null) {
            return self::STATUS_PAYMENT_REQUIRED;
        }

        $invoice = Invoice::find($charge->invoice_id);

        if ($invoice === null) {
            return self::STATUS_PAYMENT_REQUIRED;
        }

        return $this->statusForInvoice($invoice);
    }

    /**
     * Look up the active charge for a source record and return its
     * authorization status. VOIDED/CANCELLED charges are ignored.
     */
    public function statusForSource(string $sourceType, int $sourceId): string
    {
        $charge = Charge::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereNotIn('status', [Charge::STATUS_VOIDED, Charge::STATUS_CANCELLED])
            ->first();

        return $this->statusForCharge($charge);
    }

    /**
     * Alias for statusForSource — the "Required" suffix signals that the
     * absence of a charge is always PAYMENT_REQUIRED (no silent pass-through).
     */
    public function statusForSourceRequired(string $sourceType, int $sourceId): string
    {
        return $this->statusForSource($sourceType, $sourceId);
    }

    /**
     * Assert that every source in $sourceIds has STATUS_AUTHORIZED.
     * Throws a ValidationException the moment any source is not authorized,
     * so the caller's transaction can roll back cleanly.
     *
     * @param  list<int>  $sourceIds
     *
     * @throws ValidationException
     */
    public function assertAuthorizedForSources(string $sourceType, array $sourceIds): void
    {
        if (empty($sourceIds)) {
            return;
        }

        $charges = Charge::query()
            ->where('source_type', $sourceType)
            ->whereIn('source_id', $sourceIds)
            ->whereNotIn('status', [Charge::STATUS_VOIDED, Charge::STATUS_CANCELLED])
            ->get()
            ->keyBy('source_id');

        $invoiceIds = $charges->pluck('invoice_id')->filter()->unique()->values()->all();
        $invoiceStatuses = $this->batchInvoiceStatuses($invoiceIds);

        foreach ($sourceIds as $sourceId) {
            $charge = $charges->get($sourceId);

            if ($charge === null || $charge->status !== Charge::STATUS_POSTED) {
                throw ValidationException::withMessages([
                    'financial_authorization' => 'Payment is required before this service can proceed.',
                ]);
            }

            if ($charge->invoice_id === null) {
                throw ValidationException::withMessages([
                    'financial_authorization' => 'Payment is required before this service can proceed.',
                ]);
            }

            if (($invoiceStatuses[$charge->invoice_id] ?? self::STATUS_PAYMENT_REQUIRED) !== self::STATUS_AUTHORIZED) {
                throw ValidationException::withMessages([
                    'financial_authorization' => 'Payment is required before this service can proceed.',
                ]);
            }
        }
    }

    /**
     * Batch-check authorization status for multiple invoices in one set of
     * database queries. Returns map of invoice_id => status string.
     *
     * @param  list<int>  $invoiceIds
     * @return array<int, string>
     */
    private function batchInvoiceStatuses(array $invoiceIds): array
    {
        if (empty($invoiceIds)) {
            return [];
        }

        $invoices = Invoice::query()
            ->whereIn('id', $invoiceIds)
            ->with(['charges' => fn ($q) => $q->where('status', Charge::STATUS_POSTED)->with('insuranceAllocation')])
            ->get();

        $result = [];
        foreach ($invoices as $invoice) {
            $outstanding = $this->calculateOutstanding($invoice);
            $result[$invoice->id] = $outstanding['patient_outstanding'] <= 0.0
                ? self::STATUS_AUTHORIZED
                : self::STATUS_PAYMENT_REQUIRED;
        }

        return $result;
    }

    /**
     * Determine STATUS_AUTHORIZED / STATUS_PAYMENT_REQUIRED for a single
     * loaded Invoice by computing its patient outstanding balance.
     */
    private function statusForInvoice(Invoice $invoice): string
    {
        $outstanding = $this->calculateOutstanding($invoice);

        return $outstanding['patient_outstanding'] <= 0.0
            ? self::STATUS_AUTHORIZED
            : self::STATUS_PAYMENT_REQUIRED;
    }

    /**
     * Mirrors PaymentService::calculateOutstanding() — the authoritative
     * outstanding-balance calculation, duplicated here to avoid a circular
     * dependency between FinancialAuthorizationService and PaymentService.
     *
     * @return array{patient_responsibility: float, patient_outstanding: float}
     */
    private function calculateOutstanding(Invoice $invoice): array
    {
        $charges = $invoice->relationLoaded('charges')
            ? $invoice->charges
            : $invoice->charges()->where('status', Charge::STATUS_POSTED)->with('insuranceAllocation')->get();

        $patientResponsibility = round($charges->sum(function (Charge $charge) {
            return $charge->insuranceAllocation
                ? (float) $charge->insuranceAllocation->patient_responsibility
                : (float) $charge->net_amount;
        }), 2);

        if ($patientResponsibility <= 0.0) {
            return ['patient_responsibility' => 0.0, 'patient_outstanding' => 0.0];
        }

        $patientPaid = round((float) PaymentAllocation::query()
            ->where('invoice_id', $invoice->id)
            ->whereHas('payment', fn ($q) => $q->where('status', Payment::STATUS_POSTED))
            ->sum('amount'), 2);

        $reversalsTotal = round((float) PaymentReversal::query()
            ->where('invoice_id', $invoice->id)
            ->sum('amount'), 2);

        $refundsTotal = round((float) PaymentRefund::query()
            ->where('invoice_id', $invoice->id)
            ->sum('amount'), 2);

        $creditAdjustments = round((float) BillingAdjustment::query()
            ->where('invoice_id', $invoice->id)
            ->where('direction', BillingAdjustment::DIRECTION_CREDIT)
            ->sum('amount'), 2);

        $debitAdjustments = round((float) BillingAdjustment::query()
            ->where('invoice_id', $invoice->id)
            ->where('direction', BillingAdjustment::DIRECTION_DEBIT)
            ->sum('amount'), 2);

        $patientOutstanding = round(
            $patientResponsibility - $patientPaid + $reversalsTotal + $refundsTotal - $creditAdjustments + $debitAdjustments,
            2
        );

        return [
            'patient_responsibility' => $patientResponsibility,
            'patient_outstanding' => $patientOutstanding,
        ];
    }
}
