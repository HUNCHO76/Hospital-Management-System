<?php

use App\Models\ClinicalEncounter;
use App\Models\Hospital;
use App\Models\RadiologyAppointment;
use App\Models\Staff;
use App\Models\User;
use App\Services\ConsultationService;
use App\Services\RadiologyOrderService;
use App\Services\RadiologyReceptionService;
use App\Services\RadiologySchedulingService;
use App\Services\TriageService;
use App\Services\VitalSignsService;

test('a guest is redirected to login for the examination worklist and every mutation', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);

    $this->get(route('radiology-examinations.index'))->assertRedirect(route('login'));
    $this->get(route('radiology-examinations.show', $appointment))->assertRedirect(route('login'));
    $this->post(route('radiology-examinations.start', $appointment))->assertRedirect(route('login'));
    $this->put(route('radiology-examinations.update', $appointment))->assertRedirect(route('login'));
    $this->post(route('radiology-examinations.complete', $appointment))->assertRedirect(route('login'));
    $this->post(route('radiology-examinations.abort', $appointment))->assertRedirect(route('login'));
});

test('a doctor cannot view or act on the examination worklist', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);

    $this->actingAs($ctx['doctor']['user'])->get(route('radiology-examinations.index'))->assertForbidden();
    $this->actingAs($ctx['doctor']['user'])->post(route('radiology-examinations.start', $appointment))->assertForbidden();
    $this->actingAs($ctx['doctor']['user'])->post(route('radiology-examinations.complete', $appointment))->assertForbidden();
    $this->actingAs($ctx['doctor']['user'])->post(route('radiology-examinations.abort', $appointment), ['reason' => 'other'])->assertForbidden();

    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_READY);
});

test('a receptionist cannot view or act on the examination worklist', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $receptionist = userWithRole('receptionist');

    $this->actingAs($receptionist)->get(route('radiology-examinations.index'))->assertForbidden();
    $this->actingAs($receptionist)->post(route('radiology-examinations.start', $appointment))->assertForbidden();
});

test('a permission-less user cannot start, update, complete, or abort an examination', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    seedRolesAndPermissions();
    $noPermissionUser = User::factory()->create();

    $this->actingAs($noPermissionUser)->post(route('radiology-examinations.start', $appointment))->assertForbidden();
    $this->actingAs($noPermissionUser)->put(route('radiology-examinations.update', $appointment))->assertForbidden();
    $this->actingAs($noPermissionUser)->post(route('radiology-examinations.complete', $appointment))->assertForbidden();
    $this->actingAs($noPermissionUser)->post(route('radiology-examinations.abort', $appointment), ['reason' => 'other'])->assertForbidden();
});

test('a radiology technician can view the worklist and start, update, complete an examination', function () {
    $ctx = radiologyOrderReadyForScheduling(['modality' => 'x_ray']);
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');

    $this->actingAs($technician)->get(route('radiology-examinations.index'))->assertOk();
    $this->actingAs($technician)->get(route('radiology-examinations.show', $appointment))->assertOk();

    $this->actingAs($technician)->post(route('radiology-examinations.start', $appointment))->assertRedirect();
    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_IN_PROGRESS);

    $this->actingAs($technician)->put(route('radiology-examinations.update', $appointment), [
        'technical_notes' => 'Cooperative patient.',
        'technical_details' => ['view' => 'PA'],
    ])->assertRedirect();

    $this->actingAs($technician)->post(route('radiology-examinations.complete', $appointment))->assertRedirect();
    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_COMPLETED);
});

test('a radiology technician can abort an in-progress examination with a reason', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');

    $this->actingAs($technician)->post(route('radiology-examinations.start', $appointment))->assertRedirect();

    $this->actingAs($technician)->post(route('radiology-examinations.abort', $appointment), [
        'reason' => 'equipment_problem',
    ])->assertRedirect();

    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_ABORTED);
    expect($appointment->fresh()->abort_reason)->toBe('equipment_problem');
});

test('a hospital-admin can act within their own hospital but not another', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $appointmentA = radiologyReadyForExaminationFor($hospitalA, 'X-Ray Room 1');
    $appointmentB = radiologyReadyForExaminationFor($hospitalB, 'X-Ray Room 2');

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->post(route('radiology-examinations.start', $appointmentA))->assertRedirect();
    expect($appointmentA->fresh()->status)->toBe(RadiologyAppointment::STATUS_IN_PROGRESS);

    $this->actingAs($adminA)->post(route('radiology-examinations.start', $appointmentB))->assertForbidden();
    $this->actingAs($adminA)->get(route('radiology-examinations.show', $appointmentB))->assertForbidden();

    expect($appointmentB->fresh()->status)->toBe(RadiologyAppointment::STATUS_READY);
});

test('a super-admin bypasses radiology examination permission checks', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $user = userWithRole('super-admin');

    $this->actingAs($user)->post(route('radiology-examinations.start', $appointment))->assertRedirect();
    expect($appointment->fresh()->status)->toBe(RadiologyAppointment::STATUS_IN_PROGRESS);
});

/**
 * Build a fully READY-for-examination appointment under a specific
 * hospital — mirrors radiologyReadyItemFor() from
 * RadiologySchedulingAuthorizationTest, extended through scheduling and the
 * full reception pipeline.
 */
function radiologyReadyForExaminationFor(Hospital $hospital, string $resourceName = 'X-Ray Room 1'): RadiologyAppointment
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

    $procedure = activeRadiologyProcedure(['modality' => 'x_ray']);
    $order = app(RadiologyOrderService::class)->create($encounter->fresh(), ['procedure_ids' => [$procedure->id]], $consultationRequest);
    $item = $order->items->sole();

    $technician = userWithRole('radiology-technician');
    Staff::factory()->create(['user_id' => $technician->id, 'hospital_id' => $hospital->id]);
    $technicianRequest = requestAs($technician);

    $appointment = app(RadiologySchedulingService::class)->schedule($item, [
        'resource_name' => $resourceName,
        'scheduled_start' => now()->addDay()->setTime(9, 0),
        'scheduled_end' => now()->addDay()->setTime(9, 30),
    ], $technicianRequest);

    $reception = app(RadiologyReceptionService::class);
    $reception->markArrived($appointment, $technicianRequest);
    $reception->verify($appointment, $technicianRequest);
    $reception->markReady($appointment->fresh(), $technicianRequest);

    return $appointment->fresh();
}
