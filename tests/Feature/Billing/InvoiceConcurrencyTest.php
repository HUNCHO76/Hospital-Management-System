<?php

use App\Models\Charge;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Services\InvoiceService;
use App\Services\PatientAccountService;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency tests for invoicing (Phase 7, Step 55, spec §20-21):
 * the same charge must never be finalized onto two invoices, and an invoice
 * must never be posted twice, regardless of how many overlapping attempts
 * occur. Mirrors BillingConcurrencyTest's own "simulate repeated attempts"
 * approach exactly — each attempt re-fetches fresh state and the row-locked
 * eligibility/transition guard rejects every attempt after the first.
 */
test('only one of several back-to-back attempts to add the same charge to two different draft invoices succeeds', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = postedChargeFor($hospital, $patient);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $actor = userWithRole('accountant');
    $service = app(InvoiceService::class);

    $invoices = [];
    for ($i = 0; $i < 5; $i++) {
        $invoices[] = $service->createDraft($account, [], null, requestAs($actor));
    }

    $successes = 0;
    $rejections = 0;

    foreach ($invoices as $invoice) {
        try {
            $service->addCharge($invoice, $charge->fresh(), requestAs($actor));
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
    expect(Charge::query()->whereKey($charge->id)->sole()->invoice_id)->not->toBeNull();
});

test('only one of several back-to-back attempts to post the same invoice succeeds', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = postedChargeFor($hospital, $patient);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $actor = userWithRole('accountant');
    $service = app(InvoiceService::class);
    $invoice = $service->createDraft($account, [$charge->id], null, requestAs($actor));

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->post($invoice->fresh(), requestAs($actor));
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
    expect(Invoice::query()->count())->toBe(1);
});

test('only one of several back-to-back attempts to cancel the same posted invoice succeeds', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = postedChargeFor($hospital, $patient);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $actor = userWithRole('accountant');
    $service = app(InvoiceService::class);
    $invoice = $service->createDraft($account, [$charge->id], null, requestAs($actor));
    $service->post($invoice, requestAs($actor));

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->cancel($invoice->fresh(), 'reason', requestAs($actor));
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
});

test('several invoice numbers generated back-to-back are all unique', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $actor = userWithRole('accountant');
    $numbers = [];

    for ($i = 0; $i < 5; $i++) {
        $invoice = app(InvoiceService::class)->createDraft($account, [], null, requestAs($actor));
        $numbers[] = $invoice->invoice_number;
    }

    expect(count($numbers))->toBe(count(array_unique($numbers)));
});
