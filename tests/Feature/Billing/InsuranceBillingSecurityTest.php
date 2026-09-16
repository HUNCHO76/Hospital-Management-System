<?php

use App\Models\ChargeInsuranceAllocation;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Services\InvoiceService;

/**
 * Phase 7, Step 56, spec §33/§45 — a client can never dictate
 * covered_amount/patient_amount/copayment/coverage_percentage/eligibility;
 * every figure is server-calculated. The calculate endpoint accepts no body
 * at all, so this is largely "prove extraneous input has zero effect,"
 * mirroring InvoiceSecurityTest's own mass-assignment-proof style.
 */
test('a client-supplied covered_amount, patient_responsibility, or status is ignored by the calculate endpoint', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $scheme = insuranceSchemeFor();
    coverageRuleFor($scheme, ['coverage_percentage' => 80]);
    primaryCoverageFor($patient, $scheme);

    $accountant = userWithRole('accountant');
    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 10000]);
    $invoice = app(InvoiceService::class)->createDraft($charge->patientAccount, [$charge->id], null, requestAs($accountant));

    $this->actingAs($accountant)->post(route('billing.invoices.insurance.calculate', $invoice), [
        'covered_amount' => 999999,
        'patient_responsibility' => 0,
        'status' => 'fully_covered',
        'coverage_percentage_applied' => 100,
        'eligible_amount' => 1,
    ])->assertRedirect();

    $allocation = ChargeInsuranceAllocation::query()->where('charge_id', $charge->id)->sole();

    expect((float) $allocation->covered_amount)->toBe(8000.0);
    expect((float) $allocation->patient_responsibility)->toBe(2000.0);
    expect((float) $allocation->eligible_amount)->toBe(10000.0);
    expect($allocation->status)->toBe('partially_covered');
});

test('a client cannot force a specific insurance_coverage_rule_id or patient_insurance_id via the calculate endpoint', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $scheme = insuranceSchemeFor();
    $realRule = coverageRuleFor($scheme, ['coverage_percentage' => 80]);
    $coverage = primaryCoverageFor($patient, $scheme);

    $otherScheme = insuranceSchemeFor();
    $otherRule = coverageRuleFor($otherScheme, ['coverage_percentage' => 10]);

    $accountant = userWithRole('accountant');
    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 10000]);
    $invoice = app(InvoiceService::class)->createDraft($charge->patientAccount, [$charge->id], null, requestAs($accountant));

    $this->actingAs($accountant)->post(route('billing.invoices.insurance.calculate', $invoice), [
        'insurance_coverage_rule_id' => $otherRule->id,
        'patient_insurance_id' => 999999,
    ])->assertRedirect();

    $allocation = ChargeInsuranceAllocation::query()->where('charge_id', $charge->id)->sole();

    expect($allocation->insurance_coverage_rule_id)->toBe($realRule->id);
    expect($allocation->patient_insurance_id)->toBe($coverage->id);
});

test('a coverage rule percentage outside 0-100 is rejected over HTTP, never trusting the client\'s own number', function () {
    $accountant = userWithRole('accountant');
    $scheme = insuranceSchemeFor();

    $this->actingAs($accountant)->post(route('billing-insurance-rules.store'), [
        'insurance_scheme_id' => $scheme->id,
        'coverage_type' => 'percentage',
        'coverage_percentage' => 250,
        'effective_from' => now()->toDateString(),
    ])->assertSessionHasErrors('coverage_percentage');
});

test('a client-supplied created_by or status is never mass-assignable through the create-rule request', function () {
    $accountant = userWithRole('accountant');
    $other = User::factory()->create();
    $scheme = insuranceSchemeFor();

    $this->actingAs($accountant)->post(route('billing-insurance-rules.store'), [
        'insurance_scheme_id' => $scheme->id,
        'coverage_type' => 'percentage',
        'coverage_percentage' => 80,
        'effective_from' => now()->toDateString(),
        'created_by' => $other->id,
        'status' => 'cancelled',
    ])->assertRedirect(route('billing-insurance-rules.index'));

    $rule = $scheme->coverageRules()->sole();
    expect($rule->created_by)->toBe($accountant->id);
    expect($rule->status)->toBe('active');
});

test('a cancellation reason containing markup is stored as plain text, never interpreted as HTML server-side', function () {
    $accountant = userWithRole('accountant');
    $scheme = insuranceSchemeFor();
    $rule = coverageRuleFor($scheme);

    $payload = '<script>alert(1)</script>';

    $this->actingAs($accountant)->post(route('billing-insurance-rules.cancel', $rule), ['reason' => $payload])->assertRedirect();

    expect($rule->fresh()->cancellation_reason)->toBe($payload);
});

test('exporting no coverage percentage bypasses insurance calculation entirely when the invoice patient is ineligible', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();

    $accountant = userWithRole('accountant');
    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 10000]);
    $invoice = app(InvoiceService::class)->createDraft($charge->patientAccount, [$charge->id], null, requestAs($accountant));

    $this->actingAs($accountant)->post(route('billing.invoices.insurance.calculate', $invoice), [
        'covered_amount' => 999999,
    ])->assertSessionHasErrors('coverage');

    expect(ChargeInsuranceAllocation::query()->count())->toBe(0);
});
