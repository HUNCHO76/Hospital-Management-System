<?php

use App\Models\ChargeInsuranceAllocation;
use App\Models\Hospital;
use App\Models\Patient;
use App\Services\ChargeService;
use App\Services\InsuranceBillingService;
use App\Services\InvoiceService;
use Illuminate\Validation\ValidationException;

/**
 * Phase 7, Step 56 — InsuranceBillingService::applyToInvoice(), the
 * transactional entry point: DRAFT-only, requires eligible coverage and at
 * least one charge, recalculation while still DRAFT, and duplicate
 * allocation prevention (spec §27/§28/§29/§42/§43).
 */
test('applying insurance to a draft invoice creates one allocation per charge', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $scheme = insuranceSchemeFor();
    coverageRuleFor($scheme, ['coverage_percentage' => 80]);
    primaryCoverageFor($patient, $scheme);

    $chargeA = postedChargeFor($hospital, $patient, ['unit_price' => 10000]);
    $chargeB = postedChargeFor($hospital, $patient, ['unit_price' => 5000]);
    $actor = userWithRole('accountant');
    $account = $chargeA->patientAccount;
    $invoice = app(InvoiceService::class)->createDraft($account, [$chargeA->id, $chargeB->id], null, requestAs($actor));

    $allocations = app(InsuranceBillingService::class)->applyToInvoice($invoice, requestAs($actor));

    expect($allocations)->toHaveCount(2);
    expect(ChargeInsuranceAllocation::query()->where('invoice_id', $invoice->id)->count())->toBe(2);
    expect((float) $allocations->firstWhere('charge_id', $chargeA->id)->covered_amount)->toBe(8000.0);
    expect((float) $allocations->firstWhere('charge_id', $chargeB->id)->covered_amount)->toBe(4000.0);
});

test('applying insurance to an invoice with no charges is rejected', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $scheme = insuranceSchemeFor();
    primaryCoverageFor($patient, $scheme);

    $actor = userWithRole('accountant');
    $charge = postedChargeFor($hospital, $patient);
    $account = $charge->patientAccount;
    $invoice = app(InvoiceService::class)->createDraft($account, [], null, requestAs($actor));

    expect(fn () => app(InsuranceBillingService::class)->applyToInvoice($invoice, requestAs($actor)))
        ->toThrow(ValidationException::class);
});

test('applying insurance to an invoice for a patient with no primary coverage is rejected', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $actor = userWithRole('accountant');

    $charge = postedChargeFor($hospital, $patient);
    $account = $charge->patientAccount;
    $invoice = app(InvoiceService::class)->createDraft($account, [$charge->id], null, requestAs($actor));

    expect(fn () => app(InsuranceBillingService::class)->applyToInvoice($invoice, requestAs($actor)))
        ->toThrow(ValidationException::class);

    expect(ChargeInsuranceAllocation::query()->count())->toBe(0);
});

test('calculation can only be applied while the invoice is still draft', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $scheme = insuranceSchemeFor();
    coverageRuleFor($scheme, ['coverage_percentage' => 80]);
    primaryCoverageFor($patient, $scheme);

    $actor = userWithRole('accountant');
    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 10000]);
    $account = $charge->patientAccount;
    $invoice = app(InvoiceService::class)->createDraft($account, [$charge->id], null, requestAs($actor));

    app(InvoiceService::class)->post($invoice, requestAs($actor));

    expect(fn () => app(InsuranceBillingService::class)->applyToInvoice($invoice->fresh(), requestAs($actor)))
        ->toThrow(ValidationException::class);
});

test('recalculating a still-draft invoice updates the same allocation rows rather than creating duplicates', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $scheme = insuranceSchemeFor();
    coverageRuleFor($scheme, ['coverage_percentage' => 80]);
    primaryCoverageFor($patient, $scheme);

    $actor = userWithRole('accountant');
    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 10000]);
    $account = $charge->patientAccount;
    $invoice = app(InvoiceService::class)->createDraft($account, [$charge->id], null, requestAs($actor));

    $first = app(InsuranceBillingService::class)->applyToInvoice($invoice, requestAs($actor))->first();
    expect((float) $first->covered_amount)->toBe(8000.0);

    // The coverage rule changes (a new, more generous version supersedes the old one).
    coverageRuleFor($scheme, ['coverage_percentage' => 95, 'effective_from' => now()->toDateString()]);

    $second = app(InsuranceBillingService::class)->applyToInvoice($invoice->fresh(), requestAs($actor))->first();

    expect($second->id)->toBe($first->id);
    expect((float) $second->fresh()->covered_amount)->toBe(9500.0);
    expect(ChargeInsuranceAllocation::query()->where('charge_id', $charge->id)->count())->toBe(1);
});

test('a finalized (posted) invoice\'s allocation is never silently rewritten by a later coverage rule change', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $scheme = insuranceSchemeFor();
    coverageRuleFor($scheme, ['coverage_percentage' => 80]);
    primaryCoverageFor($patient, $scheme);

    $actor = userWithRole('accountant');
    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 10000]);
    $account = $charge->patientAccount;
    $invoice = app(InvoiceService::class)->createDraft($account, [$charge->id], null, requestAs($actor));

    app(InsuranceBillingService::class)->applyToInvoice($invoice, requestAs($actor));
    app(InvoiceService::class)->post($invoice->fresh(), requestAs($actor));

    coverageRuleFor($scheme, ['coverage_percentage' => 10, 'effective_from' => now()->toDateString()]);

    $allocation = ChargeInsuranceAllocation::query()->where('charge_id', $charge->id)->sole();
    expect((float) $allocation->covered_amount)->toBe(8000.0);
});

test('the eligible amount always equals the charge\'s own already-posted net amount, never a client-supplied figure', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $scheme = insuranceSchemeFor();
    coverageRuleFor($scheme, ['coverage_percentage' => 50]);
    primaryCoverageFor($patient, $scheme);

    $actor = userWithRole('accountant');
    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 10000, 'discount_amount' => 1000]);
    $account = $charge->patientAccount;
    $invoice = app(InvoiceService::class)->createDraft($account, [$charge->id], null, requestAs($actor));

    $allocation = app(InsuranceBillingService::class)->applyToInvoice($invoice, requestAs($actor))->first();

    expect((float) $allocation->eligible_amount)->toBe((float) $charge->fresh()->net_amount);
    expect((float) $allocation->eligible_amount)->toBe(9000.0);
});

test('a charge that becomes ineligible after being added is still calculated against, since post() would already reject it separately', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $scheme = insuranceSchemeFor();
    coverageRuleFor($scheme, ['coverage_percentage' => 50]);
    primaryCoverageFor($patient, $scheme);

    $actor = userWithRole('accountant');
    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 10000]);
    $account = $charge->patientAccount;
    $invoice = app(InvoiceService::class)->createDraft($account, [$charge->id], null, requestAs($actor));

    app(ChargeService::class)->cancel($charge->fresh(), 'billing correction', requestAs($actor));

    // Insurance calculation itself does not gate on charge status the way
    // InvoiceService::post() does — it reflects whatever is currently
    // linked; post() remains the single authority that ultimately refuses
    // to finalize an invoice with an ineligible charge.
    $allocations = app(InsuranceBillingService::class)->applyToInvoice($invoice->fresh(), requestAs($actor));
    expect($allocations)->toHaveCount(1);

    expect(fn () => app(InvoiceService::class)->post($invoice->fresh(), requestAs($actor)))
        ->toThrow(ValidationException::class);
});
