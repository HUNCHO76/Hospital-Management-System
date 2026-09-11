<?php

use App\Models\ClinicalEncounter;
use App\Models\Hospital;
use App\Models\RadiologyAppointment;
use App\Models\Staff;
use App\Models\User;
use App\Services\ConsultationService;
use App\Services\RadiologyOrderService;
use App\Services\RadiologySchedulingService;
use App\Services\TriageService;
use App\Services\VitalSignsService;

test('a guest is redirected to login for the reception dashboard and every mutation', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = scheduledAppointmentFor($ctx);

    $this->get(route('radiology-reception.index'))->assertRedirect(route('login'));
    $this->post(route('radiology-reception.arrive', $appointment))->assertRedirect(route('login'));
});

test('a doctor cannot access radiology reception at all', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = scheduledAppointmentFor($ctx);

    $this->actingAs($ctx['doctor']['user'])->get(route('radiology-reception.index'))->assertForbidden();
    $this->actingAs($ctx['doctor']['user'])->post(route('radiology-reception.arrive', $appointment))->assertForbidden();

    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_SCHEDULED);
});

test('a receptionist cannot access radiology reception', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = scheduledAppointmentFor($ctx);
    $receptionist = userWithRole('receptionist');

    $this->actingAs($receptionist)->get(route('radiology-reception.index'))->assertForbidden();
    $this->actingAs($receptionist)->post(route('radiology-reception.arrive', $appointment))->assertForbidden();
});

test('a radiology technician can run the full reception workflow over HTTP', function () {
    $ctx = radiologyOrderReadyForScheduling(['preparation_type' => 'none']);
    $appointment = scheduledAppointmentFor($ctx);
    $technician = userWithRole('radiology-technician');

    $this->actingAs($technician)->get(route('radiology-reception.index'))->assertOk();

    $this->actingAs($technician)->post(route('radiology-reception.arrive', $appointment))->assertRedirect();
    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_ARRIVED);

    $this->actingAs($technician)->post(route('radiology-reception.verify', $appointment))->assertRedirect();
    expect($appointment->fresh()->verified_at)->not->toBeNull();

    $this->actingAs($technician)->post(route('radiology-reception.ready', $appointment))->assertRedirect();
    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_READY);
});

test('a hospital-admin can manage reception within their own hospital but not another', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $appointmentA = receptionReadyAppointmentFor($hospitalA);
    $appointmentB = receptionReadyAppointmentFor($hospitalB);

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->post(route('radiology-reception.arrive', $appointmentA))->assertRedirect();
    $this->actingAs($adminA)->post(route('radiology-reception.arrive', $appointmentB))->assertForbidden();

    expect($appointmentB->fresh()->status)->toBe(RadiologyAppointment::STATUS_SCHEDULED);
});

test('a super-admin bypasses radiology reception permission checks', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = scheduledAppointmentFor($ctx);
    $user = userWithRole('super-admin');

    $this->actingAs($user)->post(route('radiology-reception.arrive', $appointment))->assertRedirect();

    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_ARRIVED);
});

/**
 * Build a scheduled radiology appointment under a specific hospital —
 * mirrors radiologyReadyItemFor() from RadiologySchedulingAuthorizationTest.
 */
function receptionReadyAppointmentFor(Hospital $hospital): RadiologyAppointment
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

    $scheduler = userWithRole('radiology-technician');

    // A distinct resource per hospital — two hospitals' rooms are never
    // actually the same physical resource, and reusing one literal name
    // across both fixtures would otherwise collide with the (correctly
    // working) cross-hospital-agnostic double-booking guard.
    return app(RadiologySchedulingService::class)->schedule($order->items->sole(), [
        'resource_name' => "X-Ray Room (Hospital {$hospital->id})",
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], requestAs($scheduler));
}
