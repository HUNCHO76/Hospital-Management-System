<?php

use App\Models\BillingAdjustment;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Staff;

/**
 * Phase 7, Step 58 — security: IDOR, cross-hospital, amount-tampering, and
 * mass-assignment protection for financial corrections. Mirrors
 * PaymentSecurityTest's own pattern exactly.
 */
test('an accountant from hospital B cannot reverse a payment from hospital A (IDOR)', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $patient = Patient::factory()->create();

    $invoiceA = postedInvoiceFor($hospitalA, $patient, ['unit_price' => 50000]);
    $paymentA = paymentFor($invoiceA, ['amount' => 50000]);

    $staffB = Staff::factory()->create(['hospital_id' => $hospitalB->id]);
    $adminB = userWithRole('hospital-admin');
    $adminB->staff()->save($staffB);

    $this->actingAs($adminB)
        ->post(route('billing.payments.reversal.store', $paymentA), ['reason' => 'IDOR attempt'])
        ->assertForbidden();

    expect($paymentA->fresh()->reversal)->toBeNull();
});

test('an accountant from hospital B cannot issue a refund against a payment from hospital A (IDOR)', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $patient = Patient::factory()->create();

    $invoiceA = postedInvoiceFor($hospitalA, $patient, ['unit_price' => 50000]);
    $paymentA = paymentFor($invoiceA, ['amount' => 50000]);

    $staffB = Staff::factory()->create(['hospital_id' => $hospitalB->id]);
    $adminB = userWithRole('hospital-admin');
    $adminB->staff()->save($staffB);

    $this->actingAs($adminB)
        ->post(route('billing.payments.refunds.store', $paymentA), [
            'amount' => '10000',
            'refund_method' => 'cash',
            'reason' => 'IDOR attempt',
        ])
        ->assertForbidden();

    expect($paymentA->fresh()->refunds)->toBeEmpty();
});

test('an accountant from hospital B cannot post an adjustment against an invoice from hospital A (IDOR)', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $patient = Patient::factory()->create();

    $invoiceA = postedInvoiceFor($hospitalA, $patient, ['unit_price' => 50000]);

    $staffB = Staff::factory()->create(['hospital_id' => $hospitalB->id]);
    $adminB = userWithRole('hospital-admin');
    $adminB->staff()->save($staffB);

    $this->actingAs($adminB)
        ->post(route('billing.invoices.adjustments.store', $invoiceA), [
            'type' => BillingAdjustment::TYPE_APPROVED_DISCOUNT,
            'direction' => BillingAdjustment::DIRECTION_CREDIT,
            'amount' => '5000',
            'reason' => 'IDOR attempt',
        ])
        ->assertForbidden();

    expect($invoiceA->fresh()->adjustments)->toBeEmpty();
});

test('refund amount field cannot be tampered to exceed the refundable balance via the HTTP layer', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $payment = paymentFor($invoice, ['amount' => 50000]);

    $accountant = userWithRole('accountant');

    // Attempt to refund more than the payment amount
    $response = $this->actingAs($accountant)->post(route('billing.payments.refunds.store', $payment), [
        'amount' => '999999',
        'refund_method' => 'cash',
        'reason' => 'Tampered amount',
    ]);

    // Should get a validation redirect/error, not a success
    $response->assertRedirect();
    expect($payment->fresh()->refunds)->toBeEmpty();
});

test('adjustment amount field cannot be negative via the HTTP layer', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $accountant = userWithRole('accountant');

    $response = $this->actingAs($accountant)->post(route('billing.invoices.adjustments.store', $invoice), [
        'type' => BillingAdjustment::TYPE_APPROVED_DISCOUNT,
        'direction' => BillingAdjustment::DIRECTION_CREDIT,
        'amount' => '-5000',
        'reason' => 'Negative tamper',
    ]);

    $response->assertRedirect();
    expect($invoice->fresh()->adjustments)->toBeEmpty();
});

test('hospital_id, patient_id, and adjusted_by cannot be mass-assigned via adjustment request (only trusted service values are persisted)', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospitalA, $patient, ['unit_price' => 50000]);
    $accountant = userWithRole('accountant');

    $this->actingAs($accountant)->post(route('billing.invoices.adjustments.store', $invoice), [
        'type' => BillingAdjustment::TYPE_APPROVED_DISCOUNT,
        'direction' => BillingAdjustment::DIRECTION_CREDIT,
        'amount' => '5000',
        'reason' => 'Test',
        'hospital_id' => $hospitalB->id,
        'adjusted_by' => 9999,
        'patient_id' => 9999,
    ]);

    $adjustment = $invoice->fresh()->adjustments->first();
    expect((int) $adjustment->hospital_id)->toBe($hospitalA->id);
    expect((int) $adjustment->patient_id)->toBe($patient->id);
    expect((int) $adjustment->adjusted_by)->not->toBe(9999);
});
