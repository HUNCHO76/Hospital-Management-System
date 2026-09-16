<?php

use App\Models\BillingAdjustment;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Staff;
use App\Models\User;

/**
 * Phase 7, Step 58 — authorization for financial corrections: reversals,
 * refunds, and adjustments. Mirrors the pattern established by
 * PaymentAuthorizationTest exactly.
 */
test('a guest is redirected to login for every correction route', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 10000]);
    $payment = paymentFor($invoice, ['amount' => 5000]);
    $reversal = reversalFor($payment);
    $payment2 = paymentFor($invoice, ['amount' => 5000]);
    $refund = refundFor($payment2, ['amount' => 1000]);
    $adjustment = adjustmentFor($invoice);

    $this->post(route('billing.payments.reversal.store', $payment))->assertRedirect(route('login'));
    $this->get(route('billing.reversals.show', $reversal))->assertRedirect(route('login'));
    $this->post(route('billing.payments.refunds.store', $payment2))->assertRedirect(route('login'));
    $this->get(route('billing.refunds.show', $refund))->assertRedirect(route('login'));
    $this->get(route('billing.adjustments.index'))->assertRedirect(route('login'));
    $this->post(route('billing.invoices.adjustments.store', $invoice))->assertRedirect(route('login'));
    $this->get(route('billing.adjustments.show', $adjustment))->assertRedirect(route('login'));
});

test('a permission-less user cannot access any correction route', function () {
    seedRolesAndPermissions();
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 10000]);
    $payment = paymentFor($invoice, ['amount' => 5000]);
    $reversal = reversalFor($payment);
    $payment2 = paymentFor($invoice, ['amount' => 5000]);
    $refund = refundFor($payment2, ['amount' => 1000]);
    $adjustment = adjustmentFor($invoice);
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('billing.payments.reversal.store', $payment), ['reason' => 'x'])->assertForbidden();
    $this->actingAs($user)->get(route('billing.reversals.show', $reversal))->assertForbidden();
    $this->actingAs($user)->post(route('billing.payments.refunds.store', $payment2), ['amount' => '500', 'refund_method' => 'cash', 'reason' => 'x'])->assertForbidden();
    $this->actingAs($user)->get(route('billing.refunds.show', $refund))->assertForbidden();
    $this->actingAs($user)->get(route('billing.adjustments.index'))->assertForbidden();
    $this->actingAs($user)->post(route('billing.invoices.adjustments.store', $invoice), ['type' => 'write_off', 'direction' => 'credit', 'amount' => '500', 'reason' => 'x'])->assertForbidden();
    $this->actingAs($user)->get(route('billing.adjustments.show', $adjustment))->assertForbidden();
});

test('a doctor holds no billing-reversals, billing-refunds, or billing-adjustments permission', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 10000]);
    $payment = paymentFor($invoice, ['amount' => 5000]);
    $reversal = reversalFor($payment);
    $payment2 = paymentFor($invoice, ['amount' => 5000]);
    $refund = refundFor($payment2, ['amount' => 1000]);
    $adjustment = adjustmentFor($invoice);
    $doctor = userWithRole('doctor');

    $this->actingAs($doctor)->get(route('billing.reversals.show', $reversal))->assertForbidden();
    $this->actingAs($doctor)->get(route('billing.refunds.show', $refund))->assertForbidden();
    $this->actingAs($doctor)->get(route('billing.adjustments.index'))->assertForbidden();
    $this->actingAs($doctor)->get(route('billing.adjustments.show', $adjustment))->assertForbidden();
});

test('an accountant can access reversal, refund, and adjustment pages end to end over HTTP', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 100000]);
    $payment = paymentFor($invoice, ['amount' => 50000]);
    $accountant = userWithRole('accountant');

    // Reversal store
    $response = $this->actingAs($accountant)->post(route('billing.payments.reversal.store', $payment), [
        'reason' => 'Duplicate',
        'notes' => null,
    ]);
    $response->assertRedirect(route('billing.payments.show', $payment));

    $reversal = $payment->fresh()->reversal;
    $this->actingAs($accountant)->get(route('billing.reversals.show', $reversal))->assertOk();

    // Refund store (new payment)
    $payment2 = paymentFor($invoice, ['amount' => 50000]);
    $response = $this->actingAs($accountant)->post(route('billing.payments.refunds.store', $payment2), [
        'amount' => '10000',
        'refund_method' => 'cash',
        'refund_reference' => null,
        'reason' => 'Overpayment',
    ]);
    $response->assertRedirect(route('billing.payments.show', $payment2));

    $refund = $payment2->fresh()->refunds->first();
    $this->actingAs($accountant)->get(route('billing.refunds.show', $refund))->assertOk();

    // Adjustment store
    $invoice2 = postedInvoiceFor($hospital, $patient, ['unit_price' => 50000]);
    $response = $this->actingAs($accountant)->post(route('billing.invoices.adjustments.store', $invoice2), [
        'type' => BillingAdjustment::TYPE_APPROVED_DISCOUNT,
        'direction' => BillingAdjustment::DIRECTION_CREDIT,
        'amount' => '5000',
        'reason' => 'Staff discount',
    ]);
    $response->assertRedirect(route('billing.invoices.show', $invoice2));

    $adjustment = $invoice2->fresh()->adjustments->first();
    $this->actingAs($accountant)->get(route('billing.adjustments.show', $adjustment))->assertOk();
    $this->actingAs($accountant)->get(route('billing.adjustments.index'))->assertOk();
});

test('a hospital-admin is restricted to their own hospital for corrections', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $patient = Patient::factory()->create();

    $invoiceA = postedInvoiceFor($hospitalA, $patient, ['unit_price' => 50000]);
    $paymentA = paymentFor($invoiceA, ['amount' => 50000]);

    $invoiceB = postedInvoiceFor($hospitalB, $patient, ['unit_price' => 50000]);
    $paymentB = paymentFor($invoiceB, ['amount' => 50000]);

    $staffB = Staff::factory()->create(['hospital_id' => $hospitalB->id]);
    $adminB = userWithRole('hospital-admin');
    $adminB->staff()->save($staffB);

    // Admin B cannot reverse a payment from hospital A
    $this->actingAs($adminB)
        ->post(route('billing.payments.reversal.store', $paymentA), ['reason' => 'Test'])
        ->assertForbidden();

    // Admin B can reverse their own hospital's payment
    $this->actingAs($adminB)
        ->post(route('billing.payments.reversal.store', $paymentB), ['reason' => 'Test'])
        ->assertRedirect(route('billing.payments.show', $paymentB));
});
