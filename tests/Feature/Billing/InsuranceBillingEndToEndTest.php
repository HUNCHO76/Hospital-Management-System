<?php

use App\Models\Charge;
use App\Models\ChargeInsuranceAllocation;
use App\Models\LaboratoryOrderItem;
use App\Services\ChargeService;
use App\Services\InsuranceBillingService;
use App\Services\InvoiceService;
use App\Services\LaboratoryOrderService;
use Illuminate\Validation\ValidationException;

/**
 * Phase 7, Step 56, spec §47 — the complete, realistic pathway: a patient
 * with a primary insurance coverage, a real clinical encounter, an
 * automatically-captured charge (Step 54's own ChargeCaptureService,
 * unmodified by this step), grouped into an invoice (Step 55), with
 * insurance coverage calculated against it. Verifies every downstream
 * figure — patient, facility, invoice, insurance, service, coverage,
 * copayment, patient responsibility, payer responsibility, audit trail, no
 * duplicate allocation — agrees with the same underlying transaction.
 */
test('the full pathway from insurance enrollment through an automatically-captured, insurance-calculated invoice is consistent everywhere', function () {
    $ctx = diagnosisReadyEncounter();
    $accountant = userWithRole('accountant');

    // Patient enrolled in insurance, with an 80% laboratory coverage rule.
    $scheme = insuranceSchemeFor();
    coverageRuleFor($scheme, ['category' => 'laboratory', 'coverage_percentage' => 80]);
    $coverage = primaryCoverageFor($ctx['patient'], $scheme);

    // A billable, priced laboratory test (TZS 20,000 by default).
    $test = billableLaboratoryTest($ctx['hospital']);

    // Doctor orders it — ChargeCaptureService (Step 54) automatically
    // captures a PENDING charge, unmodified by this step.
    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$test->id]], requestAs($ctx['doctor']['user']));
    $orderItem = $order->items->sole();

    $charge = Charge::query()->where('source_type', LaboratoryOrderItem::class)->where('source_id', $orderItem->id)->sole();
    expect($charge->patient_id)->toBe($ctx['patient']->id);
    expect($charge->hospital_id)->toBe($ctx['hospital']->id);
    expect((float) $charge->net_amount)->toBe(20000.0);

    // Billing officer posts the charge and groups it into an invoice.
    app(ChargeService::class)->post($charge, requestAs($accountant));
    $invoice = app(InvoiceService::class)->createDraft($charge->fresh()->patientAccount, [$charge->id], null, requestAs($accountant));

    expect($invoice->patient_id)->toBe($ctx['patient']->id);
    expect($invoice->hospital_id)->toBe($ctx['hospital']->id);

    // Insurance coverage is calculated.
    $allocations = app(InsuranceBillingService::class)->applyToInvoice($invoice, requestAs($accountant));
    $allocation = $allocations->sole();

    expect($allocation->patient_id)->toBe($ctx['patient']->id);
    expect($allocation->hospital_id)->toBe($ctx['hospital']->id);
    expect($allocation->invoice_id)->toBe($invoice->id);
    expect($allocation->patient_insurance_id)->toBe($coverage->id);
    expect((float) $allocation->eligible_amount)->toBe(20000.0);
    expect((float) $allocation->covered_amount)->toBe(16000.0);
    expect((float) $allocation->patient_responsibility)->toBe(4000.0);
    expect($allocation->status)->toBe('partially_covered');

    // The reconciliation invariant: eligible = covered + patient (spec §44).
    expect((float) $allocation->covered_amount + (float) $allocation->patient_responsibility)->toBe((float) $allocation->eligible_amount);

    // No duplicate allocation, even after a second calculation pass.
    app(InsuranceBillingService::class)->applyToInvoice($invoice->fresh(), requestAs($accountant));
    expect(ChargeInsuranceAllocation::query()->where('charge_id', $charge->id)->count())->toBe(1);

    // Audit trail: both the initial calculation and the recalculation were recorded.
    $this->assertDatabaseHas('audit_logs', ['action' => 'insurance_coverage_calculated', 'auditable_id' => $invoice->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'insurance_coverage_recalculated', 'auditable_id' => $invoice->id]);

    // Finalizing the invoice freezes the allocation permanently.
    app(InvoiceService::class)->post($invoice->fresh(), requestAs($accountant));
    coverageRuleFor($scheme, ['category' => 'laboratory', 'coverage_percentage' => 10, 'effective_from' => now()->toDateString()]);

    $frozen = $allocation->fresh();
    expect((float) $frozen->covered_amount)->toBe(16000.0);
    expect((float) $frozen->patient_responsibility)->toBe(4000.0);
});

test('a patient with no insurance at all is simply never given an insurance allocation — self-pay remains the invoice\'s own total', function () {
    $ctx = diagnosisReadyEncounter();
    $accountant = userWithRole('accountant');

    $test = billableLaboratoryTest($ctx['hospital']);
    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$test->id]], requestAs($ctx['doctor']['user']));
    $orderItem = $order->items->sole();
    $charge = Charge::query()->where('source_type', LaboratoryOrderItem::class)->where('source_id', $orderItem->id)->sole();

    app(ChargeService::class)->post($charge, requestAs($accountant));
    $invoice = app(InvoiceService::class)->createDraft($charge->fresh()->patientAccount, [$charge->id], null, requestAs($accountant));

    expect(fn () => app(InsuranceBillingService::class)->applyToInvoice($invoice, requestAs($accountant)))
        ->toThrow(ValidationException::class);

    $posted = app(InvoiceService::class)->post($invoice->fresh(), requestAs($accountant));
    expect((float) $posted->total_amount)->toBe(20000.0);
    expect(ChargeInsuranceAllocation::query()->count())->toBe(0);
});
