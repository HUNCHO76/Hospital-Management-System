<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\Charge;
use App\Models\ClinicalEncounter;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentRefund;
use App\Models\PaymentReversal;
use Illuminate\Support\Collection;

/**
 * Aggregates the financial picture for a single inpatient admission (Phase 9,
 * Step 83). Reads from the existing billing models — never creates financial
 * records itself; charge capture stays in ChargeCaptureService, payments in
 * PaymentService.
 *
 * "Charges for this admission" = charges where encounter_id belongs to an
 * inpatient encounter for this admission, PLUS the admission's own bed/service
 * charge (source_type = Admission::class, source_id = admission->id). A charge
 * satisfying both conditions is returned once — SQL OR semantics guarantee no
 * duplication.
 */
class InpatientBillingService
{
    /**
     * All non-voided, non-cancelled charges attributable to this admission.
     *
     * @return Collection<int, Charge>
     */
    public function chargesForAdmission(Admission $admission): Collection
    {
        $encounterIds = ClinicalEncounter::query()
            ->where('admission_id', $admission->id)
            ->pluck('id')
            ->toArray();

        return Charge::query()
            ->where(function ($q) use ($admission, $encounterIds): void {
                if (! empty($encounterIds)) {
                    $q->whereIn('encounter_id', $encounterIds);
                }
                $q->orWhere(function ($inner) use ($admission): void {
                    $inner->where('source_type', Admission::class)
                        ->where('source_id', $admission->id);
                });
            })
            ->whereNotIn('status', [Charge::STATUS_VOIDED, Charge::STATUS_CANCELLED])
            ->with(['insuranceAllocation', 'service:id,code,name', 'createdBy:id,UserName', 'encounter:id,encounter_number'])
            ->orderBy('charged_at')
            ->get();
    }

    /**
     * Compute the inpatient financial summary for an admission.
     *
     * @return array{
     *   payer_type: string,
     *   total_charges: float,
     *   pending_charges: float,
     *   nhif_covered: float,
     *   patient_responsibility: float,
     *   cash_paid: float,
     *   outstanding: float,
     *   status: string,
     *   charge_count: int,
     *   currency: string,
     * }
     */
    public function summary(Admission $admission): array
    {
        $charges = $this->chargesForAdmission($admission);

        $posted = $charges->where('status', Charge::STATUS_POSTED);
        $pending = $charges->where('status', Charge::STATUS_PENDING);

        $totalCharges = round((float) $posted->sum('net_amount'), 2);
        $pendingTotal = round((float) $pending->sum('net_amount'), 2);

        $nhifCovered = round((float) $posted->sum(function (Charge $charge): float {
            return $charge->insuranceAllocation
                ? (float) $charge->insuranceAllocation->covered_amount
                : 0.0;
        }), 2);

        $patientResponsibility = round((float) $posted->sum(function (Charge $charge): float {
            return $charge->insuranceAllocation
                ? (float) $charge->insuranceAllocation->patient_responsibility
                : (float) $charge->net_amount;
        }), 2);

        $invoiceIds = $posted->pluck('invoice_id')->filter()->unique()->values()->toArray();

        $cashPaid = 0.0;
        $reversalsTotal = 0.0;
        $refundsTotal = 0.0;

        if (! empty($invoiceIds)) {
            $cashPaid = round((float) PaymentAllocation::query()
                ->whereIn('invoice_id', $invoiceIds)
                ->whereHas('payment', fn ($q) => $q->where('status', Payment::STATUS_POSTED))
                ->sum('amount'), 2);

            $reversalsTotal = round((float) PaymentReversal::query()
                ->whereIn('invoice_id', $invoiceIds)
                ->sum('amount'), 2);

            $refundsTotal = round((float) PaymentRefund::query()
                ->whereIn('invoice_id', $invoiceIds)
                ->sum('amount'), 2);
        }

        $outstanding = round($patientResponsibility - $cashPaid + $reversalsTotal + $refundsTotal, 2);

        return [
            'payer_type' => $admission->patient_insurance_id ? 'nhif' : 'cash',
            'total_charges' => $totalCharges,
            'pending_charges' => $pendingTotal,
            'nhif_covered' => $nhifCovered,
            'patient_responsibility' => $patientResponsibility,
            'cash_paid' => $cashPaid,
            'outstanding' => $outstanding,
            'status' => $this->deriveStatus($totalCharges, $patientResponsibility, $outstanding, $charges->isEmpty()),
            'charge_count' => $charges->count(),
            'currency' => config('billing.currency', 'KES'),
        ];
    }

    private function deriveStatus(float $totalCharges, float $patientResponsibility, float $outstanding, bool $isEmpty): string
    {
        if ($isEmpty || $totalCharges <= 0.0) {
            return 'unbilled';
        }

        if ($outstanding <= 0.0) {
            return 'paid';
        }

        if ($outstanding < $patientResponsibility) {
            return 'partial';
        }

        return 'outstanding';
    }
}
