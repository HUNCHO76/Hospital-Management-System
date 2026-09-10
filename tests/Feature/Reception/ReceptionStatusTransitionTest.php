<?php

use App\Models\ReceptionVisit;

function createVisit(array $overrides = []): ReceptionVisit
{
    $ctx = appointmentBookingContext();

    return ReceptionVisit::factory()->create(array_merge([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $ctx['department']->id,
    ], $overrides));
}

test('a visit progresses through waiting, ready, sent to service, and completed', function () {
    $user = userWithRole('receptionist');
    $visit = createVisit();

    $this->actingAs($user)->post(route('reception.visits.waiting', $visit))->assertRedirect();
    expect($visit->fresh()->status)->toBe(ReceptionVisit::STATUS_WAITING);

    $this->actingAs($user)->post(route('reception.visits.ready', $visit))->assertRedirect();
    expect($visit->fresh()->status)->toBe(ReceptionVisit::STATUS_READY_FOR_SERVICE);

    $this->actingAs($user)->post(route('reception.visits.send-to-service', $visit))->assertRedirect();
    expect($visit->fresh()->status)->toBe(ReceptionVisit::STATUS_SENT_TO_SERVICE);

    $this->actingAs($user)->post(route('reception.visits.complete', $visit))->assertRedirect();
    expect($visit->fresh()->status)->toBe(ReceptionVisit::STATUS_COMPLETED);
    expect($visit->fresh()->completed_at)->not->toBeNull();

    $this->assertDatabaseHas('audit_logs', ['action' => 'reception_visit_completed', 'auditable_id' => $visit->id]);
});

test('a visit can be cancelled from checked_in, waiting, ready, or sent_to_service', function (string $status) {
    $user = userWithRole('receptionist');
    $visit = createVisit(['status' => $status]);

    $this->actingAs($user)->post(route('reception.visits.cancel', $visit), ['cancellation_reason' => 'Patient left'])
        ->assertRedirect();

    expect($visit->fresh()->status)->toBe(ReceptionVisit::STATUS_CANCELLED);
    expect($visit->fresh()->cancellation_reason)->toBe('Patient left');
})->with([
    ReceptionVisit::STATUS_CHECKED_IN,
    ReceptionVisit::STATUS_WAITING,
    ReceptionVisit::STATUS_READY_FOR_SERVICE,
    ReceptionVisit::STATUS_SENT_TO_SERVICE,
]);

test('a completed visit cannot transition to any other status', function () {
    $user = userWithRole('receptionist');
    $visit = createVisit(['status' => ReceptionVisit::STATUS_COMPLETED, 'completed_at' => now()]);

    $this->actingAs($user)->post(route('reception.visits.waiting', $visit))->assertSessionHasErrors('status');
    $this->actingAs($user)->post(route('reception.visits.cancel', $visit), ['cancellation_reason' => 'x'])->assertSessionHasErrors('status');

    expect($visit->fresh()->status)->toBe(ReceptionVisit::STATUS_COMPLETED);
});

test('a cancelled visit cannot transition to any other status', function () {
    $user = userWithRole('receptionist');
    $visit = createVisit(['status' => ReceptionVisit::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancellation_reason' => 'x']);

    $this->actingAs($user)->post(route('reception.visits.waiting', $visit))->assertSessionHasErrors('status');

    expect($visit->fresh()->status)->toBe(ReceptionVisit::STATUS_CANCELLED);
});

test('a checked-in visit cannot skip directly to ready for service', function () {
    $user = userWithRole('receptionist');
    $visit = createVisit(['status' => ReceptionVisit::STATUS_CHECKED_IN]);

    $this->actingAs($user)->post(route('reception.visits.ready', $visit))->assertSessionHasErrors('status');

    expect($visit->fresh()->status)->toBe(ReceptionVisit::STATUS_CHECKED_IN);
});
