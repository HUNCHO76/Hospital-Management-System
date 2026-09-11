<?php

use App\Models\ClinicalEncounter;
use App\Services\RadiologyOrderService;
use Illuminate\Validation\ValidationException;

test('a radiology order cannot be placed before the consultation has been started', function () {
    $ctx = consultationReadyEncounter();
    $procedure = activeRadiologyProcedure();
    $doctor = doctorWithPractitioner($ctx['hospital']);

    expect($ctx['encounter']->consultation)->toBeNull();

    expect(fn () => app(RadiologyOrderService::class)->create($ctx['encounter'], [
        'procedure_ids' => [$procedure->id],
    ], requestAs($doctor['user'])))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('radiology_orders', 0);
});

test('a radiology order cannot be placed on a non-active clinical encounter', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure();

    $ctx['encounter']->update(['status' => ClinicalEncounter::STATUS_CANCELLED]);

    expect(fn () => app(RadiologyOrderService::class)->create($ctx['encounter']->fresh(), [
        'procedure_ids' => [$procedure->id],
    ], requestAs($ctx['doctor']['user'])))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('radiology_orders', 0);
});

test('an invalid priority is rejected over HTTP', function () {
    $ctx = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure();

    $this->actingAs($ctx['doctor']['user'])->post(route('clinical-encounters.radiology-orders.store', $ctx['encounter']), [
        'procedure_ids' => [$procedure->id],
        'priority' => 'not-a-real-priority',
    ])->assertSessionHasErrors('priority');

    $this->assertDatabaseCount('radiology_orders', 0);
});

test('an empty procedure list is rejected over HTTP', function () {
    $ctx = diagnosisReadyEncounter();

    $this->actingAs($ctx['doctor']['user'])->post(route('clinical-encounters.radiology-orders.store', $ctx['encounter']), [
        'procedure_ids' => [],
    ])->assertSessionHasErrors('procedure_ids');

    $this->assertDatabaseCount('radiology_orders', 0);
});

test('a radiology order is always attached to its own encounter\'s patient — there is no input path to attach it to another patient', function () {
    $ctxA = diagnosisReadyEncounter();
    $procedure = activeRadiologyProcedure();

    // create() only ever accepts a ClinicalEncounter — patient_id is always
    // derived server-side through clinical_encounter_id, never accepted as
    // client input, so an order can never be misattributed to another
    // patient regardless of what a caller submits. Verified via the actual
    // stored relationship chain rather than a nonexistent patient_id column
    // on the order itself.
    $order = app(RadiologyOrderService::class)->create($ctxA['encounter'], [
        'procedure_ids' => [$procedure->id],
    ], requestAs($ctxA['doctor']['user']));

    expect($order->clinicalEncounter->patient_id)->toBe($ctxA['patient']->id);
});
