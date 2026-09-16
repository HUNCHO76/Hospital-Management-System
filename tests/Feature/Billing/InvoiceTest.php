<?php

use App\Models\Charge;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Services\ChargeService;
use App\Services\InvoiceService;
use App\Services\PatientAccountService;
use Illuminate\Validation\ValidationException;

/**
 * The invoice lifecycle (Phase 7, Step 55, spec §11-13/§20/§31): DRAFT ->
 * POSTED -> CANCELLED, or DRAFT -> VOIDED. A charge is invoiced if and only
 * if its own `invoice_id` is set (spec §31's "one authoritative mechanism")
 * — no separate invoice_items table exists (see the create_invoices_table
 * migration's own docblock).
 */
test('creating a draft invoice with selected charges links them and computes a live total', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $chargeA = postedChargeFor($hospital, $patient, ['unit_price' => 10000]);
    $chargeB = postedChargeFor($hospital, $patient, ['unit_price' => 15000]);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $actor = userWithRole('accountant');

    $invoice = app(InvoiceService::class)->createDraft($account, [$chargeA->id, $chargeB->id], 'Monthly billing', requestAs($actor));

    expect($invoice->status)->toBe(Invoice::STATUS_DRAFT);
    expect($invoice->invoice_number)->toStartWith('INV-'.now()->year.'-');
    expect($invoice->notes)->toBe('Monthly billing');
    expect($chargeA->fresh()->invoice_id)->toBe($invoice->id);
    expect($chargeB->fresh()->invoice_id)->toBe($invoice->id);
    expect(app(InvoiceService::class)->currentTotal($invoice))->toBe(25000.0);
    $this->assertDatabaseHas('audit_logs', ['action' => 'invoice_created']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'charge_added_to_invoice']);
});

test('an empty draft invoice can be created and charges added to it afterward', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = postedChargeFor($hospital, $patient);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $actor = userWithRole('accountant');
    $service = app(InvoiceService::class);

    $invoice = $service->createDraft($account, [], null, requestAs($actor));
    expect($invoice->charges)->toHaveCount(0);

    $service->addCharge($invoice, $charge, requestAs($actor));

    expect($charge->fresh()->invoice_id)->toBe($invoice->id);
});

test('a PENDING (not yet posted) charge is not eligible for invoicing', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = chargeFor($hospital, $patient);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $actor = userWithRole('accountant');

    expect(fn () => app(InvoiceService::class)->createDraft($account, [$charge->id], null, requestAs($actor)))
        ->toThrow(ValidationException::class);
});

test('a voided charge is not eligible for invoicing', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = chargeFor($hospital, $patient);
    $actor = userWithRole('accountant');
    app(ChargeService::class)->void($charge, 'mistake', requestAs($actor));
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs($actor));

    expect(fn () => app(InvoiceService::class)->createDraft($account, [$charge->id], null, requestAs($actor)))
        ->toThrow(ValidationException::class);
});

test('a charge already linked to another invoice cannot be added to a second one', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = postedChargeFor($hospital, $patient);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $actor = userWithRole('accountant');
    $service = app(InvoiceService::class);
    $service->createDraft($account, [$charge->id], null, requestAs($actor));

    $second = $service->createDraft($account, [], null, requestAs($actor));

    expect(fn () => $service->addCharge($second, $charge->fresh(), requestAs($actor)))->toThrow(ValidationException::class);
});

test('a charge belonging to a different patient account cannot be added to an invoice', function () {
    $hospital = Hospital::factory()->create();
    $chargeA = postedChargeFor($hospital, Patient::factory()->create());
    $chargeB = postedChargeFor($hospital, Patient::factory()->create());
    $accountA = PatientAccount::query()->whereKey($chargeA->patient_account_id)->sole();
    $actor = userWithRole('accountant');

    expect(fn () => app(InvoiceService::class)->createDraft($accountA, [$chargeB->id], null, requestAs($actor)))
        ->toThrow(ValidationException::class);
});

test('removing a charge from a draft invoice releases it for a different invoice', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = postedChargeFor($hospital, $patient);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $actor = userWithRole('accountant');
    $service = app(InvoiceService::class);
    $invoice = $service->createDraft($account, [$charge->id], null, requestAs($actor));

    $service->removeChargeBeforePosting($invoice, $charge->fresh(), requestAs($actor));

    expect($charge->fresh()->invoice_id)->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'charge_removed_from_invoice']);
});

test('charges cannot be added to or removed from a posted invoice', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $chargeA = postedChargeFor($hospital, $patient);
    $chargeB = postedChargeFor($hospital, $patient);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $actor = userWithRole('accountant');
    $service = app(InvoiceService::class);
    $invoice = $service->createDraft($account, [$chargeA->id], null, requestAs($actor));
    $posted = $service->post($invoice, requestAs($actor));

    expect(fn () => $service->addCharge($posted, $chargeB->fresh(), requestAs($actor)))->toThrow(ValidationException::class);
    expect(fn () => $service->removeChargeBeforePosting($posted, $chargeA->fresh(), requestAs($actor)))->toThrow(ValidationException::class);
});

test('posting an invoice freezes the subtotal and total from its linked charges', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $chargeA = postedChargeFor($hospital, $patient, ['unit_price' => 10000]);
    $chargeB = postedChargeFor($hospital, $patient, ['unit_price' => 7500]);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $actor = userWithRole('accountant');
    $service = app(InvoiceService::class);
    $invoice = $service->createDraft($account, [$chargeA->id, $chargeB->id], null, requestAs($actor));

    $posted = $service->post($invoice, requestAs($actor));

    expect($posted->status)->toBe(Invoice::STATUS_POSTED);
    expect((float) $posted->subtotal)->toBe(17500.0);
    expect((float) $posted->total_amount)->toBe(17500.0);
    expect($posted->posted_at)->not->toBeNull();
    expect($posted->invoice_date)->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'invoice_posted']);
});

test('an empty draft invoice cannot be posted', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $actor = userWithRole('accountant');
    $invoice = app(InvoiceService::class)->createDraft($account, [], null, requestAs($actor));

    expect(fn () => app(InvoiceService::class)->post($invoice, requestAs($actor)))->toThrow(ValidationException::class);
});

test('an invoice cannot be posted twice', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = postedChargeFor($hospital, $patient);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $actor = userWithRole('accountant');
    $service = app(InvoiceService::class);
    $invoice = $service->createDraft($account, [$charge->id], null, requestAs($actor));
    $service->post($invoice, requestAs($actor));

    expect(fn () => $service->post($invoice->fresh(), requestAs($actor)))->toThrow(ValidationException::class);
});

test('a charge that became ineligible after being added blocks posting until removed', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = postedChargeFor($hospital, $patient);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $actor = userWithRole('accountant');
    $invoiceService = app(InvoiceService::class);
    $invoice = $invoiceService->createDraft($account, [$charge->id], null, requestAs($actor));

    app(ChargeService::class)->cancel($charge->fresh(), 'reversed', requestAs($actor));

    expect(fn () => $invoiceService->post($invoice->fresh(), requestAs($actor)))->toThrow(ValidationException::class);
});

test('voiding a draft invoice releases its charges and never counts as a financial document', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = postedChargeFor($hospital, $patient);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $actor = userWithRole('accountant');
    $service = app(InvoiceService::class);
    $invoice = $service->createDraft($account, [$charge->id], null, requestAs($actor));

    expect(fn () => $service->void($invoice, '', requestAs($actor)))->toThrow(ValidationException::class);

    $voided = $service->void($invoice->fresh(), 'Created in error', requestAs($actor));

    expect($voided->status)->toBe(Invoice::STATUS_VOIDED);
    expect($voided->cancellation_reason)->toBe('Created in error');
    expect($charge->fresh()->invoice_id)->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'invoice_voided']);
});

test('a posted invoice cannot be voided — only cancelled', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = postedChargeFor($hospital, $patient);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $actor = userWithRole('accountant');
    $service = app(InvoiceService::class);
    $invoice = $service->createDraft($account, [$charge->id], null, requestAs($actor));
    $service->post($invoice, requestAs($actor));

    expect(fn () => $service->void($invoice->fresh(), 'reason', requestAs($actor)))->toThrow(ValidationException::class);
});

test('cancelling a posted invoice releases its charges for a corrected invoice, preserving the original record', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 12000]);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $actor = userWithRole('accountant');
    $service = app(InvoiceService::class);
    $invoice = $service->createDraft($account, [$charge->id], null, requestAs($actor));
    $service->post($invoice, requestAs($actor));

    $cancelled = $service->cancel($invoice->fresh(), 'Wrong patient', requestAs($actor));

    expect($cancelled->status)->toBe(Invoice::STATUS_CANCELLED);
    expect((float) $cancelled->total_amount)->toBe(12000.0);
    expect($charge->fresh()->invoice_id)->toBeNull();
    expect($charge->fresh()->status)->toBe(Charge::STATUS_POSTED);
    $this->assertDatabaseHas('audit_logs', ['action' => 'invoice_cancelled']);

    // The released charge can now be included on a corrected invoice.
    $corrected = $service->createDraft($account, [$charge->fresh()->id], 'Correction', requestAs($actor));
    expect($charge->fresh()->invoice_id)->toBe($corrected->id);
});

test('a draft invoice cannot be cancelled — only voided', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $actor = userWithRole('accountant');
    $invoice = app(InvoiceService::class)->createDraft($account, [], null, requestAs($actor));

    expect(fn () => app(InvoiceService::class)->cancel($invoice, 'reason', requestAs($actor)))->toThrow(ValidationException::class);
});

test('voided and cancelled invoices are terminal — no further transition is possible', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $actor = userWithRole('accountant');
    $service = app(InvoiceService::class);

    $voided = $service->void($service->createDraft($account, [], null, requestAs($actor)), 'x', requestAs($actor));
    expect(fn () => $service->post($voided->fresh(), requestAs($actor)))->toThrow(ValidationException::class);

    $charge = postedChargeFor($hospital, $patient);
    $invoice = $service->createDraft($account, [$charge->id], null, requestAs($actor));
    $posted = $service->post($invoice, requestAs($actor));
    $cancelled = $service->cancel($posted->fresh(), 'x', requestAs($actor));
    expect(fn () => $service->cancel($cancelled->fresh(), 'y', requestAs($actor)))->toThrow(ValidationException::class);
});
