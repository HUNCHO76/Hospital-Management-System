<?php

use App\Models\Admission;
use App\Models\Charge;
use App\Models\ClinicalEncounter;
use App\Models\Invoice;
use App\Models\NhifPreApprovalSession;
use App\Models\NhifPreApprovalSessionItem;
use App\Models\NhifSchemeService;
use App\Models\NhifService;
use App\Models\PatientInsurance;
use App\Models\ProcedureOrder;
use App\Models\Service;
use App\Models\User;
use App\Services\ProcedureService;
use Illuminate\Validation\ValidationException;

function billableProcedureService($hospital, array $serviceOverrides = [], array $priceOverrides = []): Service
{
    $service = serviceFor(array_merge([
        'code' => 'PROC-' . strtoupper(uniqid()),
        'name' => 'Wound Dressing Routine',
        'category' => 'procedure',
        'is_billable' => true,
    ], $serviceOverrides));

    priceFor($service, $hospital, array_merge([
        'amount' => 15000,
    ], $priceOverrides));

    return $service;
}

test('1. doctor can successfully order a clinical procedure for cash patient', function () {
    $ctx = diagnosisReadyEncounter();
    $service = billableProcedureService($ctx['hospital']);

    $response = $this->actingAs($ctx['doctor']['user'])
        ->post(route('clinical-encounters.procedure-orders.store', $ctx['encounter']), [
            'service_id' => $service->id,
            'clinical_indication' => 'Deep abrasion dressing required',
            'priority' => ProcedureOrder::PRIORITY_ROUTINE,
            'quantity' => 1,
            'notes' => 'Perform under aseptic conditions',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $this->assertDatabaseHas('procedure_orders', [
        'clinical_encounter_id' => $ctx['encounter']->id,
        'patient_id' => $ctx['patient']->id,
        'prescribed_by' => $ctx['doctor']['user']->id,
        'service_id' => $service->id,
        'procedure_name' => $service->name,
        'payment_mode' => 'cash',
        'billing_status' => ProcedureOrder::BILLING_STATUS_PENDING_PAYMENT,
        'status' => ProcedureOrder::STATUS_ORDERED,
    ]);
});

test('2. procedure unavailable in the catalogue is rejected', function () {
    $ctx = diagnosisReadyEncounter();

    $response = $this->actingAs($ctx['doctor']['user'])
        ->postJson(route('clinical-encounters.procedure-orders.store', $ctx['encounter']), [
            'service_id' => 9999999, // non-existent
            'clinical_indication' => 'Test indication',
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['service_id']);
});

test('3. NHIF-covered procedure requiring pre-authorization sets pending_authorization', function () {
    $ctx = diagnosisReadyEncounter();

    // Create NHIF catalog item that requires authorization
    $nhifService = NhifService::create([
        'nhif_item_code' => 'PR001',
        'nhif_item_name' => 'Endoscopic Biopsy',
        'nhif_item_type_id' => 4, // Procedure
        'is_restricted' => true,
        'is_active' => true,
    ]);

    $service = billableProcedureService($ctx['hospital'], [
        'code' => 'PR001',
        'name' => 'Endoscopic Biopsy',
    ]);

    // Active NHIF insurance
    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_scheme_id' => 101,
        'nhif_authorization_no' => '201627864999',
        'card_number' => '9876543210',
        'nhif_facility_code' => $ctx['hospital']->code,
        'verified_at' => now(),
    ]);

    // Map in nhif_scheme_services as covered
    NhifSchemeService::create([
        'scheme_id' => 101,
        'item_code' => 'PR001',
        'item_name' => 'Endoscopic Biopsy',
        'item_type_id' => 4,
        'unit_price' => 45000,
        'is_active' => true,
    ]);

    $response = $this->actingAs($ctx['doctor']['user'])
        ->post(route('clinical-encounters.procedure-orders.store', $ctx['encounter']), [
            'service_id' => $service->id,
            'clinical_indication' => 'Gastric ulcer investigation',
            'priority' => ProcedureOrder::PRIORITY_URGENT,
        ]);

    $response->assertRedirect();

    $order = ProcedureOrder::where('clinical_encounter_id', $ctx['encounter']->id)->first();
    expect($order)->not->toBeNull()
        ->and($order->payment_mode)->toBe('nhif')
        ->and($order->billing_status)->toBe(ProcedureOrder::BILLING_STATUS_PENDING_AUTHORIZATION)
        ->and($order->isPendingAuthorization())->toBeTrue();
});

test('4. pending or rejected NHIF authorization blocks unauthorized execution', function () {
    $ctx = diagnosisReadyEncounter();
    $service = billableProcedureService($ctx['hospital']);

    $order = ProcedureOrder::create([
        'clinical_encounter_id' => $ctx['encounter']->id,
        'patient_id' => $ctx['patient']->id,
        'prescribed_by' => $ctx['doctor']['user']->id,
        'service_id' => $service->id,
        'procedure_name' => $service->name,
        'procedure_type' => ProcedureOrder::TYPE_BEDSIDE,
        'payment_mode' => 'nhif',
        'billing_status' => ProcedureOrder::BILLING_STATUS_PENDING_AUTHORIZATION,
        'nhif_approval_status' => ProcedureOrder::NHIF_APPROVAL_PENDING,
        'status' => ProcedureOrder::STATUS_ORDERED,
        'quantity' => 1,
        'unit_price' => 15000,
        'total_price' => 15000,
        'clinical_indication' => 'Clinical test indication',
        'urgency' => 'routine',
    ]);

    $nurse = userWithRole('nurse');

    // Attempting execution while pending authorization must throw ValidationException
    expect(fn () => app(ProcedureService::class)->recordExecution($order, [
        'performer_id' => $nurse->id,
        'findings' => 'Done',
    ], $nurse))->toThrow(ValidationException::class, 'NHIF pre-authorization has not been approved');

    // Attempting execution when rejected must also throw ValidationException
    $order->update([
        'nhif_approval_status' => ProcedureOrder::NHIF_APPROVAL_REJECTED,
        'nhif_rejection_reason' => 'Item not covered under this tier',
    ]);

    expect(fn () => app(ProcedureService::class)->recordExecution($order, [
        'performer_id' => $nurse->id,
        'findings' => 'Done',
    ], $nurse))->toThrow(ValidationException::class, 'NHIF pre-authorization was rejected');
});

test('5. NHIF-uncovered procedure requires explicit patient consent for self-pay', function () {
    $ctx = diagnosisReadyEncounter();

    $service = billableProcedureService($ctx['hospital'], [
        'code' => 'NON-NHIF-001',
        'name' => 'Laser Cosmetic Procedure',
    ]);

    // Active NHIF insurance
    PatientInsurance::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_scheme_id' => 101,
        'nhif_authorization_no' => '201627864999',
        'card_number' => '9876543210',
        'nhif_facility_code' => $ctx['hospital']->code,
        'verified_at' => now(),
    ]);

    // Not in nhif_scheme_services (uncovered). Submitting without consent must throw
    $responseWithoutConsent = $this->actingAs($ctx['doctor']['user'])
        ->postJson(route('clinical-encounters.procedure-orders.store', $ctx['encounter']), [
            'service_id' => $service->id,
            'clinical_indication' => 'Aesthetic scar revision',
            'consent_obtained' => false,
        ]);

    $responseWithoutConsent->assertStatus(422);
    $responseWithoutConsent->assertJsonValidationErrors(['consent_obtained']);

    // Submitting WITH patient consent follows self-pay policy
    $responseWithConsent = $this->actingAs($ctx['doctor']['user'])
        ->post(route('clinical-encounters.procedure-orders.store', $ctx['encounter']), [
            'service_id' => $service->id,
            'clinical_indication' => 'Aesthetic scar revision',
            'consent_obtained' => true,
        ]);

    $responseWithConsent->assertRedirect();

    $order = ProcedureOrder::where('clinical_encounter_id', $ctx['encounter']->id)->first();
    expect($order)->not->toBeNull()
        ->and($order->payment_mode)->toBe('cash')
        ->and($order->billing_status)->toBe(ProcedureOrder::BILLING_STATUS_PENDING_PAYMENT)
        ->and($order->charge_id)->not->toBeNull();
});

test('6. cash patient receives the correct price and bill/invoice', function () {
    $ctx = diagnosisReadyEncounter();
    $service = billableProcedureService($ctx['hospital'], [], ['amount' => 25000]);

    $this->actingAs($ctx['doctor']['user'])
        ->post(route('clinical-encounters.procedure-orders.store', $ctx['encounter']), [
            'service_id' => $service->id,
            'clinical_indication' => 'Abscess drainage',
            'quantity' => 2,
        ]);

    $order = ProcedureOrder::where('clinical_encounter_id', $ctx['encounter']->id)->sole();

    expect((float) $order->unit_price)->toBe(25000.0)
        ->and((float) $order->total_price)->toBe(50000.0)
        ->and($order->charge_id)->not->toBeNull()
        ->and($order->invoice_id)->not->toBeNull();

    $charge = Charge::find($order->charge_id);
    expect($charge)->not->toBeNull()
        ->and((float) $charge->net_amount)->toBe(50000.0)
        ->and($charge->status)->toBe(Charge::STATUS_POSTED);

    $invoice = Invoice::find($order->invoice_id);
    expect($invoice)->not->toBeNull()
        ->and((float) $invoice->total_amount)->toBe(50000.0);
});

test('7. unpaid procedure is blocked from performance when payment is required', function () {
    $ctx = diagnosisReadyEncounter();
    $service = billableProcedureService($ctx['hospital']);

    $order = app(ProcedureService::class)->createForEncounter($ctx['encounter'], [
        'service_id' => $service->id,
        'clinical_indication' => 'Suture removal',
    ], $ctx['doctor']['user']);

    $nurse = userWithRole('nurse');

    expect(fn () => app(ProcedureService::class)->recordExecution($order, [
        'performer_id' => $nurse->id,
        'findings' => 'Done',
    ], $nurse))->toThrow(ValidationException::class, 'Payment is required');
});

test('8. confirmed payment allows the order to proceed to execution', function () {
    $ctx = diagnosisReadyEncounter();
    $service = billableProcedureService($ctx['hospital']);

    $order = app(ProcedureService::class)->createForEncounter($ctx['encounter'], [
        'service_id' => $service->id,
        'clinical_indication' => 'Wound dressing',
    ], $ctx['doctor']['user']);

    // Simulate cashier confirming payment
    $order->charge->update(['status' => Charge::STATUS_POSTED]);
    $order->update(['billing_status' => ProcedureOrder::BILLING_STATUS_PAID]);

    $performer = userWithRole('nurse');

    $executedOrder = app(ProcedureService::class)->recordExecution($order, [
        'performer_id' => $performer->id,
        'performed_at' => now()->toDateTimeString(),
        'findings' => 'Clean granulating tissue, no signs of infection',
        'procedure_notes' => 'Sterile saline rinse, paraffin gauze applied',
        'complications' => null,
        'results' => 'Satisfactory healing progression',
    ], $performer);

    expect($executedOrder->status)->toBe(ProcedureOrder::STATUS_COMPLETED)
        ->and($executedOrder->performer_id)->toBe($performer->id)
        ->and($executedOrder->performed_at)->not->toBeNull()
        ->and($executedOrder->findings)->toContain('Clean granulating tissue');
});

test('9. authorized emergency exemption allows immediate execution without prior payment', function () {
    $ctx = diagnosisReadyEncounter();
    $service = billableProcedureService($ctx['hospital']);

    $order = app(ProcedureService::class)->createForEncounter($ctx['encounter'], [
        'service_id' => $service->id,
        'clinical_indication' => 'Acute arterial bleed control',
        'priority' => ProcedureOrder::PRIORITY_STAT,
        'emergency_exemption' => true,
        'exemption_reason' => 'Immediate life-threatening hemorrhage',
    ], $ctx['doctor']['user']);

    expect($order->is_emergency_exemption)->toBeTrue()
        ->and($order->isExempted())->toBeTrue()
        ->and($order->billing_status)->toBe(ProcedureOrder::BILLING_STATUS_EXEMPTED)
        ->and($order->canBeExecuted())->toBeTrue();

    $performer = $ctx['doctor']['user'];

    $executedOrder = app(ProcedureService::class)->recordExecution($order, [
        'performer_id' => $performer->id,
        'findings' => 'Hemostasis achieved via pressure bandage and ligation',
    ], $performer);

    expect($executedOrder->status)->toBe(ProcedureOrder::STATUS_COMPLETED);
});

test('10. duplicate submissions and retries do not create duplicate orders or bills', function () {
    $ctx = diagnosisReadyEncounter();
    $service = billableProcedureService($ctx['hospital']);

    $data = [
        'service_id' => $service->id,
        'clinical_indication' => 'Catheterization',
        'priority' => ProcedureOrder::PRIORITY_ROUTINE,
        'quantity' => 1,
    ];

    // Submit first time
    $response1 = $this->actingAs($ctx['doctor']['user'])
        ->post(route('clinical-encounters.procedure-orders.store', $ctx['encounter']), $data);
    $response1->assertRedirect();

    $this->assertDatabaseCount('procedure_orders', 1);
    $this->assertDatabaseCount('charges', 1);

    // Resubmit identical order immediately (e.g. double click or retry)
    $response2 = $this->actingAs($ctx['doctor']['user'])
        ->post(route('clinical-encounters.procedure-orders.store', $ctx['encounter']), $data);
    $response2->assertRedirect();

    // Idempotency: Still exactly 1 order and 1 charge
    $this->assertDatabaseCount('procedure_orders', 1);
    $this->assertDatabaseCount('charges', 1);
});

test('11. unauthorized users are prevented from ordering, executing, or cancelling procedures', function () {
    $ctx = diagnosisReadyEncounter();
    $service = billableProcedureService($ctx['hospital']);

    // A user with no procedure permissions
    $unauthorizedUser = User::factory()->create();

    // Prevented from ordering
    $responseOrder = $this->actingAs($unauthorizedUser)
        ->post(route('clinical-encounters.procedure-orders.store', $ctx['encounter']), [
            'service_id' => $service->id,
            'clinical_indication' => 'Unauthorized order attempt',
        ]);
    $responseOrder->assertStatus(403);

    // Create an order legitimately first
    $order = app(ProcedureService::class)->createForEncounter($ctx['encounter'], [
        'service_id' => $service->id,
        'clinical_indication' => 'Legitimate order',
    ], $ctx['doctor']['user']);

    // Prevented from executing
    $responseExecute = $this->actingAs($unauthorizedUser)
        ->post(route('clinical-encounters.procedure-orders.execute', [$ctx['encounter'], $order]), [
            'findings' => 'Unauthorized execution',
        ]);
    $responseExecute->assertStatus(403);

    // Prevented from cancelling
    $responseCancel = $this->actingAs($unauthorizedUser)
        ->post(route('clinical-encounters.procedure-orders.cancel', [$ctx['encounter'], $order]), [
            'cancellation_reason' => 'Unauthorized cancellation',
        ]);
    $responseCancel->assertStatus(403);
});

test('12. existing consultation, laboratory, radiology, and pharmacy orders remain functional alongside procedures', function () {
    $ctx = diagnosisReadyEncounter();

    // 1. Order a procedure
    $procService = billableProcedureService($ctx['hospital']);
    $procedureOrder = app(ProcedureService::class)->createForEncounter($ctx['encounter'], [
        'service_id' => $procService->id,
        'clinical_indication' => 'Routine dressing',
    ], $ctx['doctor']['user']);

    // 2. Order a laboratory test alongside
    $labTest = billableLaboratoryTest($ctx['hospital']);
    $labOrder = app(\App\Services\LaboratoryOrderService::class)->create($ctx['encounter'], [
        'test_ids' => [$labTest->id],
    ], requestAs($ctx['doctor']['user']));

    // 3. Order a radiology procedure alongside
    $radProc = billableRadiologyProcedure($ctx['hospital']);
    $radOrder = app(\App\Services\RadiologyOrderService::class)->create($ctx['encounter'], [
        'procedure_ids' => [$radProc->id],
    ], requestAs($ctx['doctor']['user']));

    expect($procedureOrder)->not->toBeNull()
        ->and($labOrder)->not->toBeNull()
        ->and($radOrder)->not->toBeNull();

    // Verify encounter relationships
    $freshEncounter = $ctx['encounter']->fresh(['procedureOrders', 'laboratoryOrders', 'radiologyOrders']);
    expect($freshEncounter->procedureOrders)->toHaveCount(1)
        ->and($freshEncounter->laboratoryOrders)->toHaveCount(1)
        ->and($freshEncounter->radiologyOrders)->toHaveCount(1);
});
