<?php

use App\Models\AuditLog;
use App\Models\PrescriptionOrder;
use App\Models\PrescriptionOrderItem;
use App\Services\PrescriptionOrderService;
use Illuminate\Validation\ValidationException;

test('a prescription order can be written with multiple medications and a generated prescription number', function () {
    $ctx = diagnosisReadyEncounter();
    $medicationA = activeMedication(['generic_name' => 'Paracetamol', 'code' => 'PARA-500']);
    $medicationB = activeMedication(['generic_name' => 'Amoxicillin', 'code' => 'AMOX-500']);
    $service = app(PrescriptionOrderService::class);
    $request = requestAs($ctx['doctor']['user']);

    $order = $service->create($ctx['encounter'], [
        'items' => [
            ['medication_id' => $medicationA->id, 'dose' => '1', 'dose_unit' => 'tablet', 'frequency' => 'three_times_daily', 'route' => 'oral', 'duration' => 5, 'duration_unit' => 'days', 'quantity' => 15],
            ['medication_id' => $medicationB->id, 'dose' => '500', 'dose_unit' => 'mg', 'frequency' => 'twice_daily', 'route' => 'oral', 'quantity' => 10],
        ],
        'clinical_notes' => 'Symptomatic relief',
    ], $request);

    expect($order->clinical_encounter_id)->toBe($ctx['encounter']->id);
    expect($order->status)->toBe(PrescriptionOrder::STATUS_ACTIVE);
    expect($order->prescription_number)->toStartWith('RX-'.now()->year.'-');
    expect($order->prescriber_id)->toBe($ctx['doctor']['user']->id);
    expect($order->items)->toHaveCount(2);
    expect($order->items->pluck('medication_id')->sort()->values()->all())
        ->toBe(collect([$medicationA->id, $medicationB->id])->sort()->values()->all());
});

test('writing a prescription does not require a diagnosis to exist', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication();

    expect($ctx['encounter']->diagnoses()->exists())->toBeFalse();

    $order = app(PrescriptionOrderService::class)->create($ctx['encounter'], [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]],
    ], requestAs($ctx['doctor']['user']));

    expect($order->status)->toBe(PrescriptionOrder::STATUS_ACTIVE);
});

test('order items snapshot the medication code, name, strength, form, and route at prescribing time', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication(['code' => 'PARA-500', 'generic_name' => 'Paracetamol', 'strength' => '500mg', 'form' => 'tablet', 'route' => 'oral']);

    $order = app(PrescriptionOrderService::class)->create($ctx['encounter'], [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]],
    ], requestAs($ctx['doctor']['user']));

    $item = $order->items->sole();
    expect($item->medication_code_snapshot)->toBe('PARA-500');
    expect($item->medication_name_snapshot)->toBe('Paracetamol');
    expect($item->strength_snapshot)->toBe('500mg');
    expect($item->form_snapshot)->toBe('tablet');
    expect($item->route_snapshot)->toBe('oral');

    $medication->update(['generic_name' => 'Renamed Medication', 'strength' => '250mg']);

    expect($item->fresh()->medication_name_snapshot)->toBe('Paracetamol');
    expect($item->fresh()->strength_snapshot)->toBe('500mg');
});

test('duplicate medications within one prescription are rejected, not silently deduplicated', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication();

    expect(fn () => app(PrescriptionOrderService::class)->create($ctx['encounter'], [
        'items' => [
            ['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5],
            ['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5],
        ],
    ], requestAs($ctx['doctor']['user'])))->toThrow(ValidationException::class);
});

test('creating a prescription records an audit event and one item-added event per medication', function () {
    $ctx = diagnosisReadyEncounter();
    $medicationA = activeMedication();
    $medicationB = activeMedication();

    $order = app(PrescriptionOrderService::class)->create($ctx['encounter'], [
        'items' => [
            ['medication_id' => $medicationA->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5],
            ['medication_id' => $medicationB->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5],
        ],
    ], requestAs($ctx['doctor']['user']));

    $this->assertDatabaseHas('audit_logs', ['action' => 'prescription_order_created', 'auditable_id' => $order->id]);
    expect(AuditLog::query()->where('action', 'like', 'prescription_order%')->count())->toBe(3); // 1 order_created + 2 item_added
});

test('updating clinical notes is audited', function () {
    $ctx = diagnosisReadyEncounter();
    $request = requestAs($ctx['doctor']['user']);
    $order = activePrescriptionOrderFor($ctx);

    app(PrescriptionOrderService::class)->update($order, ['clinical_notes' => 'Reviewed and adjusted'], $request);

    expect($order->fresh()->clinical_notes)->toBe('Reviewed and adjusted');
    $this->assertDatabaseHas('audit_logs', ['action' => 'prescription_order_updated', 'auditable_id' => $order->id]);
});

test('updating an order can add new medications without touching existing items', function () {
    $ctx = diagnosisReadyEncounter();
    $request = requestAs($ctx['doctor']['user']);
    $order = activePrescriptionOrderFor($ctx);
    $newMedication = activeMedication();

    app(PrescriptionOrderService::class)->update($order, [
        'items' => [['medication_id' => $newMedication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]],
    ], $request);

    expect($order->fresh()->items)->toHaveCount(2);
});

test('updating an order rejects a medication already active on it', function () {
    $ctx = diagnosisReadyEncounter();
    $request = requestAs($ctx['doctor']['user']);
    $medication = activeMedication();
    $order = activePrescriptionOrderFor($ctx, ['items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]]]);

    expect(fn () => app(PrescriptionOrderService::class)->update($order, [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]],
    ], $request))->toThrow(ValidationException::class);
});

test('a prescription order cannot be updated once no longer active', function () {
    $ctx = diagnosisReadyEncounter();
    $request = requestAs($ctx['doctor']['user']);
    $order = activePrescriptionOrderFor($ctx);

    app(PrescriptionOrderService::class)->cancel($order, 'no longer needed', $request);

    expect(fn () => app(PrescriptionOrderService::class)->update($order->fresh(), ['clinical_notes' => 'x'], $request))
        ->toThrow(ValidationException::class);
});

test('cancelling a prescription order cascades to every non-cancelled item', function () {
    $ctx = diagnosisReadyEncounter();
    $request = requestAs($ctx['doctor']['user']);
    $medicationA = activeMedication();
    $medicationB = activeMedication();
    $order = activePrescriptionOrderFor($ctx, ['items' => [
        ['medication_id' => $medicationA->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5],
        ['medication_id' => $medicationB->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5],
    ]]);

    app(PrescriptionOrderService::class)->cancel($order, 'Patient allergic reaction', $request);

    expect($order->fresh()->status)->toBe(PrescriptionOrder::STATUS_CANCELLED);
    expect($order->fresh()->cancellation_reason)->toBe('Patient allergic reaction');
    $order->fresh()->items->each(fn (PrescriptionOrderItem $item) => expect($item->status)->toBe(PrescriptionOrderItem::STATUS_CANCELLED));
    $this->assertDatabaseHas('audit_logs', ['action' => 'prescription_order_cancelled', 'auditable_id' => $order->id]);
});

test('a prescription order cannot be cancelled twice', function () {
    $ctx = diagnosisReadyEncounter();
    $request = requestAs($ctx['doctor']['user']);
    $order = activePrescriptionOrderFor($ctx);

    app(PrescriptionOrderService::class)->cancel($order, 'reason', $request);

    expect(fn () => app(PrescriptionOrderService::class)->cancel($order->fresh(), 'again', $request))
        ->toThrow(ValidationException::class);
});

test('a single medication can be removed from a prescription while it is still active', function () {
    $ctx = diagnosisReadyEncounter();
    $request = requestAs($ctx['doctor']['user']);
    $medicationA = activeMedication();
    $medicationB = activeMedication();
    $order = activePrescriptionOrderFor($ctx, ['items' => [
        ['medication_id' => $medicationA->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5],
        ['medication_id' => $medicationB->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5],
    ]]);
    $item = $order->items->firstWhere('medication_id', $medicationA->id);

    app(PrescriptionOrderService::class)->cancelItem($item, 'Not needed', $request);

    expect($item->fresh()->status)->toBe(PrescriptionOrderItem::STATUS_CANCELLED);
    expect($order->fresh()->status)->toBe(PrescriptionOrder::STATUS_ACTIVE);
    $this->assertDatabaseHas('audit_logs', ['action' => 'prescription_order_item_removed', 'auditable_id' => $item->id]);
});

test('an item cannot be removed once the parent order is no longer active', function () {
    $ctx = diagnosisReadyEncounter();
    $request = requestAs($ctx['doctor']['user']);
    $order = activePrescriptionOrderFor($ctx);
    $item = $order->items->sole();

    app(PrescriptionOrderService::class)->cancel($order, 'reason', $request);

    expect(fn () => app(PrescriptionOrderService::class)->cancelItem($item->fresh(), 'too late', $request))
        ->toThrow(ValidationException::class);
});
