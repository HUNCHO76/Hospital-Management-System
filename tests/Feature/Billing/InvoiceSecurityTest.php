<?php

use App\Models\Charge;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Services\InvoiceService;
use App\Services\PatientAccountService;

/**
 * Security-focused validation for the invoice module (Phase 7, Step 55,
 * spec §44): mass assignment, financial manipulation, and duplicate
 * invoicing are all rejected server-side, mirroring BillingSecurityTest's
 * own shape.
 */
test('a client-supplied status, subtotal, total_amount, or invoice_number is never mass-assignable through the store-invoice request', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $accountant = userWithRole('accountant');
    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 5000]);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs($accountant));

    $this->actingAs($accountant)->post(route('billing.invoices.store'), [
        'patient_account_id' => $account->id,
        'charge_ids' => [$charge->id],
        // Attempted injection — none of these are accepted fields.
        'status' => 'posted',
        'subtotal' => 999999,
        'total_amount' => 0,
        'invoice_number' => 'INV-2026-999999',
        'hospital_id' => Hospital::factory()->create()->id,
    ]);

    $invoice = Invoice::query()->where('patient_account_id', $account->id)->sole();
    expect($invoice->status)->toBe(Invoice::STATUS_DRAFT);
    expect($invoice->subtotal)->toBeNull();
    expect($invoice->hospital_id)->toBe($hospital->id);
    expect($invoice->invoice_number)->not->toBe('INV-2026-999999');
});

test('a client-supplied status or total is never mass-assignable through post/void/cancel', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $accountant = userWithRole('accountant');
    $charge = postedChargeFor($hospital, $patient, ['unit_price' => 5000]);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs($accountant));
    $invoice = app(InvoiceService::class)->createDraft($account, [$charge->id], null, requestAs($accountant));

    $this->actingAs($accountant)->post(route('billing.invoices.post', $invoice), [
        'subtotal' => 1,
        'total_amount' => 1,
        'status' => 'cancelled',
    ]);

    $posted = $invoice->fresh();
    expect($posted->status)->toBe(Invoice::STATUS_POSTED);
    expect((float) $posted->total_amount)->toBe(5000.0);
});

test('a charge cannot be included in two invoices even when submitted twice over HTTP', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $accountant = userWithRole('accountant');
    $charge = postedChargeFor($hospital, $patient);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs($accountant));

    $this->actingAs($accountant)->post(route('billing.invoices.store'), [
        'patient_account_id' => $account->id,
        'charge_ids' => [$charge->id],
    ])->assertRedirect();

    $firstInvoice = Invoice::query()->where('patient_account_id', $account->id)->sole();

    // The second attempt fails atomically — no orphaned empty invoice is
    // left behind, spec §40's "no partial" principle applied to creation.
    $this->actingAs($accountant)->post(route('billing.invoices.store'), [
        'patient_account_id' => $account->id,
        'charge_ids' => [$charge->id],
    ])->assertSessionHasErrors('charge_id');

    expect(Invoice::query()->where('patient_account_id', $account->id)->count())->toBe(1);
    expect(Charge::query()->whereKey($charge->id)->sole()->invoice_id)->toBe($firstInvoice->id);
});

test('an invalid status transition is rejected rather than silently accepted', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $accountant = userWithRole('accountant');
    $charge = postedChargeFor($hospital, $patient);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs($accountant));
    $invoice = app(InvoiceService::class)->createDraft($account, [$charge->id], null, requestAs($accountant));

    $this->actingAs($accountant)->post(route('billing.invoices.cancel', $invoice), ['reason' => 'x'])
        ->assertSessionHasErrors('status');

    expect($invoice->fresh()->status)->toBe(Invoice::STATUS_DRAFT);
});

test('reasons and notes containing markup are stored as plain text, never interpreted as HTML server-side', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $accountant = userWithRole('accountant');
    $charge = postedChargeFor($hospital, $patient);
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs($accountant));
    $payload = '<script>alert(1)</script>';

    $invoice = app(InvoiceService::class)->createDraft($account, [$charge->id], $payload, requestAs($accountant));
    expect($invoice->notes)->toBe($payload);

    $this->actingAs($accountant)->post(route('billing.invoices.void', $invoice), ['reason' => $payload]);

    $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'cancellation_reason' => $payload]);
});
