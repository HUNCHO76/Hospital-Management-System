<?php

use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Staff;
use App\Models\User;
use App\Services\PaymentService;

/**
 * Phase 7, Step 60 — billing report security: facility isolation prevents
 * hospital-admin from seeing another facility's data; no financial records
 * are mutated from any report endpoint.
 */
test('hospital-admin invoice report is scoped to their own hospital', function () {
    $hospital1 = Hospital::factory()->create();
    $hospital2 = Hospital::factory()->create();
    $patient = Patient::factory()->create();

    $admin = userWithRole('hospital-admin');
    Staff::factory()->create(['user_id' => $admin->id, 'hospital_id' => $hospital1->id]);

    postedInvoiceFor($hospital1, $patient, ['unit_price' => 10000]);
    postedInvoiceFor($hospital2, $patient, ['unit_price' => 20000]);

    $response = $this->actingAs($admin)->get(route('billing.reports.invoices'));
    $response->assertOk();

    // The response prop only contains hospital1 invoices.
    $data = $response->original->getData();
    $invoices = $data['page']['props']['invoices']['data'];

    foreach ($invoices as $inv) {
        expect($inv['hospital']['id'])->toBe($hospital1->id);
    }
});

test('hospital-admin payments report is scoped to their own hospital', function () {
    $hospital1 = Hospital::factory()->create();
    $hospital2 = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $cashier = User::factory()->create();

    $admin = userWithRole('hospital-admin');
    Staff::factory()->create(['user_id' => $admin->id, 'hospital_id' => $hospital1->id]);

    $invoice1 = postedInvoiceFor($hospital1, $patient, ['unit_price' => 20000]);
    app(PaymentService::class)->createPayment([
        'invoice_id' => $invoice1->id,
        'amount' => 20000,
        'payment_method' => Payment::METHOD_CASH,
        'payment_date' => now()->toDateString(),
    ], requestAs($cashier));

    $invoice2 = postedInvoiceFor($hospital2, $patient, ['unit_price' => 10000]);
    app(PaymentService::class)->createPayment([
        'invoice_id' => $invoice2->id,
        'amount' => 10000,
        'payment_method' => Payment::METHOD_CASH,
        'payment_date' => now()->toDateString(),
    ], requestAs($cashier));

    $response = $this->actingAs($admin)->get(route('billing.reports.payments'));
    $response->assertOk();

    $data = $response->original->getData();
    $payments = $data['page']['props']['payments']['data'];

    // All payments in the response should belong to hospital1 only.
    expect(count($payments))->toBe(1);
});

test('report endpoints do not modify any financial records', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $accountant = userWithRole('accountant');

    postedInvoiceFor($hospital, $patient, ['unit_price' => 5000]);

    $invoiceCountBefore = Invoice::count();
    $paymentCountBefore = Payment::count();

    $this->actingAs($accountant)->get(route('billing.reports.invoices'));
    $this->actingAs($accountant)->get(route('billing.reports.payments'));
    $this->actingAs($accountant)->get(route('billing.reports.corrections'));

    expect(Invoice::count())->toBe($invoiceCountBefore);
    expect(Payment::count())->toBe($paymentCountBefore);
});
