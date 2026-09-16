<?php

use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Services\InvoiceService;
use App\Services\PatientAccountService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * Database integrity for the invoice module (Phase 7, Step 55, spec
 * §39/§41), mirroring BillingDatabaseIntegrityTest's own style.
 */
test('the invoices table exists with its expected columns and charges gained an invoice_id column', function () {
    expect(Schema::hasTable('invoices'))->toBeTrue();

    expect(Schema::hasColumns('invoices', [
        'invoice_number', 'hospital_id', 'patient_id', 'patient_account_id', 'status', 'invoice_date',
        'subtotal', 'total_amount', 'currency', 'notes', 'posted_at', 'created_by', 'updated_by',
        'cancelled_at', 'cancelled_by', 'cancellation_reason',
    ]))->toBeTrue();

    expect(Schema::hasColumn('charges', 'invoice_id'))->toBeTrue();
});

test('invoice numbers are globally unique', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $account = app(PatientAccountService::class)->ensureAccountExists($hospital->id, $patient->id, requestAs(userWithRole('accountant')));
    $invoice = app(InvoiceService::class)->createDraft($account, [], null, requestAs(userWithRole('accountant')));

    expect(fn () => Invoice::factory()->create(['invoice_number' => $invoice->invoice_number]))
        ->toThrow(QueryException::class);
});

test('an invoice referencing a hospital/patient/account that does not exist is rejected at the database level', function () {
    expect(fn () => Invoice::factory()->create(['hospital_id' => 999999]))
        ->toThrow(QueryException::class);

    expect(fn () => Invoice::factory()->create(['patient_id' => 999999]))
        ->toThrow(QueryException::class);

    expect(fn () => Invoice::factory()->create(['patient_account_id' => 999999]))
        ->toThrow(QueryException::class);
});

test('a charge referencing a non-existent invoice is rejected at the database level', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $charge = postedChargeFor($hospital, $patient);

    expect(fn () => $charge->update(['invoice_id' => 999999]))->toThrow(QueryException::class);
});
