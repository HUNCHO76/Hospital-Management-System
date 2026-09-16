<?php

use App\Models\DispensingItem;
use App\Models\Hospital;
use App\Models\InsuranceCoverageRule;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Services\InsuranceBillingService;
use Illuminate\Validation\ValidationException;

/**
 * Phase 7, Step 56, spec §45 — pure calculation unit tests for
 * InsuranceBillingService::calculateForCharge()/eligibility()/
 * primaryCoverage(), directly, with no HTTP/invoice involved. Every branch
 * of the responsibility split (percentage, fixed copay, full, not covered,
 * rejected) and the rule-resolution priority order.
 */
test('a percentage rule splits the charge correctly and never loses a cent to rounding', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $scheme = insuranceSchemeFor();
    coverageRuleFor($scheme, ['coverage_type' => 'percentage', 'coverage_percentage' => 80]);
    $coverage = primaryCoverageFor($patient, $scheme);

    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 10000]);

    $result = app(InsuranceBillingService::class)->calculateForCharge($charge, $coverage);

    expect($result['eligible_amount'])->toBe(10000.0);
    expect($result['covered_amount'])->toBe(8000.0);
    expect($result['patient_responsibility'])->toBe(2000.0);
    expect($result['status'])->toBe('partially_covered');
    expect($result['covered_amount'] + $result['patient_responsibility'])->toBe($result['eligible_amount']);
});

test('a fixed copay rule charges the patient exactly the copay amount, capped at the eligible amount', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $scheme = insuranceSchemeFor();
    coverageRuleFor($scheme, ['coverage_type' => 'fixed_copay', 'coverage_percentage' => null, 'fixed_copay_amount' => 2000]);
    $coverage = primaryCoverageFor($patient, $scheme);

    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 10000]);
    $result = app(InsuranceBillingService::class)->calculateForCharge($charge, $coverage);

    expect($result['covered_amount'])->toBe(8000.0);
    expect($result['patient_responsibility'])->toBe(2000.0);
    expect($result['copay_amount_applied'])->toBe(2000.0);

    // A fixed copay larger than the charge itself is capped, never negative insurance coverage.
    $tinyCharge = postedChargeFor($hospital, $patient, ['unit_price' => 500]);
    $tinyResult = app(InsuranceBillingService::class)->calculateForCharge($tinyCharge, $coverage);

    expect($tinyResult['patient_responsibility'])->toBe(500.0);
    expect($tinyResult['covered_amount'])->toBe(0.0);
    expect($tinyResult['status'])->toBe('not_covered');
});

test('a full coverage rule leaves nothing for the patient', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $scheme = insuranceSchemeFor();
    coverageRuleFor($scheme, ['coverage_type' => 'full', 'coverage_percentage' => null]);
    $coverage = primaryCoverageFor($patient, $scheme);

    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 10000]);
    $result = app(InsuranceBillingService::class)->calculateForCharge($charge, $coverage);

    expect($result['covered_amount'])->toBe(10000.0);
    expect($result['patient_responsibility'])->toBe(0.0);
    expect($result['status'])->toBe('fully_covered');
});

test('a not_covered rule leaves everything for the patient, distinct from no rule at all', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $scheme = insuranceSchemeFor();
    $rule = coverageRuleFor($scheme, ['category' => 'dental', 'coverage_type' => 'not_covered', 'coverage_percentage' => null]);
    $coverage = primaryCoverageFor($patient, $scheme);

    $service = serviceFor(['category' => 'dental']);
    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 10000, 'service_id' => $service->id]);
    $result = app(InsuranceBillingService::class)->calculateForCharge($charge, $coverage);

    expect($result['covered_amount'])->toBe(0.0);
    expect($result['patient_responsibility'])->toBe(10000.0);
    expect($result['status'])->toBe('not_covered');
    expect($result['insurance_coverage_rule_id'])->toBe($rule->id);
});

test('no matching rule at all is treated as not covered, never a hard-coded default', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $scheme = insuranceSchemeFor();
    $coverage = primaryCoverageFor($patient, $scheme);

    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 10000]);
    $result = app(InsuranceBillingService::class)->calculateForCharge($charge, $coverage);

    expect($result['insurance_coverage_rule_id'])->toBeNull();
    expect($result['covered_amount'])->toBe(0.0);
    expect($result['patient_responsibility'])->toBe(10000.0);
    expect($result['status'])->toBe('not_covered');
});

test('coverage that had lapsed on the charge date is rejected, not merely uncovered', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $scheme = insuranceSchemeFor();
    coverageRuleFor($scheme, ['coverage_percentage' => 80]);
    $coverage = primaryCoverageFor($patient, $scheme, ['status' => 'suspended']);

    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 10000]);
    $result = app(InsuranceBillingService::class)->calculateForCharge($charge, $coverage);

    expect($result['status'])->toBe('rejected');
    expect($result['covered_amount'])->toBe(0.0);
    expect($result['patient_responsibility'])->toBe(10000.0);
    expect($result['rejection_reason'])->not->toBeNull();
});

test('a service-specific rule wins over a category rule, which wins over the scheme default', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $scheme = insuranceSchemeFor();
    $service = serviceFor(['category' => 'laboratory']);

    coverageRuleFor($scheme, ['coverage_percentage' => 50]); // scheme default
    coverageRuleFor($scheme, ['category' => 'laboratory', 'coverage_percentage' => 70]); // category
    coverageRuleFor($scheme, ['service_id' => $service->id, 'coverage_percentage' => 95]); // exact service

    $coverage = primaryCoverageFor($patient, $scheme);
    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 10000, 'service_id' => $service->id]);

    $result = app(InsuranceBillingService::class)->calculateForCharge($charge, $coverage);

    expect((float) $result['coverage_percentage_applied'])->toBe(95.0);
});

test('a pharmacy-sourced charge (no service_id) resolves via the pharmacy category rule', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $scheme = insuranceSchemeFor();
    coverageRuleFor($scheme, ['coverage_percentage' => 50]); // scheme default
    coverageRuleFor($scheme, ['category' => 'pharmacy', 'coverage_percentage' => 60]);

    $coverage = primaryCoverageFor($patient, $scheme);
    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 10000, 'source_type' => DispensingItem::class, 'source_id' => 1]);

    $result = app(InsuranceBillingService::class)->calculateForCharge($charge, $coverage);

    expect((float) $result['coverage_percentage_applied'])->toBe(60.0);
});

test('a coverage record with no insurance scheme at all cannot resolve any rule', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $coverage = PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'insurance_scheme_id' => null,
        'is_primary' => true,
        'status' => 'active',
        'effective_from' => now()->subMonth(),
        'effective_to' => null,
    ]);

    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 10000]);
    $result = app(InsuranceBillingService::class)->calculateForCharge($charge, $coverage);

    expect($result['status'])->toBe('not_covered');
    expect($result['covered_amount'])->toBe(0.0);
});

test('eligibility() reports no coverage on file when the patient has none', function () {
    $patient = Patient::factory()->create();

    $eligibility = app(InsuranceBillingService::class)->eligibility($patient);

    expect($eligibility['eligible'])->toBeFalse();
    expect($eligibility['coverage'])->toBeNull();
});

test('eligibility() reports eligible for an active, currently-effective primary coverage', function () {
    $patient = Patient::factory()->create();
    $scheme = insuranceSchemeFor();
    primaryCoverageFor($patient, $scheme);

    $eligibility = app(InsuranceBillingService::class)->eligibility($patient);

    expect($eligibility['eligible'])->toBeTrue();
    expect($eligibility['coverage'])->not->toBeNull();
});

test('an ambiguous overlapping rule match is surfaced loudly rather than silently guessed', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $scheme = insuranceSchemeFor();
    $coverage = primaryCoverageFor($patient, $scheme);

    // Force two overlapping active rules directly at the model level,
    // bypassing InsuranceCoverageRuleService's own overlap prevention — the
    // exact "should be structurally impossible" scenario
    // CoverageRuleResolver's own docblock guards against.
    InsuranceCoverageRule::factory()->create([
        'insurance_scheme_id' => $scheme->id,
        'effective_from' => now()->subMonth(),
        'effective_to' => null,
    ]);
    InsuranceCoverageRule::factory()->create([
        'insurance_scheme_id' => $scheme->id,
        'effective_from' => now()->subMonth(),
        'effective_to' => null,
    ]);

    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 10000]);

    expect(fn () => app(InsuranceBillingService::class)->calculateForCharge($charge, $coverage))
        ->toThrow(ValidationException::class);
});
