<?php

use App\Models\ChargeInsuranceAllocation;
use App\Models\Hospital;
use App\Models\Patient;
use App\Services\InsuranceBillingService;
use App\Services\InvoiceService;

/**
 * Mandatory concurrency test (Phase 7, Step 56, spec §43): repeated
 * back-to-back calculation attempts against the same invoice must never
 * create duplicate allocations or conflicting responsibility totals — the
 * database's own UNIQUE constraint on `charge_id`, combined with
 * applyToInvoice()'s own invoice-row lock (serializing concurrent callers
 * the same way every other billing transition in this codebase already
 * does), is what guarantees this. Mirrors InvoiceConcurrencyTest's own
 * "simulate repeated attempts" approach for a single-threaded test runner.
 */
test('several back-to-back calculation attempts against the same invoice never produce duplicate allocations', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $scheme = insuranceSchemeFor();
    coverageRuleFor($scheme, ['coverage_percentage' => 80]);
    primaryCoverageFor($patient, $scheme);

    $accountant = userWithRole('accountant');
    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 10000]);
    $invoice = app(InvoiceService::class)->createDraft($charge->patientAccount, [$charge->id], null, requestAs($accountant));

    for ($i = 0; $i < 5; $i++) {
        app(InsuranceBillingService::class)->applyToInvoice($invoice->fresh(), requestAs($accountant));
    }

    expect(ChargeInsuranceAllocation::query()->where('charge_id', $charge->id)->count())->toBe(1);

    $allocation = ChargeInsuranceAllocation::query()->where('charge_id', $charge->id)->sole();
    expect((float) $allocation->covered_amount)->toBe(8000.0);
    expect((float) $allocation->patient_responsibility)->toBe(2000.0);
});

test('several back-to-back calculation attempts across multiple charges on the same invoice stay consistent', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $scheme = insuranceSchemeFor();
    coverageRuleFor($scheme, ['coverage_percentage' => 50]);
    primaryCoverageFor($patient, $scheme);

    $accountant = userWithRole('accountant');
    $chargeA = postedChargeFor($hospital, $patient, ['unit_price' => 4000]);
    $chargeB = postedChargeFor($hospital, $patient, ['unit_price' => 6000]);
    $invoice = app(InvoiceService::class)->createDraft($chargeA->patientAccount, [$chargeA->id, $chargeB->id], null, requestAs($accountant));

    for ($i = 0; $i < 3; $i++) {
        app(InsuranceBillingService::class)->applyToInvoice($invoice->fresh(), requestAs($accountant));
    }

    expect(ChargeInsuranceAllocation::query()->where('invoice_id', $invoice->id)->count())->toBe(2);

    $total = ChargeInsuranceAllocation::query()->where('invoice_id', $invoice->id)->sum('eligible_amount');
    expect((float) $total)->toBe(10000.0);
});
