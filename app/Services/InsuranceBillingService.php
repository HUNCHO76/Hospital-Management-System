<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\ChargeInsuranceAllocation;
use App\Models\DispensingItem;
use App\Models\InsuranceCoverageRule;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\Service;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Determines who is financially responsible for a posted charge once
 * insurance is considered (Phase 7, Step 56, spec Â§1) â€” the central service
 * spec Â§8 itself asks for. Deliberately answers only "how much does the
 * insurer/patient owe," never "how much has been paid" (Step 57) or "was
 * this claim submitted to NHIF" (a later phase) â€” see this class's own
 * "Integration boundary" note in docs/insurance-billing.md.
 *
 * **Eligibility vs. coverage** (spec Â§7), kept as two distinct concerns:
 * `primaryCoverage()`/`isCoverageValidOn()` answer "is this patient
 * currently covered" (the record exists, is administratively ACTIVE, and
 * the charge's own date falls within its effective range â€” mirrors
 * `PatientInsurance`'s own `status` vs. date-lapse distinction, per
 * `docs/insurance.md`); `calculateForCharge()` answers "how much of this
 * specific charge is covered," a completely separate question resolved via
 * `CoverageRuleResolver`. `PatientInsurance.verification_status` (an
 * internal-only, manually-set confirmation flag â€” no live provider
 * verification exists anywhere in this codebase) is deliberately **not** a
 * hard eligibility gate here: spec Â§6's own coverage-validity checklist
 * (exists, active, effective/expiry dates) never mentions it, and this
 * codebase's own documentation is explicit that it is an administrative
 * confirmation, not a live check. It remains fully visible to the UI as an
 * informational signal.
 *
 * **Coverage priority** (spec Â§11): exactly one `PatientInsurance` may be
 * `is_primary` per patient (already enforced by `PatientInsuranceService`)
 * â€” this service always resolves that one record and never stacks a
 * secondary coverage on top of it, per spec's own "do not implement
 * secondary insurance complexity unless the existing architecture requires
 * it." A patient with no primary coverage is simply not eligible for
 * insurance billing at all (self-pay) â€” `applyToInvoice()` refuses outright
 * rather than fabricating a zero-value allocation nobody asked for.
 */
class InsuranceBillingService
{
    public function __construct(
        protected CoverageRuleResolver $coverageRules,
        protected AuditLogService $auditLog,
    ) {}

    /**
     * The patient's single primary insurance coverage record, if any â€”
     * regardless of whether it is currently valid (see isCoverageValidOn()
     * for that separate question). Never more than one exists per patient
     * (PatientInsuranceService's own invariant).
     */
    public function primaryCoverage(Patient $patient): ?PatientInsurance
    {
        return PatientInsurance::query()
            ->where('patient_id', $patient->id)
            ->where('is_primary', true)
            ->first();
    }

    /**
     * Whether a coverage record was administratively active and within its
     * own effective date range on the given date (spec Â§6) â€” the
     * "eligibility" half of eligibility-vs-coverage.
     */
    public function isCoverageValidOn(PatientInsurance $coverage, DateTimeInterface $date): bool
    {
        if ($coverage->status !== PatientInsurance::STATUS_ACTIVE) {
            return false;
        }

        $date = Carbon::parse($date)->startOfDay();

        if ($coverage->effective_from !== null && $coverage->effective_from->startOfDay()->gt($date)) {
            return false;
        }

        return $coverage->effective_to === null || $coverage->effective_to->startOfDay()->gte($date);
    }

    /**
     * A read-only eligibility summary for display (spec Â§32's "Verify
     * Coverage" step) â€” never a live external check (none exists), purely a
     * read of this patient's own primary coverage record as of today.
     *
     * @return array{coverage: PatientInsurance|null, eligible: bool, reason: string|null}
     */
    public function eligibility(Patient $patient): array
    {
        $coverage = $this->primaryCoverage($patient);

        if ($coverage === null) {
            return ['coverage' => null, 'eligible' => false, 'reason' => 'No primary insurance coverage is on file for this patient.'];
        }

        if (! $this->isCoverageValidOn($coverage, now())) {
            return ['coverage' => $coverage, 'eligible' => false, 'reason' => 'The primary coverage on file is not currently active or has lapsed.'];
        }

        return ['coverage' => $coverage, 'eligible' => true, 'reason' => null];
    }

    /**
     * Pure calculation for a single charge against a given coverage record
     * â€” no database write, directly unit-testable (spec Â§45). Never trusts
     * a caller-supplied amount: `eligible_amount` is always the charge's
     * own already-server-computed `net_amount` (spec Â§33).
     *
     * @return array<string, mixed> every ChargeInsuranceAllocation field this
     *                              produces, except the identity/audit
     *                              columns (hospital_id/patient_id/
     *                              invoice_id/charge_id/patient_insurance_id/
     *                              currency/calculated_at/calculated_by),
     *                              which the caller fills in
     *
     * @throws ValidationException
     */
    public function calculateForCharge(Charge $charge, PatientInsurance $coverage): array
    {
        $eligibleAmount = round((float) $charge->net_amount, 2);
        $referenceDate = $charge->charged_at ?? now();

        if (! $this->isCoverageValidOn($coverage, $referenceDate)) {
            return $this->rejectedResult($eligibleAmount, 'The patient\'s coverage was not active on the date this charge was incurred.');
        }

        if ($coverage->insurance_scheme_id === null) {
            return $this->notCoveredResult($eligibleAmount, null, 'No insurance scheme is recorded on this coverage â€” no coverage rules can be resolved.');
        }

        $service = $charge->relationLoaded('service') ? $charge->service : ($charge->service_id ? Service::find($charge->service_id) : null);
        $category = $this->resolveCategory($charge, $service);

        $rule = $this->coverageRules->resolveRule($coverage->insuranceScheme, $service, $category, Carbon::parse($referenceDate));

        if ($rule === null) {
            return $this->notCoveredResult($eligibleAmount, null, null);
        }

        [$coveredAmount, $copayApplied] = match ($rule->coverage_type) {
            InsuranceCoverageRule::TYPE_FULL => [$eligibleAmount, null],
            InsuranceCoverageRule::TYPE_NOT_COVERED => [0.0, null],
            InsuranceCoverageRule::TYPE_PERCENTAGE => [round($eligibleAmount * ((float) $rule->coverage_percentage / 100), 2), null],
            InsuranceCoverageRule::TYPE_FIXED_COPAY => (function () use ($eligibleAmount, $rule) {
                $copay = min((float) $rule->fixed_copay_amount, $eligibleAmount);

                return [round($eligibleAmount - $copay, 2), round($copay, 2)];
            })(),
        };

        // The patient's share is always derived, never independently
        // rounded â€” this is what guarantees covered_amount +
        // patient_responsibility === eligible_amount exactly (spec Â§24/Â§44),
        // with no possibility of a rounding-induced discrepancy between the
        // two halves.
        $patientResponsibility = round($eligibleAmount - $coveredAmount, 2);

        return [
            'insurance_coverage_rule_id' => $rule->id,
            'coverage_type_applied' => $rule->coverage_type,
            'coverage_percentage_applied' => $rule->coverage_type === InsuranceCoverageRule::TYPE_PERCENTAGE ? $rule->coverage_percentage : null,
            'copay_amount_applied' => $copayApplied,
            'eligible_amount' => $eligibleAmount,
            'covered_amount' => $coveredAmount,
            'patient_responsibility' => $patientResponsibility,
            'status' => $this->statusFor($coveredAmount, $patientResponsibility),
            'rejection_reason' => null,
        ];
    }

    /**
     * Calculate and persist the insurance allocation for every charge on a
     * DRAFT invoice (spec Â§22/Â§42) â€” the transactional entry point. Only
     * permitted while the invoice is still DRAFT (spec Â§29's own "after
     * finalization: no silent recalculation" â€” since a charge can only ever
     * be added to a DRAFT invoice in the first place, POSTED's charge set is
     * already permanently frozen, so this is the only point recalculation
     * could ever apply anyway). Re-running this on the same invoice while
     * still DRAFT recalculates every charge's allocation in place (an
     * UPDATE, never a duplicate row â€” `charge_id` is UNIQUE) and is audited
     * distinctly as a recalculation.
     *
     * @return Collection<int, ChargeInsuranceAllocation>
     *
     * @throws ValidationException
     */
    public function applyToInvoice(Invoice $invoice, Request $request): Collection
    {
        $allocations = DB::transaction(function () use ($invoice, $request) {
            $lockedInvoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (! $lockedInvoice->isDraft()) {
                throw ValidationException::withMessages([
                    'status' => "Cannot calculate insurance coverage for an invoice with status \"{$lockedInvoice->status}\".",
                ]);
            }

            $charges = Charge::query()->where('invoice_id', $lockedInvoice->id)->lockForUpdate()->with('service')->get();

            if ($charges->isEmpty()) {
                throw ValidationException::withMessages(['charges' => 'This invoice has no charges to calculate coverage for.']);
            }

            $patient = Patient::query()->whereKey($lockedInvoice->patient_id)->firstOrFail();
            $coverage = $this->primaryCoverage($patient);

            if ($coverage === null) {
                throw ValidationException::withMessages(['coverage' => 'This patient has no primary insurance coverage on file.']);
            }

            $isRecalculation = ChargeInsuranceAllocation::query()->where('invoice_id', $lockedInvoice->id)->exists();

            $allocations = $charges->map(function (Charge $charge) use ($lockedInvoice, $coverage, $request) {
                $result = $this->calculateForCharge($charge, $coverage);

                return ChargeInsuranceAllocation::updateOrCreate(
                    ['charge_id' => $charge->id],
                    [...$result,
                        'hospital_id' => $charge->hospital_id,
                        'patient_id' => $charge->patient_id,
                        'invoice_id' => $lockedInvoice->id,
                        'patient_insurance_id' => $coverage->id,
                        'currency' => $charge->currency,
                        'calculated_at' => now(),
                        'calculated_by' => $request->user()->id,
                    ],
                );
            });

            $this->auditLog->record(
                $isRecalculation ? 'insurance_coverage_recalculated' : 'insurance_coverage_calculated',
                $lockedInvoice,
                null,
                [
                    'patient_insurance_id' => $coverage->id,
                    'charge_count' => $allocations->count(),
                    'total_covered' => round((float) $allocations->sum('covered_amount'), 2),
                    'total_patient_responsibility' => round((float) $allocations->sum('patient_responsibility'), 2),
                ],
                $request,
            );

            return $allocations;
        });

        return $allocations;
    }

    /**
     * The service-category vocabulary a charge belongs to, for
     * category-level rule matching (spec Â§12/Â§13). A service-linked charge
     * uses its own Service's category directly (never a second,
     * independently-maintained classification). A pharmacy-sourced charge
     * carries no `service_id` at all (Medication is deliberately never
     * mapped into the Service catalog â€” see ChargeCaptureService's own
     * Pharmacy Boundary docblock, Step 54), so it is identified by its
     * `source_type` instead, reusing the `Service::CATEGORIES` vocabulary's
     * own existing 'pharmacy' entry rather than inventing a parallel one.
     */
    protected function resolveCategory(Charge $charge, ?Service $service): ?string
    {
        if ($service !== null) {
            return $service->category;
        }

        if ($charge->source_type === DispensingItem::class) {
            return 'pharmacy';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rejectedResult(float $eligibleAmount, string $reason): array
    {
        return [
            'insurance_coverage_rule_id' => null,
            'coverage_type_applied' => null,
            'coverage_percentage_applied' => null,
            'copay_amount_applied' => null,
            'eligible_amount' => $eligibleAmount,
            'covered_amount' => 0.0,
            'patient_responsibility' => $eligibleAmount,
            'status' => ChargeInsuranceAllocation::STATUS_REJECTED,
            'rejection_reason' => $reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function notCoveredResult(float $eligibleAmount, ?int $ruleId, ?string $reason): array
    {
        return [
            'insurance_coverage_rule_id' => $ruleId,
            'coverage_type_applied' => $ruleId !== null ? InsuranceCoverageRule::TYPE_NOT_COVERED : null,
            'coverage_percentage_applied' => null,
            'copay_amount_applied' => null,
            'eligible_amount' => $eligibleAmount,
            'covered_amount' => 0.0,
            'patient_responsibility' => $eligibleAmount,
            'status' => ChargeInsuranceAllocation::STATUS_NOT_COVERED,
            'rejection_reason' => $reason,
        ];
    }

    protected function statusFor(float $coveredAmount, float $patientResponsibility): string
    {
        if ($patientResponsibility <= 0.0) {
            return ChargeInsuranceAllocation::STATUS_FULLY_COVERED;
        }

        if ($coveredAmount <= 0.0) {
            return ChargeInsuranceAllocation::STATUS_NOT_COVERED;
        }

        return ChargeInsuranceAllocation::STATUS_PARTIALLY_COVERED;
    }
}
