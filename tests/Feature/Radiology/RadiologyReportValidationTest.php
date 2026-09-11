<?php

use App\Models\Hospital;
use App\Models\RadiologyReport;
use App\Services\RadiologyExaminationService;

/**
 * A report can only ever be created for a COMPLETED examination (Part 6) —
 * every other examination status must be rejected, whether reached through
 * the HTTP layer (store()) or the service directly.
 */
test('a report cannot be created for a SCHEDULED examination', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = scheduledAppointmentFor($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);

    $this->actingAs($radiologist['user'])->post(route('radiology-results.store'), [
        'radiology_appointment_id' => $appointment->id,
    ])->assertSessionHasErrors('status');

    $this->assertDatabaseCount('radiology_reports', 0);
});

test('a report cannot be created for a READY examination', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);

    $this->actingAs($radiologist['user'])->post(route('radiology-results.store'), [
        'radiology_appointment_id' => $appointment->id,
    ])->assertSessionHasErrors('status');
});

test('a report cannot be created for an IN_PROGRESS examination', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');
    app(RadiologyExaminationService::class)->start($appointment, [], requestAs($technician));
    $radiologist = radiologistWithPractitioner($ctx['hospital']);

    $this->actingAs($radiologist['user'])->post(route('radiology-results.store'), [
        'radiology_appointment_id' => $appointment->id,
    ])->assertSessionHasErrors('status');
});

test('a report cannot be created for an ABORTED examination', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');
    $examService = app(RadiologyExaminationService::class);
    $examService->start($appointment, [], requestAs($technician));
    $appointment->refresh();
    $examService->abort($appointment, 'other', requestAs($technician));
    $radiologist = radiologistWithPractitioner($ctx['hospital']);

    $this->actingAs($radiologist['user'])->post(route('radiology-results.store'), [
        'radiology_appointment_id' => $appointment->id,
    ])->assertSessionHasErrors('status');

    $this->assertDatabaseCount('radiology_reports', 0);
});

test('store() rejects a non-existent radiology_appointment_id', function () {
    $radiologist = radiologistWithPractitioner(Hospital::factory()->create());
    seedRolesAndPermissions();

    $this->actingAs($radiologist['user'])->post(route('radiology-results.store'), [
        'radiology_appointment_id' => 999999,
    ])->assertSessionHasErrors('radiology_appointment_id');
});

test('submitting with findings but no impression reports only the impression error', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);

    $this->actingAs($radiologist['user'])->post(route('radiology-results.store'), [
        'radiology_appointment_id' => $appointment->id,
        'findings' => 'Findings only.',
    ])->assertRedirect();

    $report = RadiologyReport::query()->where('radiology_appointment_id', $appointment->id)->sole();

    $this->actingAs($radiologist['user'])->post(route('radiology-results.submit', $report))
        ->assertSessionHasErrors('impression')
        ->assertSessionDoesntHaveErrors('findings');
});

test('submitting with impression but no findings reports only the findings error', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);

    $this->actingAs($radiologist['user'])->post(route('radiology-results.store'), [
        'radiology_appointment_id' => $appointment->id,
        'impression' => 'Impression only.',
    ])->assertRedirect();

    $report = RadiologyReport::query()->where('radiology_appointment_id', $appointment->id)->sole();

    $this->actingAs($radiologist['user'])->post(route('radiology-results.submit', $report))
        ->assertSessionHasErrors('findings')
        ->assertSessionDoesntHaveErrors('impression');
});

test('the clinical indication cannot be changed through the report update endpoint', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $ctx['order']->update(['clinical_indication' => 'Persistent chest pain and cough.']);
    $appointment = radiologyExaminationCompleted($ctx);
    $radiologist = radiologistWithPractitioner($ctx['hospital']);
    $originalIndication = $ctx['order']->fresh()->clinical_indication;

    $this->actingAs($radiologist['user'])->post(route('radiology-results.store'), [
        'radiology_appointment_id' => $appointment->id,
    ])->assertRedirect();

    $report = RadiologyReport::query()->where('radiology_appointment_id', $appointment->id)->sole();

    $this->actingAs($radiologist['user'])->put(route('radiology-results.update', $report), [
        'findings' => 'x',
        'clinical_indication' => 'A completely different reason for the exam',
    ])->assertRedirect();

    expect($ctx['order']->fresh()->clinical_indication)->toBe($originalIndication);
});
