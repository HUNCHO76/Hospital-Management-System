<?php

use App\Models\Charge;
use App\Models\Dispensing;
use App\Models\DispensingItem;
use App\Models\Hospital;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderItem;
use App\Models\LaboratoryTest;
use App\Models\Patient;
use App\Models\RadiologyOrderItem;
use App\Services\ChargeCaptureService;
use App\Services\DispensingService;
use App\Services\LaboratoryOrderService;
use App\Services\PrescriptionOrderService;
use App\Services\PrescriptionVerificationService;
use App\Services\RadiologyOrderService;
use Illuminate\Validation\ValidationException;

/**
 * Automated charge capture (Phase 7, Step 54, spec §10-19): a Laboratory or
 * Radiology order item that is mapped and priced in the billing catalog
 * automatically produces a Charge the moment it is ordered, reusing
 * ServicePriceResolver (Step 53) rather than re-deriving a price. A missing
 * mapping/price never blocks the clinical order itself — see
 * ChargeCaptureService's own docblock.
 */
test('placing a laboratory order for a billable, priced test automatically captures a charge', function () {
    $ctx = diagnosisReadyEncounter();
    $test = billableLaboratoryTest($ctx['hospital'], [], ['amount' => 15000]);

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], [
        'test_ids' => [$test->id],
    ], requestAs($ctx['doctor']['user']));

    $item = $order->items->sole();
    $charge = Charge::query()->where('source_type', LaboratoryOrderItem::class)->where('source_id', $item->id)->first();

    expect($charge)->not->toBeNull();
    expect($charge->status)->toBe(Charge::STATUS_PENDING);
    expect((float) $charge->unit_price)->toBe(15000.0);
    expect((float) $charge->net_amount)->toBe(15000.0);
    expect($charge->service_id)->not->toBeNull();
    expect($charge->service_code_snapshot)->toBe($test->code);
    expect($charge->description)->toBe($test->name);
    expect($charge->patient_id)->toBe($ctx['patient']->id);
    expect($charge->hospital_id)->toBe($ctx['hospital']->id);
    expect($charge->encounter_id)->toBe($ctx['encounter']->id);
    expect($charge->charge_number)->toStartWith('CHG-'.now()->year.'-');
});

test('placing a laboratory order for a test with no billing mapping does not block the order and captures no charge', function () {
    $ctx = diagnosisReadyEncounter();
    $test = activeLaboratoryTest();

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], [
        'test_ids' => [$test->id],
    ], requestAs($ctx['doctor']['user']));

    expect($order->status)->toBe(LaboratoryOrder::STATUS_ORDERED);
    $this->assertDatabaseCount('charges', 0);
    $this->assertDatabaseHas('audit_logs', ['action' => 'charge_capture_skipped']);
});

test('placing a laboratory order for a mapped but unpriced test does not block the order and captures no charge', function () {
    $ctx = diagnosisReadyEncounter();
    $test = activeLaboratoryTest();
    serviceFor([
        'code' => $test->code,
        'name' => $test->name,
        'category' => 'laboratory',
        'serviceable_type' => LaboratoryTest::class,
        'serviceable_id' => $test->id,
    ]);

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], [
        'test_ids' => [$test->id],
    ], requestAs($ctx['doctor']['user']));

    expect($order->status)->toBe(LaboratoryOrder::STATUS_ORDERED);
    $this->assertDatabaseCount('charges', 0);
});

test('cancelling a laboratory order voids its captured charges', function () {
    $ctx = diagnosisReadyEncounter();
    $test = billableLaboratoryTest($ctx['hospital']);
    $request = requestAs($ctx['doctor']['user']);

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$test->id]], $request);
    $item = $order->items->sole();

    app(LaboratoryOrderService::class)->cancel($order, 'Ordered in error', $request);

    $charge = Charge::query()->where('source_type', LaboratoryOrderItem::class)->where('source_id', $item->id)->sole();
    expect($charge->status)->toBe(Charge::STATUS_VOIDED);
    expect($charge->cancellation_reason)->toBe('Ordered in error');
});

test('removing a single laboratory order item voids only its own captured charge', function () {
    $ctx = diagnosisReadyEncounter();
    $testA = billableLaboratoryTest($ctx['hospital']);
    $testB = billableLaboratoryTest($ctx['hospital']);
    $request = requestAs($ctx['doctor']['user']);

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$testA->id, $testB->id]], $request);
    $itemA = $order->items->firstWhere('laboratory_test_id', $testA->id);
    $itemB = $order->items->firstWhere('laboratory_test_id', $testB->id);

    app(LaboratoryOrderService::class)->cancelItem($itemA, 'Not needed', $request);

    $chargeA = Charge::query()->where('source_type', LaboratoryOrderItem::class)->where('source_id', $itemA->id)->sole();
    $chargeB = Charge::query()->where('source_type', LaboratoryOrderItem::class)->where('source_id', $itemB->id)->sole();
    expect($chargeA->status)->toBe(Charge::STATUS_VOIDED);
    expect($chargeB->status)->toBe(Charge::STATUS_PENDING);
});

test('placing a radiology order for a billable, priced procedure automatically captures a charge', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = billableRadiologyProcedure($ctx['hospital'], [], ['amount' => 45000]);

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], [
        'procedure_ids' => [$procedure->id],
    ], requestAs($ctx['doctor']['user']));

    $item = $order->items->sole();
    $charge = Charge::query()->where('source_type', RadiologyOrderItem::class)->where('source_id', $item->id)->first();

    expect($charge)->not->toBeNull();
    expect((float) $charge->unit_price)->toBe(45000.0);
    expect($charge->service_code_snapshot)->toBe($procedure->code);
});

test('cancelling a radiology order voids its captured charges', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = billableRadiologyProcedure($ctx['hospital']);
    $request = requestAs($ctx['doctor']['user']);

    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], ['procedure_ids' => [$procedure->id]], $request);
    $item = $order->items->sole();

    app(RadiologyOrderService::class)->cancel($order, 'Ordered in error', $request);

    $charge = Charge::query()->where('source_type', RadiologyOrderItem::class)->where('source_id', $item->id)->sole();
    expect($charge->status)->toBe(Charge::STATUS_VOIDED);
});

test('completing dispensing for a priced medication automatically captures a charge from Medication.price', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication(['price' => 5000]);
    $order = app(PrescriptionOrderService::class)->create($ctx['encounter'], [
        'items' => [[
            'medication_id' => $medication->id,
            'dose' => '1 tablet',
            'frequency' => 'three_times_daily',
            'route' => 'oral',
            'duration' => 5,
            'duration_unit' => 'days',
            'quantity' => 15,
            'instructions' => 'Take after meals',
        ]],
    ], requestAs($ctx['doctor']['user']));
    $orderItem = $order->items->sole();
    receiveStock($medication, $ctx['hospital']);

    $pharmacist = userWithRole('pharmacist');
    app(PrescriptionVerificationService::class)->verify($order->fresh(), [], requestAs($pharmacist));
    $dispensing = app(DispensingService::class)->start($order->fresh(), requestAs($pharmacist));

    app(DispensingService::class)->complete($dispensing, [
        'items' => [['prescription_order_item_id' => $orderItem->id, 'dispensed_quantity' => 10]],
    ], requestAs($pharmacist));

    $dispensingItem = DispensingItem::query()->where('dispensing_id', $dispensing->id)->sole();
    $charge = Charge::query()->where('source_type', DispensingItem::class)->where('source_id', $dispensingItem->id)->first();

    expect($charge)->not->toBeNull();
    expect($charge->status)->toBe(Charge::STATUS_PENDING);
    expect($charge->quantity)->toBe(10);
    expect((float) $charge->unit_price)->toBe(5000.0);
    expect((float) $charge->net_amount)->toBe(50000.0);
    expect($charge->service_id)->toBeNull();
    expect($charge->service_code_snapshot)->toBe($orderItem->medication_code_snapshot);
    expect($charge->description)->toBe($orderItem->medication_name_snapshot);
});

test('completing dispensing for a medication with no configured price does not block dispensing and captures no charge', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication(['price' => null]);
    $order = app(PrescriptionOrderService::class)->create($ctx['encounter'], [
        'items' => [[
            'medication_id' => $medication->id,
            'dose' => '1 tablet',
            'frequency' => 'three_times_daily',
            'route' => 'oral',
            'duration' => 5,
            'duration_unit' => 'days',
            'quantity' => 15,
            'instructions' => 'Take after meals',
        ]],
    ], requestAs($ctx['doctor']['user']));
    $orderItem = $order->items->sole();
    receiveStock($medication, $ctx['hospital']);

    $pharmacist = userWithRole('pharmacist');
    app(PrescriptionVerificationService::class)->verify($order->fresh(), [], requestAs($pharmacist));
    $dispensing = app(DispensingService::class)->start($order->fresh(), requestAs($pharmacist));

    $completed = app(DispensingService::class)->complete($dispensing, [
        'items' => [['prescription_order_item_id' => $orderItem->id, 'dispensed_quantity' => 10]],
    ], requestAs($pharmacist));

    expect($completed->status)->toBe(Dispensing::STATUS_COMPLETED);
    $this->assertDatabaseCount('charges', 0);
    $this->assertDatabaseHas('audit_logs', ['action' => 'charge_capture_skipped']);
});

test('a source item can never produce two charges — duplicate capture is idempotent', function () {
    $ctx = diagnosisReadyEncounter();
    $test = billableLaboratoryTest($ctx['hospital']);
    $request = requestAs($ctx['doctor']['user']);

    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], ['test_ids' => [$test->id]], $request);
    $item = $order->items->sole();

    $service = app(ChargeCaptureService::class);
    $first = $service->captureFromLaboratoryOrderItem($item, $ctx['encounter'], $request);
    $second = $service->captureFromLaboratoryOrderItem($item, $ctx['encounter'], $request);

    expect($first->id)->toBe($second->id);
    $this->assertDatabaseCount('charges', 1);
});

test('manual charge capture requires selecting a catalog service and a reason, never a free-typed price', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $service = serviceFor();
    priceFor($service, $hospital, ['amount' => 5000]);
    $actor = userWithRole('accountant');

    $charge = app(ChargeCaptureService::class)->captureManual([
        'hospital_id' => $hospital->id,
        'patient_id' => $patient->id,
        'service_id' => $service->id,
        'quantity' => 2,
        'notes' => 'Walk-in consultation, no prior encounter',
    ], requestAs($actor));

    expect((float) $charge->unit_price)->toBe(5000.0);
    expect((float) $charge->net_amount)->toBe(10000.0);
    expect($charge->notes)->toBe('Walk-in consultation, no prior encounter');
    expect($charge->source_type)->toBeNull();
});

test('manual charge capture without a reason is rejected', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $service = serviceFor();
    priceFor($service, $hospital);

    expect(fn () => app(ChargeCaptureService::class)->captureManual([
        'hospital_id' => $hospital->id,
        'patient_id' => $patient->id,
        'service_id' => $service->id,
        'notes' => '',
    ], requestAs(userWithRole('accountant'))))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('charges', 0);
});

test('manual charge capture against an unpriced service is rejected', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $service = serviceFor();

    expect(fn () => app(ChargeCaptureService::class)->captureManual([
        'hospital_id' => $hospital->id,
        'patient_id' => $patient->id,
        'service_id' => $service->id,
        'notes' => 'reason',
    ], requestAs(userWithRole('accountant'))))->toThrow(ValidationException::class);
});

test('manual charge capture rejects an encounter that does not belong to the selected patient', function () {
    $ctx = diagnosisReadyEncounter();
    $otherPatient = Patient::factory()->create();
    $service = serviceFor();
    priceFor($service, $ctx['hospital']);

    expect(fn () => app(ChargeCaptureService::class)->captureManual([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $otherPatient->id,
        'service_id' => $service->id,
        'encounter_id' => $ctx['encounter']->id,
        'notes' => 'reason',
    ], requestAs(userWithRole('accountant'))))->toThrow(ValidationException::class);
});
