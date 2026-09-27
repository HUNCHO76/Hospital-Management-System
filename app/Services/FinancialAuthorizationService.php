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
     *
     * Pass STATUS_AUTHORIZED as $noChargeStatus to apply the "no-charge rule":
     * when no charge was captured (e.g. a free/uncharged consultation), the
     * gate passes rather than blocking. The strict default (STATUS_PAYMENT_REQUIRED)
     * is correct for clinical sources where a missing charge is a billing gap.
     */
    public function statusForSource(string $sourceType, int $sourceId, string $noChargeStatus = self::STATUS_PAYMENT_REQUIRED): string
    {
        $charge = Charge::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereNotIn('status', [Charge::STATUS_VOIDED, Charge::STATUS_CANCELLED])
            ->first();

        if ($charge === null) {
            return $noChargeStatus;
        }

        return $this->statusForCharge($charge);
    }

    /**
     * Alias for statusForSource — the "Required" suffix signals that the
     * absence of a charge is always PAYMENT_REQUIRED (no silent pass-through).
     */
    public function statusForSourceRequired(string $sourceType, int $sourceId): string
    {
        return $this->statusForSource($sourceType, $sourceId, self::STATUS_PAYMENT_REQUIRED);
    }

    /**
     * Assert that every source in $sourceIds has STATUS_AUTHORIZED.
     * Throws a ValidationException the moment any source is not authorized,
     * so the caller's transaction can roll back cleanly.
     *
     * Pass STATUS_AUTHORIZED as $noChargeStatus to apply the "no-charge rule"
     * for items where the charge capture was skipped (e.g. a medication with
     * no configured price, or a lab test with no mapped billing service). The
     * strict default (STATUS_PAYMENT_REQUIRED) is correct when a missing
     * charge is always a billing gap that must block the service.
     *
     * @param  list<int>  $sourceIds
     *
     * @throws ValidationException
     */
    public function assertAuthorizedForSources(string $sourceType, array $sourceIds, string $noChargeStatus = self::STATUS_PAYMENT_REQUIRED): void
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

            if ($charge === null) {
                if ($noChargeStatus !== self::STATUS_AUTHORIZED) {
                    throw ValidationException::withMessages([
                        'financial_authorization' => 'Payment is required before this service can proceed.',
                    ]);
                }

                continue;
            }

            if ($charge->status !== Charge::STATUS_POSTED) {
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
     * Batch-resolve statuses for many sources of the same type in a fixed,
     * small number of queries — the read-only counterpart to
     * assertAuthorizedForSources(), for listing views (e.g. the reception
     * dashboard) that need a status per row without an N+1 query per row.
     *
     * @param  list<int>  $sourceIds
     * @return array<int, string> source_id => status
     */
    public function statusesForSources(string $sourceType, array $sourceIds, string $noChargeStatus = self::STATUS_PAYMENT_REQUIRED): array
    {
        if (empty($sourceIds)) {
            return [];
        }

        $charges = Charge::query()
            ->where('source_type', $sourceType)
            ->whereIn('source_id', $sourceIds)
            ->whereNotIn('status', [Charge::STATUS_VOIDED, Charge::STATUS_CANCELLED])
            ->get()
            ->keyBy('source_id');

        $invoiceIds = $charges->pluck('invoice_id')->filter()->unique()->values()->all();
        $invoiceStatuses = $this->batchInvoiceStatuses($invoiceIds);

        $result = [];

        foreach ($sourceIds as $sourceId) {
            $charge = $charges->get($sourceId);

            if ($charge === null) {
                $result[$sourceId] = $noChargeStatus;

                continue;
            }

            if ($charge->status !== Charge::STATUS_POSTED || $charge->invoice_id === null) {
                $result[$sourceId] = self::STATUS_PAYMENT_REQUIRED;

                continue;
            }

            $result[$sourceId] = $invoiceStatuses[$charge->invoice_id] ?? self::STATUS_PAYMENT_REQUIRED;
        }

        return $result;
    }

    /**
     * Batch-check authorization status for multiple invoices in a fixed,
     * small number of database queries regardless of how many invoices are
     * given — the per-invoice sums calculateOutstanding() computes one at a
     * time are computed here with GROUP BY across all invoice ids at once,
     * so this stays safe for an unpaginated listing (e.g. the reception
     * dashboard) driving statusesForSources() over many rows.
     *
     * @param  list<int>  $invoiceIds
     * @return array<int, string>
     */
    protected function batchInvoiceStatuses(array $invoiceIds): array
    {
        if (empty($invoiceIds)) {
            return [];
        }

        $invoices = Invoice::query()
            ->whereIn('id', $invoiceIds)
            ->with(['charges' => fn ($q) => $q->where('status', Charge::STATUS_POSTED)->with('insuranceAllocation')])
            ->get();

        $paidByInvoice = PaymentAllocation::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->whereHas('payment', fn ($q) => $q->where('status', Payment::STATUS_POSTED))
            ->selectRaw('invoice_id, SUM(amount) as total')
            ->groupBy('invoice_id')
            ->pluck('total', 'invoice_id');

        $reversalsByInvoice = PaymentReversal::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->selectRaw('invoice_id, SUM(amount) as total')
            ->groupBy('invoice_id')
            ->pluck('total', 'invoice_id');

        $refundsByInvoice = PaymentRefund::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->selectRaw('invoice_id, SUM(amount) as total')
            ->groupBy('invoice_id')
            ->pluck('total', 'invoice_id');

        $creditAdjustmentsByInvoice = BillingAdjustment::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->where('direction', BillingAdjustment::DIRECTION_CREDIT)
            ->selectRaw('invoice_id, SUM(amount) as total')
            ->groupBy('invoice_id')
            ->pluck('total', 'invoice_id');

        $debitAdjustmentsByInvoice = BillingAdjustment::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->where('direction', BillingAdjustment::DIRECTION_DEBIT)
            ->selectRaw('invoice_id, SUM(amount) as total')
            ->groupBy('invoice_id')
            ->pluck('total', 'invoice_id');

        $result = [];

        foreach ($invoices as $invoice) {
            $patientResponsibility = round($invoice->charges->sum(function (Charge $charge) {
                return $charge->insuranceAllocation
                    ? (float) $charge->insuranceAllocation->patient_responsibility
                    : (float) $charge->net_amount;
            }), 2);

            if ($patientResponsibility <= 0.0) {
                $result[$invoice->id] = self::STATUS_AUTHORIZED;

                continue;
            }

            $patientOutstanding = round(
                $patientResponsibility
                - (float) ($paidByInvoice[$invoice->id] ?? 0)
                + (float) ($reversalsByInvoice[$invoice->id] ?? 0)
                + (float) ($refundsByInvoice[$invoice->id] ?? 0)
                - (float) ($creditAdjustmentsByInvoice[$invoice->id] ?? 0)
                + (float) ($debitAdjustmentsByInvoice[$invoice->id] ?? 0),
                2
            );

            $result[$invoice->id] = $patientOutstanding <= 0.0
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
