<?php

use App\Models\ClinicalEncounter;
use App\Models\Hospital;
use App\Models\RadiologyAppointment;
use App\Models\RadiologyOrder;
use App\Models\RadiologyOrderItem;
use App\Models\Staff;
use App\Models\User;
use App\Services\ConsultationService;
use App\Services\RadiologyOrderService;
use App\Services\RadiologySchedulingService;
use App\Services\TriageService;
use App\Services\VitalSignsService;

test('a guest is redirected to login for scheduling and the worklist', function () {
    $ctx = radiologyOrderReadyForScheduling();

    $this->get(route('radiology-scheduling.index'))->assertRedirect(route('login'));
    $this->get(route('radiology-worklist.index'))->assertRedirect(route('login'));
    $this->post(route('radiology-scheduling.store'))->assertRedirect(route('login'));
});

test('a doctor cannot schedule, reschedule, confirm, or cancel a radiology appointment', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $scheduler = userWithRole('radiology-technician');
    $appointment = app(RadiologySchedulingService::class)->schedule($ctx['item'], [
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], requestAs($scheduler));

    $this->actingAs($ctx['doctor']['user'])->post(route('radiology-scheduling.store'), [
        'radiology_order_item_id' => $ctx['item']->id,
        'resource_name' => 'X-Ray Room 2',
        'scheduled_start' => now()->addDays(2)->toDateTimeString(),
        'scheduled_end' => now()->addDays(2)->addMinutes(30)->toDateTimeString(),
    ])->assertForbidden();

    $this->actingAs($ctx['doctor']['user'])->post(route('radiology-scheduling.reschedule', $appointment), [
        'scheduled_start' => now()->addDays(3)->toDateTimeString(),
        'scheduled_end' => now()->addDays(3)->addMinutes(30)->toDateTimeString(),
        'reason' => 'x',
    ])->assertForbidden();

    $this->actingAs($ctx['doctor']['user'])->post(route('radiology-scheduling.confirm', $appointment))->assertForbidden();
    $this->actingAs($ctx['doctor']['user'])->post(route('radiology-scheduling.cancel', $appointment), ['cancellation_reason' => 'x'])->assertForbidden();
    $this->actingAs($ctx['doctor']['user'])->post(route('radiology-scheduling.no-show', $appointment))->assertForbidden();

    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_SCHEDULED);
});

test('a receptionist cannot view or manage radiology scheduling', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $receptionist = userWithRole('receptionist');

    $this->actingAs($receptionist)->get(route('radiology-scheduling.index'))->assertForbidden();
    $this->actingAs($receptionist)->get(route('radiology-worklist.index'))->assertForbidden();
    $this->actingAs($receptionist)->post(route('radiology-scheduling.store'), [
        'radiology_order_item_id' => $ctx['item']->id,
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->toDateTimeString(),
        'scheduled_end' => now()->addDay()->addMinutes(30)->toDateTimeString(),
    ])->assertForbidden();
});

test('a radiology technician can schedule, reschedule, confirm, and cancel', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $scheduler = userWithRole('radiology-technician');

    $this->actingAs($scheduler)->get(route('radiology-scheduling.index'))->assertOk();
    $this->actingAs($scheduler)->get(route('radiology-worklist.index'))->assertOk();

    $this->actingAs($scheduler)->post(route('radiology-scheduling.store'), [
        'radiology_order_item_id' => $ctx['item']->id,
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0)->toDateTimeString(),
        'scheduled_end' => now()->addDay()->setTime(9, 30)->toDateTimeString(),
    ])->assertRedirect();

    $appointment = RadiologyAppointment::query()->where('radiology_order_item_id', $ctx['item']->id)->sole();

    $this->actingAs($scheduler)->post(route('radiology-scheduling.confirm', $appointment))->assertRedirect();
    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_CONFIRMED);

    $this->actingAs($scheduler)->post(route('radiology-scheduling.reschedule', $appointment), [
        'scheduled_start' => now()->addDays(2)->setTime(9, 0)->toDateTimeString(),
        'scheduled_end' => now()->addDays(2)->setTime(9, 30)->toDateTimeString(),
        'reason' => 'Room maintenance',
    ])->assertRedirect();

    $this->actingAs($scheduler)->post(route('radiology-scheduling.cancel', $appointment), ['cancellation_reason' => 'x'])->assertRedirect();
    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_CANCELLED);
});

test('a hospital-admin can act within their own hospital but not another', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $ctxA = radiologyReadyItemFor($hospitalA);
    $ctxB = radiologyReadyItemFor($hospitalB);

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->post(route('radiology-scheduling.store'), [
        'radiology_order_item_id' => $ctxA['item']->id,
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0)->toDateTimeString(),
        'scheduled_end' => now()->addDay()->setTime(9, 30)->toDateTimeString(),
    ])->assertRedirect();

    $this->actingAs($adminA)->post(route('radiology-scheduling.store'), [
        'radiology_order_item_id' => $ctxB['item']->id,
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(11, 0)->toDateTimeString(),
        'scheduled_end' => now()->addDay()->setTime(11, 30)->toDateTimeString(),
    ])->assertForbidden();

    $appointmentA = RadiologyAppointment::query()->where('radiology_order_item_id', $ctxA['item']->id)->sole();
    $adminB = User::factory()->create();
    $adminB->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminB->id, 'hospital_id' => $hospitalB->id]);

    $this->actingAs($adminB)->post(route('radiology-scheduling.cancel', $appointmentA), ['cancellation_reason' => 'cross-hospital'])
        ->assertForbidden();
});

test('a super-admin bypasses radiology scheduling permission checks', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $user = userWithRole('super-admin');

    $this->actingAs($user)->post(route('radiology-scheduling.store'), [
        'radiology_order_item_id' => $ctx['item']->id,
        'resource_name' => 'X-Ray Room 1',
        'scheduled_start' => now()->addDay()->setTime(9, 0)->toDateTimeString(),
        'scheduled_end' => now()->addDay()->setTime(9, 30)->toDateTimeString(),
    ])->assertRedirect();

    $this->assertDatabaseCount('radiology_appointments', 1);
});

/**
 * Build a radiology-order-ready item under a specific hospital, returning
 * the item and its context — mirrors radiologyReadyEncounterFor() from
 * RadiologyOrderAuthorizationTest exactly.
 *
 * @return array{item: RadiologyOrderItem, order: RadiologyOrder}
 */
function radiologyReadyItemFor(Hospital $hospital): array
{
    $ctx = clinicalEncounterContext($hospital);
    $encounter = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);

    $triageRequest = requestAs(userWithRole('nurse'));
    $triage = app(TriageService::class)->create($encounter, $triageRequest);
    app(TriageService::class)->start($triage, $triageRequest);
    app(TriageService::class)->update($triage, ['triage_category' => 'urgent'], $triageRequest);
    app(VitalSignsService::class)->record($triage, ['temperature_c' => 37.0], $triageRequest);
    app(TriageService::class)->complete($triage->fresh(), $triageRequest);

    $doctor = doctorWithPractitioner($hospital);
    $consultationRequest = requestAs($doctor['user']);
    $consultation = app(ConsultationService::class)->create($encounter->fresh(), $consultationRequest);
    app(ConsultationService::class)->start($consultation, $consultationRequest);

    $procedure = activeRadiologyProcedure();
    $order = app(RadiologyOrderService::class)->create($encounter->fresh(), ['procedure_ids' => [$procedure->id]], $consultationRequest);

    return ['item' => $order->items->sole(), 'order' => $order];
}
