<?php

use App\Models\ClinicalEncounter;
use App\Models\Hospital;
use App\Models\Practitioner;
use App\Models\RadiologyAppointment;
use App\Models\RadiologyReport;
use App\Models\Staff;
use App\Models\User;
use App\Services\ConsultationService;
use App\Services\RadiologyExaminationService;
use App\Services\RadiologyOrderService;
use App\Services\RadiologyReceptionService;
use App\Services\RadiologyReportService;
use App\Services\RadiologySchedulingService;
use App\Services\TriageService;
use App\Services\VitalSignsService;

test('a guest is redirected to login for the results worklist and every mutation', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);
    $report = app(RadiologyReportService::class)->createDraft($appointment, [], requestAs($radiologist['user']));

    $this->get(route('radiology-results.index'))->assertRedirect(route('login'));
    $this->get(route('radiology-results.create', $appointment))->assertRedirect(route('login'));
    $this->post(route('radiology-results.store'))->assertRedirect(route('login'));
    $this->get(route('radiology-results.show', $report))->assertRedirect(route('login'));
    $this->put(route('radiology-results.update', $report))->assertRedirect(route('login'));
    $this->post(route('radiology-results.submit', $report))->assertRedirect(route('login'));
    $this->get(route('radiology-results.history', $report))->assertRedirect(route('login'));
});

test('a doctor cannot view or act on the results worklist', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);

    $this->actingAs($ctx['doctor']['user'])->get(route('radiology-results.index'))->assertForbidden();
    $this->actingAs($ctx['doctor']['user'])->get(route('radiology-results.create', $appointment))->assertForbidden();
    $this->actingAs($ctx['doctor']['user'])->post(route('radiology-results.store'), [
        'radiology_appointment_id' => $appointment->id,
    ])->assertForbidden();

    $this->assertDatabaseCount('radiology_reports', 0);
});

test('a radiology technician cannot create, update, or submit reports', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $technician = userWithRole('radiology-technician');

    $this->actingAs($technician)->post(route('radiology-results.store'), [
        'radiology_appointment_id' => $appointment->id,
    ])->assertForbidden();
});

test('a permission-less user cannot view, create, update, or submit reports', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);
    $report = app(RadiologyReportService::class)->createDraft($appointment, [], requestAs($radiologist['user']));
    seedRolesAndPermissions();
    $noPermissionUser = User::factory()->create();

    $this->actingAs($noPermissionUser)->get(route('radiology-results.index'))->assertForbidden();
    $this->actingAs($noPermissionUser)->get(route('radiology-results.show', $report))->assertForbidden();
    $this->actingAs($noPermissionUser)->put(route('radiology-results.update', $report))->assertForbidden();
    $this->actingAs($noPermissionUser)->post(route('radiology-results.submit', $report))->assertForbidden();
});

test('a radiologist can view the worklist and create, update, submit their own report', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);

    $this->actingAs($radiologist['user'])->get(route('radiology-results.index'))->assertOk();
    $this->actingAs($radiologist['user'])->get(route('radiology-results.create', $appointment))->assertOk();

    $this->actingAs($radiologist['user'])->post(route('radiology-results.store'), [
        'radiology_appointment_id' => $appointment->id,
    ])->assertRedirect();

    $report = RadiologyReport::query()->where('radiology_appointment_id', $appointment->id)->sole();

    $this->actingAs($radiologist['user'])->get(route('radiology-results.show', $report))->assertOk();

    $this->actingAs($radiologist['user'])->put(route('radiology-results.update', $report), [
        'findings' => 'Findings text.',
        'impression' => 'Impression text.',
    ])->assertRedirect();

    $this->actingAs($radiologist['user'])->post(route('radiology-results.submit', $report))->assertRedirect();

    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_SUBMITTED);
});

test('a different radiologist can view but not edit or submit another radiologist\'s draft', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $owner = radiologistWithPractitioner($ctx['hospital']);
    $report = app(RadiologyReportService::class)->createDraft($appointment, [], requestAs($owner['user']));

    $otherRadiologist = radiologistWithPractitioner($ctx['hospital']);

    $this->actingAs($otherRadiologist['user'])->get(route('radiology-results.show', $report))->assertOk();
    $this->actingAs($otherRadiologist['user'])->put(route('radiology-results.update', $report), ['findings' => 'x'])->assertForbidden();
    $this->actingAs($otherRadiologist['user'])->post(route('radiology-results.submit', $report))->assertForbidden();
});

test('a hospital-admin can act within their own hospital but not another', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $appointmentA = radiologyCompletedExaminationFor($hospitalA, 'X-Ray Room 1');
    $appointmentB = radiologyCompletedExaminationFor($hospitalB, 'X-Ray Room 2');

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    $adminAStaff = Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);
    // A hospital-admin authoring a report still needs a practitioner
    // profile of their own — the ability check (hospital-admin is
    // unrestricted) is separate from the domain requirement that every
    // report have a genuine clinical author (RadiologyReportService::
    // createDraft()).
    Practitioner::factory()->create(['staff_id' => $adminAStaff->id, 'practitioner_type' => 'radiologist']);

    $this->actingAs($adminA)->post(route('radiology-results.store'), [
        'radiology_appointment_id' => $appointmentA->id,
    ])->assertRedirect();

    $this->actingAs($adminA)->post(route('radiology-results.store'), [
        'radiology_appointment_id' => $appointmentB->id,
    ])->assertForbidden();

    $reportA = RadiologyReport::query()->where('radiology_appointment_id', $appointmentA->id)->sole();
    $this->actingAs($adminA)->put(route('radiology-results.update', $reportA), ['findings' => 'x'])->assertRedirect();

    // IDOR: hospital B's report, created by its own radiologist, must
    // never be reachable by hospital A's admin simply by changing the id
    // in the URL.
    $radiologistB = radiologistWithPractitioner($hospitalB);
    $reportB = app(RadiologyReportService::class)->createDraft($appointmentB, [], requestAs($radiologistB['user']));

    $this->actingAs($adminA)->get(route('radiology-results.show', $reportB))->assertForbidden();
});

test('a super-admin bypasses radiology results permission checks', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $user = userWithRole('super-admin');
    $staff = Staff::factory()->create(['user_id' => $user->id, 'hospital_id' => $ctx['hospital']->id]);
    Practitioner::factory()->create(['staff_id' => $staff->id, 'practitioner_type' => 'radiologist']);

    $this->actingAs($user)->post(route('radiology-results.store'), [
        'radiology_appointment_id' => $appointment->id,
    ])->assertRedirect();

    $this->assertDatabaseCount('radiology_reports', 1);
});

/**
 * Build a fully COMPLETED examination under a specific hospital — mirrors
 * radiologyReadyForExaminationFor() from RadiologyExaminationAuthorizationTest,
 * extended through examination start/complete.
 */
function radiologyCompletedExaminationFor(Hospital $hospital, string $resourceName = 'X-Ray Room 1'): RadiologyAppointment
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

    $examService = app(RadiologyExaminationService::class);
    $examService->start($appointment->fresh(), [], $technicianRequest);
    $examService->complete($appointment->fresh(), [], $technicianRequest);

    return $appointment->fresh();
}
