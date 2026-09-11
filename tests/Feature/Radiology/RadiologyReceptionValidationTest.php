<?php

use App\Models\RadiologyAppointment;

test('an invalid not-ready reason is rejected over HTTP', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = scheduledAppointmentFor($ctx);
    $technician = userWithRole('radiology-technician');

    $this->actingAs($technician)->post(route('radiology-reception.arrive', $appointment));

    $this->actingAs($technician)->post(route('radiology-reception.not-ready', $appointment), [
        'reason' => 'not_a_real_reason',
    ])->assertSessionHasErrors('reason');
});

test('a missing not-ready reason is rejected over HTTP', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = scheduledAppointmentFor($ctx);
    $technician = userWithRole('radiology-technician');

    $this->actingAs($technician)->post(route('radiology-reception.arrive', $appointment));

    $this->actingAs($technician)->post(route('radiology-reception.not-ready', $appointment), [])
        ->assertSessionHasErrors('reason');

    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_ARRIVED);
});

test('an invalid consent status is rejected over HTTP', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = scheduledAppointmentFor($ctx);
    $technician = userWithRole('radiology-technician');

    $this->actingAs($technician)->post(route('radiology-reception.arrive', $appointment));

    $this->actingAs($technician)->post(route('radiology-reception.consent', $appointment), [
        'status' => 'maybe',
    ])->assertSessionHasErrors('status');
});

test('a scheduled (not yet arrived) appointment cannot be verified', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = scheduledAppointmentFor($ctx);
    $technician = userWithRole('radiology-technician');

    $this->actingAs($technician)->post(route('radiology-reception.verify', $appointment))
        ->assertSessionHasErrors('status');
});

test('preparation cannot be completed before it has been started', function () {
    $ctx = radiologyOrderReadyForScheduling(['preparation_type' => 'fasting']);
    $appointment = scheduledAppointmentFor($ctx);
    $technician = userWithRole('radiology-technician');

    $this->actingAs($technician)->post(route('radiology-reception.arrive', $appointment));
    $this->actingAs($technician)->post(route('radiology-reception.verify', $appointment));

    $this->actingAs($technician)->post(route('radiology-reception.complete-preparation', $appointment), ['completed' => true])
        ->assertSessionHasErrors('status');
});

test('a ready patient cannot be marked ready again', function () {
    $ctx = radiologyOrderReadyForScheduling(['preparation_type' => 'none']);
    $appointment = scheduledAppointmentFor($ctx);
    $technician = userWithRole('radiology-technician');

    $this->actingAs($technician)->post(route('radiology-reception.arrive', $appointment));
    $this->actingAs($technician)->post(route('radiology-reception.verify', $appointment));
    $this->actingAs($technician)->post(route('radiology-reception.ready', $appointment))->assertRedirect();

    $this->actingAs($technician)->post(route('radiology-reception.ready', $appointment))
        ->assertSessionHasErrors('status');
});

test('a cancelled appointment cannot be marked arrived', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = scheduledAppointmentFor($ctx);
    $technician = userWithRole('radiology-technician');

    $this->actingAs($technician)->post(route('radiology-scheduling.cancel', $appointment), ['cancellation_reason' => 'x']);

    $this->actingAs($technician)->post(route('radiology-reception.arrive', $appointment))
        ->assertSessionHasErrors('status');
});
