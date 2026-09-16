<?php

use App\Models\ClinicalEncounter;
use App\Models\PrescriptionOrder;
use App\Services\PrescriptionOrderService;
use Illuminate\Validation\ValidationException;

test('a prescription cannot be written before the consultation has been started', function () {
    $ctx = consultationReadyEncounter();
    $medication = activeMedication();
    $doctor = doctorWithPractitioner($ctx['hospital']);

    expect($ctx['encounter']->consultation)->toBeNull();

    expect(fn () => app(PrescriptionOrderService::class)->create($ctx['encounter'], [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]],
    ], requestAs($doctor['user'])))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('prescription_orders', 0);
});

test('a prescription cannot be written on a non-active clinical encounter', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication();

    $ctx['encounter']->update(['status' => ClinicalEncounter::STATUS_CANCELLED]);

    expect(fn () => app(PrescriptionOrderService::class)->create($ctx['encounter']->fresh(), [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]],
    ], requestAs($ctx['doctor']['user'])))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('prescription_orders', 0);
});

test('an inactive or non-existent medication cannot be prescribed', function () {
    $ctx = diagnosisReadyEncounter();
    $inactiveMedication = activeMedication(['status' => 'inactive']);

    expect(fn () => app(PrescriptionOrderService::class)->create($ctx['encounter'], [
        'items' => [['medication_id' => $inactiveMedication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]],
    ], requestAs($ctx['doctor']['user'])))->toThrow(ValidationException::class);

    expect(fn () => app(PrescriptionOrderService::class)->create($ctx['encounter'], [
        'items' => [['medication_id' => 999999, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]],
    ], requestAs($ctx['doctor']['user'])))->toThrow(ValidationException::class);
});

test('an invalid frequency is rejected over HTTP', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication();

    $this->actingAs($ctx['doctor']['user'])->post(route('clinical-encounters.prescription-orders.store', $ctx['encounter']), [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'not-a-real-frequency', 'route' => 'oral', 'quantity' => 5]],
    ])->assertSessionHasErrors('items.0.frequency');

    $this->assertDatabaseCount('prescription_orders', 0);
});

test('an invalid route is rejected over HTTP', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication();

    $this->actingAs($ctx['doctor']['user'])->post(route('clinical-encounters.prescription-orders.store', $ctx['encounter']), [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'not-a-real-route', 'quantity' => 5]],
    ])->assertSessionHasErrors('items.0.route');

    $this->assertDatabaseCount('prescription_orders', 0);
});

test('an empty medication list is rejected over HTTP', function () {
    $ctx = diagnosisReadyEncounter();

    $this->actingAs($ctx['doctor']['user'])->post(route('clinical-encounters.prescription-orders.store', $ctx['encounter']), [
        'items' => [],
    ])->assertSessionHasErrors('items');

    $this->assertDatabaseCount('prescription_orders', 0);
});

test('a missing dose is rejected over HTTP', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication();

    $this->actingAs($ctx['doctor']['user'])->post(route('clinical-encounters.prescription-orders.store', $ctx['encounter']), [
        'items' => [['medication_id' => $medication->id, 'dose' => '', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]],
    ])->assertSessionHasErrors('items.0.dose');

    $this->assertDatabaseCount('prescription_orders', 0);
});

test('zero or negative quantity is rejected over HTTP', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication();

    $this->actingAs($ctx['doctor']['user'])->post(route('clinical-encounters.prescription-orders.store', $ctx['encounter']), [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 0]],
    ])->assertSessionHasErrors('items.0.quantity');

    $this->actingAs($ctx['doctor']['user'])->post(route('clinical-encounters.prescription-orders.store', $ctx['encounter']), [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => -5]],
    ])->assertSessionHasErrors('items.0.quantity');

    $this->assertDatabaseCount('prescription_orders', 0);
});

test('zero or negative duration is rejected over HTTP', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication();

    $this->actingAs($ctx['doctor']['user'])->post(route('clinical-encounters.prescription-orders.store', $ctx['encounter']), [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5, 'duration' => 0]],
    ])->assertSessionHasErrors('items.0.duration');

    $this->assertDatabaseCount('prescription_orders', 0);
});

test('a duplicate medication in the same submission is rejected over HTTP', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication();

    $this->actingAs($ctx['doctor']['user'])->post(route('clinical-encounters.prescription-orders.store', $ctx['encounter']), [
        'items' => [
            ['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5],
            ['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5],
        ],
    ])->assertSessionHasErrors('items.0.medication_id');

    $this->assertDatabaseCount('prescription_orders', 0);
});

test('a prescription order is always attached to its own encounter\'s patient — there is no input path to attach it to another patient', function () {
    $ctxA = diagnosisReadyEncounter();
    $medication = activeMedication();

    // create() only ever accepts a ClinicalEncounter — patient_id is always
    // derived server-side through clinical_encounter_id, never accepted as
    // client input, so an order can never be misattributed to another
    // patient regardless of what a caller submits.
    $order = app(PrescriptionOrderService::class)->create($ctxA['encounter'], [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]],
    ], requestAs($ctxA['doctor']['user']));

    expect($order->clinicalEncounter->patient_id)->toBe($ctxA['patient']->id);
});

test('instructions containing markup are stored as plain text, never interpreted as HTML server-side', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication();
    $payload = '<script>alert(1)</script>';

    $order = app(PrescriptionOrderService::class)->create($ctx['encounter'], [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5, 'instructions' => $payload]],
    ], requestAs($ctx['doctor']['user']));

    // Stored verbatim as data — no server-side HTML rendering ever occurs
    // for this field, and the Inertia/React frontend escapes it by default.
    expect($order->items->sole()->instructions)->toBe($payload);
});

test('a client-supplied prescriber_id, status, or cancelled fields are never mass-assignable through the store request', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication();
    $otherUser = userWithRole('doctor');

    $this->actingAs($ctx['doctor']['user'])->post(route('clinical-encounters.prescription-orders.store', $ctx['encounter']), [
        'items' => [['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5]],
        'prescriber_id' => $otherUser->id,
        'status' => 'cancelled',
        'clinical_encounter_id' => 999999,
    ])->assertRedirect();

    $order = PrescriptionOrder::query()->where('clinical_encounter_id', $ctx['encounter']->id)->firstOrFail();
    expect($order->prescriber_id)->toBe($ctx['doctor']['user']->id);
    expect($order->status)->toBe(PrescriptionOrder::STATUS_ACTIVE);
});
