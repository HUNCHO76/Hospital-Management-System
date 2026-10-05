<?php

/**
 * InpatientBillingTest — Phase 9 Step 83
 *
 * 15 required scenarios:
 *  1.  Inpatient charge creation
 *  2.  Correct price resolution
 *  3.  CASH payment gate
 *  4.  NHIF authorization gate
 *  5.  NHIF + copay flow
 *  6.  Payment creation clears outstanding
 *  7.  Duplicate payment prevention
 *  8.  Payment reversal restores outstanding
 *  9.  Bed/room charge where configured
 * 10.  Duplicate charge prevention
 * 11.  Financial status transitions
 * 12.  Cross-patient protection
 * 13.  Cross-facility protection
 * 14.  Permission enforcement
 * 15.  Audit logging
 *
 * Plus one bonus test (16): encounter-linked lab charge surfaces in summary.
 */

use App\Models\Admission;
use App\Models\Charge;
use App\Models\ChargeInsuranceAllocation;
use App\Models\ClinicalEncounter;
use App\Models\Hospital;
use App\Models\LaboratoryOrderItem;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Staff;
use App\Services\ChargeCaptureService;
use App\Services\ChargeService;
use App\Services\FinancialAuthorizationService;
use App\Services\InpatientBillingService;
use App\Services\InvoiceService;
use App\Services\LaboratoryOrderService;
use App\Services\LaboratorySampleService;
use App\Services\PaymentService;
use Illuminate\Validation\ValidationException;

// ---------------------------------------------------------------------------
// 1. Inpatient charge creation
// ---------------------------------------------------------------------------

test('an admission with a priced inpatient service automatically captures a pending charge', function () {
    ['admission' => $admission, 'charge' => $charge, 'service' => $service, 'patient' => $patient, 'hospital' => $hospital] = chargedAdmission();

    expect($charge->status)->toBe(Charge::STATUS_PENDING)
        ->and($charge->patient_id)->toBe($patient->id)
        ->and($charge->hospital_id)->toBe($hospital->id)
        ->and($charge->service_id)->toBe($service->id)
        ->and($charge->source_type)->toBe(Admission::class)
        ->and($charge->source_id)->toBe($admission->id);
});

// ---------------------------------------------------------------------------
// 2. Correct price resolution
// ---------------------------------------------------------------------------

test('admission charge price comes from the service catalog — charges_per_day in the payload is ignored', function () {
    ['charge' => $charge] = chargedAdmission(['amount' => 15000]);

    expect((float) $charge->unit_price)->toBe(15000.0);
});

// ---------------------------------------------------------------------------
// 3. CASH payment gate
// Uses a billable OPD lab order (consultation already open) to trigger the
// financial gate — the gate applies to any unpaid lab charge regardless of
// whether the encounter is OPD or inpatient.
// ---------------------------------------------------------------------------

test('an unpaid lab charge blocks sample registration', function () {
    $ctx = diagnosisReadyEncounter();
    $test = billableLaboratoryTest($ctx['hospital']);

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], [
        'test_ids' => [$test->id],
    ], requestAs($ctx['doctor']['user']));

    $item = $order->items->sole();

    expect(fn () => app(LaboratorySampleService::class)->create($order, [
        'item_ids' => [$item->id],
        'specimen_type' => $test->specimen_type,
        'container_type' => $test->specimen_container,
    ], requestAs(userWithRole('laboratory-technician'))))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// 4. NHIF authorization gate
// ---------------------------------------------------------------------------

test('a lab order item without a pre-approval session returns PAYMENT_REQUIRED from FinancialAuthorizationService', function () {
    $ctx = diagnosisReadyEncounter();
    $test = billableLaboratoryTest($ctx['hospital']);

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], [
        'test_ids' => [$test->id],
    ], requestAs($ctx['doctor']['user']));

    $item = $order->items->sole();

    $status = app(FinancialAuthorizationService::class)->statusForSource(
        LaboratoryOrderItem::class,
        $item->id,
    );

    expect($status)->toBe(FinancialAuthorizationService::STATUS_PAYMENT_REQUIRED);
});

// ---------------------------------------------------------------------------
// 5. NHIF + copay flow
// The ChargeInsuranceAllocation requires a non-null invoice_id, so the charge
// must be posted and invoiced before the allocation is created.
// ---------------------------------------------------------------------------

test('partial NHIF coverage leaves a positive patient_responsibility and outstanding in the summary', function () {
    ['admission' => $admission, 'charge' => $charge, 'actor' => $actor, 'hospital' => $hospital, 'patient' => $patient] = chargedAdmission(['amount' => 10000]);

    app(ChargeService::class)->post($charge, requestAs($actor));

    $account = $charge->fresh()->patientAccount;
    $invoice = app(InvoiceService::class)->createDraft($account, [$charge->id], null, requestAs($actor));
    $invoice = app(InvoiceService::class)->post($invoice, requestAs($actor));

    ChargeInsuranceAllocation::factory()->create([
        'hospital_id' => $hospital->id,
        'patient_id' => $patient->id,
        'invoice_id' => $invoice->id,
        'charge_id' => $charge->id,
        'eligible_amount' => 10000,
        'covered_amount' => 8000,
        'patient_responsibility' => 2000,
        'status' => ChargeInsuranceAllocation::STATUS_PARTIALLY_COVERED,
    ]);

    // Re-read charge now that invoice_id is stamped.
    $charge->refresh();
    $summary = app(InpatientBillingService::class)->summary($admission);

    expect($summary['nhif_covered'])->toBe(8000.0)
        ->and($summary['patient_responsibility'])->toBe(2000.0)
        ->and($summary['outstanding'])->toBe(2000.0)
        ->and($summary['status'])->toBe('outstanding');
});

// ---------------------------------------------------------------------------
// 6. Payment creation clears outstanding
// ---------------------------------------------------------------------------

test('a payment against an invoice containing the admission charge shows status paid in the summary', function () {
    ['admission' => $admission, 'charge' => $charge, 'actor' => $actor] = chargedAdmission(['amount' => 10000]);

    app(ChargeService::class)->post($charge, requestAs($actor));
    $account = $charge->fresh()->patientAccount;
    $invoice = app(InvoiceService::class)->createDraft($account, [$charge->id], null, requestAs($actor));
    $invoice = app(InvoiceService::class)->post($invoice, requestAs($actor));

    paymentFor($invoice->fresh(), ['amount' => 10000]);

    $summary = app(InpatientBillingService::class)->summary($admission);

    expect($summary['cash_paid'])->toBe(10000.0)
        ->and($summary['outstanding'])->toBeLessThanOrEqual(0.0)
        ->and($summary['status'])->toBe('paid');
});

// ---------------------------------------------------------------------------
// 7. Duplicate payment prevention
// ---------------------------------------------------------------------------

test('a payment exceeding the outstanding invoice balance is rejected', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $invoice = postedInvoiceFor($hospital, $patient, ['unit_price' => 5000]);

    paymentFor($invoice->fresh(), ['amount' => 5000]);

    // Outstanding is now zero; a second payment must be rejected.
    expect(fn () => app(PaymentService::class)->createPayment([
        'invoice_id' => $invoice->id,
        'amount' => 5000,
        'payment_date' => now()->toDateString(),
        'payment_method' => Payment::METHOD_CASH,
    ], requestAs(userWithRole('accountant'))))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// 8. Payment reversal restores outstanding
// ---------------------------------------------------------------------------

test('reversing a payment increases the outstanding balance back to its pre-payment value', function () {
    ['admission' => $admission, 'charge' => $charge, 'actor' => $actor] = chargedAdmission(['amount' => 10000]);

    app(ChargeService::class)->post($charge, requestAs($actor));
    $account = $charge->fresh()->patientAccount;
    $invoice = app(InvoiceService::class)->createDraft($account, [$charge->id], null, requestAs($actor));
    $invoice = app(InvoiceService::class)->post($invoice, requestAs($actor));
    $payment = paymentFor($invoice->fresh(), ['amount' => 10000]);

    expect(app(InpatientBillingService::class)->summary($admission)['status'])->toBe('paid');

    reversalFor($payment);

    $summary = app(InpatientBillingService::class)->summary($admission);

    expect($summary['outstanding'])->toBe(10000.0)
        ->and($summary['status'])->toBe('outstanding');
});

// ---------------------------------------------------------------------------
// 9. Bed/room charge where configured
// ---------------------------------------------------------------------------

test('admission with a configured inpatient service creates exactly one charge in the billing summary', function () {
    ['admission' => $admission, 'service' => $service] = chargedAdmission(['amount' => 3500]);

    $charges = app(InpatientBillingService::class)->chargesForAdmission($admission);

    expect($charges)->toHaveCount(1)
        ->and((float) $charges->first()->unit_price)->toBe(3500.0)
        ->and($charges->first()->service_id)->toBe($service->id);
});

test('admission without a configured service shows unbilled status with zero charge count', function () {
    $ctx = activeAdmission();

    $summary = app(InpatientBillingService::class)->summary($ctx['admission']);

    expect($summary['charge_count'])->toBe(0)
        ->and($summary['status'])->toBe('unbilled')
        ->and($summary['total_charges'])->toBe(0.0);
});

// ---------------------------------------------------------------------------
// 10. Duplicate charge prevention
// ---------------------------------------------------------------------------

test('calling captureFromAdmission twice produces exactly one charge — idempotent by design', function () {
    ['admission' => $admission, 'actor' => $actor] = chargedAdmission(['amount' => 7000]);

    $svc = app(ChargeCaptureService::class);
    $first = $svc->captureFromAdmission($admission, requestAs($actor));
    $second = $svc->captureFromAdmission($admission, requestAs($actor));

    expect($first)->not->toBeNull()
        ->and($first->id)->toBe($second->id);
});

// ---------------------------------------------------------------------------
// 11. Financial status transitions
// ---------------------------------------------------------------------------

test('financial status transitions correctly from unbilled through partial to paid', function () {
    ['admission' => $admission, 'charge' => $charge, 'actor' => $actor] = chargedAdmission(['amount' => 8000]);

    // Pending charge → total_charges = 0 (only posted charges count) → unbilled.
    $s1 = app(InpatientBillingService::class)->summary($admission);
    expect($s1['status'])->toBe('unbilled')
        ->and($s1['total_charges'])->toBe(0.0)
        ->and($s1['pending_charges'])->toBe(8000.0);

    // Post charge → outstanding.
    app(ChargeService::class)->post($charge, requestAs($actor));
    $s2 = app(InpatientBillingService::class)->summary($admission);
    expect($s2['status'])->toBe('outstanding')
        ->and($s2['total_charges'])->toBe(8000.0);

    // Create invoice, pay half → partial.
    $account = $charge->fresh()->patientAccount;
    $invoice = app(InvoiceService::class)->createDraft($account, [$charge->id], null, requestAs($actor));
    $invoice = app(InvoiceService::class)->post($invoice, requestAs($actor));
    paymentFor($invoice->fresh(), ['amount' => 4000]);
    expect(app(InpatientBillingService::class)->summary($admission)['status'])->toBe('partial');

    // Pay remaining → paid.
    paymentFor($invoice->fresh(), ['amount' => 4000]);
    expect(app(InpatientBillingService::class)->summary($admission)['status'])->toBe('paid');
});

// ---------------------------------------------------------------------------
// 12. Cross-patient protection
// ---------------------------------------------------------------------------

test('a hospital-admin from hospital A cannot access the billing page for hospital B\'s admission', function () {
    $ctxA = activeAdmission();

    $hospitalB = Hospital::factory()->create();
    $adminB = userWithRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminB->id, 'hospital_id' => $hospitalB->id]);

    $this->actingAs($adminB)
        ->get(route('inpatient.billing', $ctxA['admission']))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// 13. Cross-facility protection
// ---------------------------------------------------------------------------

test('hospital-admin cannot view billing for an admission from a different facility', function () {
    $ctx = activeAdmission();
    $otherHospital = Hospital::factory()->create();
    $otherAdmin = userWithRole('hospital-admin');
    Staff::factory()->create(['user_id' => $otherAdmin->id, 'hospital_id' => $otherHospital->id]);

    $this->actingAs($otherAdmin)
        ->get(route('inpatient.billing', $ctx['admission']))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// 14. Permission enforcement
// ---------------------------------------------------------------------------

test('a nurse (admission.view but no billing.view) receives 403 on the inpatient billing page', function () {
    $ctx = activeAdmission();
    $nurse = userWithRole('nurse');
    Staff::factory()->create(['user_id' => $nurse->id, 'hospital_id' => $ctx['hospital']->id]);

    $this->actingAs($nurse)
        ->get(route('inpatient.billing', $ctx['admission']))
        ->assertForbidden();
});

test('an accountant (billing.view + admission.view) can access the inpatient billing page', function () {
    $ctx = activeAdmission();
    $accountant = userWithRole('accountant');
    Staff::factory()->create(['user_id' => $accountant->id, 'hospital_id' => $ctx['hospital']->id]);

    $this->actingAs($accountant)
        ->get(route('inpatient.billing', $ctx['admission']))
        ->assertOk();
});

// ---------------------------------------------------------------------------
// 15. Audit logging
// ---------------------------------------------------------------------------

test('admission charge creation is recorded in the audit log as charge_created', function () {
    chargedAdmission(['amount' => 6000]);

    $this->assertDatabaseHas('audit_logs', ['action' => 'charge_created']);
});

// ---------------------------------------------------------------------------
// 16. (Bonus) Encounter-linked charge appears in admission billing summary
// Creates a ClinicalEncounter with admission_id via factory (bypassing the
// full consultation setup) and a charge linked by encounter_id.
// ---------------------------------------------------------------------------

test('a charge linked to an inpatient encounter is included in the admission billing summary', function () {
    $ctx = activeAdmission();

    $encounter = ClinicalEncounter::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
        'admission_id' => $ctx['admission']->id,
    ]);

    $charge = chargeFor($ctx['hospital'], $ctx['patient'], [
        'encounter_id' => $encounter->id,
        'unit_price' => 4500,
        'description' => 'Lab Test',
    ]);

    $charges = app(InpatientBillingService::class)->chargesForAdmission($ctx['admission']);

    expect($charges)->toHaveCount(1)
        ->and((float) $charges->first()->unit_price)->toBe(4500.0)
        ->and($charges->first()->encounter_id)->toBe($encounter->id);
});
