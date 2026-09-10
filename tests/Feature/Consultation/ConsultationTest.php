<?php

use App\Models\ClinicalEncounter;
use App\Models\Consultation;
use App\Services\ConsultationService;
use Illuminate\Validation\ValidationException;

test('starting a consultation creates a pending consultation tied to the encounter and practitioner', function () {
    $ctx = consultationReadyEncounter();
    $doctor = doctorWithPractitioner($ctx['hospital']);
    $service = app(ConsultationService::class);

    $consultation = $service->create($ctx['encounter'], requestAs($doctor['user']));

    expect($consultation->status)->toBe(Consultation::STATUS_PENDING);
    expect($consultation->clinical_encounter_id)->toBe($ctx['encounter']->id);
    expect($consultation->practitioner_id)->toBe($doctor['practitioner']->id);
    expect($consultation->created_by)->toBe($doctor['user']->id);
});

test('starting a consultation records an audit event', function () {
    $ctx = consultationReadyEncounter();
    $doctor = doctorWithPractitioner($ctx['hospital']);
    $service = app(ConsultationService::class);

    $consultation = $service->create($ctx['encounter'], requestAs($doctor['user']));

    $this->assertDatabaseHas('audit_logs', ['action' => 'consultation_created', 'auditable_id' => $consultation->id]);
});

test('a second consultation for the same encounter is rejected', function () {
    $ctx = consultationReadyEncounter();
    $doctor = doctorWithPractitioner($ctx['hospital']);
    $service = app(ConsultationService::class);
    $request = requestAs($doctor['user']);

    $service->create($ctx['encounter'], $request);

    expect(fn () => $service->create($ctx['encounter'], $request))->toThrow(ValidationException::class);
    expect(Consultation::query()->where('clinical_encounter_id', $ctx['encounter']->id)->count())->toBe(1);
});

test('a consultation cannot be started before triage is completed', function () {
    $ctx = clinicalEncounterContext();
    $encounter = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);
    $doctor = doctorWithPractitioner($ctx['hospital']);
    $service = app(ConsultationService::class);

    expect(fn () => $service->create($encounter, requestAs($doctor['user'])))->toThrow(ValidationException::class);
    expect(Consultation::query()->count())->toBe(0);
});

test('a consultation cannot be started against a cancelled encounter', function () {
    $ctx = consultationReadyEncounter();
    $ctx['encounter']->update(['status' => ClinicalEncounter::STATUS_CANCELLED]);
    $doctor = doctorWithPractitioner($ctx['hospital']);
    $service = app(ConsultationService::class);

    expect(fn () => $service->create($ctx['encounter'], requestAs($doctor['user'])))->toThrow(ValidationException::class);
    expect(Consultation::query()->count())->toBe(0);
});

test('a user without a practitioner profile cannot start a consultation', function () {
    $ctx = consultationReadyEncounter();
    $user = userWithRole('doctor');
    $service = app(ConsultationService::class);

    expect(fn () => $service->create($ctx['encounter'], requestAs($user)))->toThrow(ValidationException::class);
    expect(Consultation::query()->count())->toBe(0);
});

test('a non-clinical practitioner type cannot start a consultation', function () {
    $ctx = consultationReadyEncounter();
    $doctor = doctorWithPractitioner($ctx['hospital']);
    $doctor['practitioner']->update(['practitioner_type' => 'pharmacist']);
    $service = app(ConsultationService::class);

    expect(fn () => $service->create($ctx['encounter'], requestAs($doctor['user'])))->toThrow(ValidationException::class);
    expect(Consultation::query()->count())->toBe(0);
});

test('the full pending -> in_progress -> completed lifecycle works', function () {
    $ctx = consultationReadyEncounter();
    $doctor = doctorWithPractitioner($ctx['hospital']);
    $service = app(ConsultationService::class);
    $request = requestAs($doctor['user']);

    $consultation = $service->create($ctx['encounter'], $request);
    $started = $service->start($consultation, $request);
    expect($started->status)->toBe(Consultation::STATUS_IN_PROGRESS);
    expect($started->started_at)->not->toBeNull();

    $service->update($consultation, [
        'chief_complaint' => 'Persistent abdominal pain',
        'history_presenting_illness' => 'Three days of pain, worsening.',
        'assessment_summary' => 'Likely gastritis, stable.',
        'plan_summary' => 'Start antacids, review in 48 hours.',
    ], $request);

    $completed = $service->complete($consultation->fresh(), $request);
    expect($completed->status)->toBe(Consultation::STATUS_COMPLETED);
    expect($completed->completed_at)->not->toBeNull();
    expect($completed->completed_by)->toBe($doctor['user']->id);
});

test('a consultation cannot be completed without the required clinical fields', function () {
    $ctx = consultationReadyEncounter();
    $doctor = doctorWithPractitioner($ctx['hospital']);
    $service = app(ConsultationService::class);
    $request = requestAs($doctor['user']);

    $consultation = $service->create($ctx['encounter'], $request);
    $service->start($consultation, $request);

    expect(fn () => $service->complete($consultation->fresh(), $request))->toThrow(ValidationException::class);

    $service->update($consultation, ['chief_complaint' => 'Pain'], $request);
    expect(fn () => $service->complete($consultation->fresh(), $request))->toThrow(ValidationException::class);
});

test('pending consultation cannot jump straight to completed', function () {
    $ctx = consultationReadyEncounter();
    $doctor = doctorWithPractitioner($ctx['hospital']);
    $service = app(ConsultationService::class);
    $request = requestAs($doctor['user']);

    $consultation = $service->create($ctx['encounter'], $request);

    expect(fn () => $service->complete($consultation, $request))->toThrow(ValidationException::class);
});

test('a consultation can be cancelled from pending or in_progress', function () {
    $ctx = consultationReadyEncounter();
    $doctor = doctorWithPractitioner($ctx['hospital']);
    $service = app(ConsultationService::class);
    $request = requestAs($doctor['user']);

    $consultation = $service->create($ctx['encounter'], $request);
    $cancelled = $service->cancel($consultation, 'Patient left', $request);

    expect($cancelled->status)->toBe(Consultation::STATUS_CANCELLED);
    expect($cancelled->cancellation_reason)->toBe('Patient left');
});

test('a completed consultation cannot be modified, started, or re-completed', function () {
    $ctx = consultationReadyEncounter();
    $doctor = doctorWithPractitioner($ctx['hospital']);
    $service = app(ConsultationService::class);
    $request = requestAs($doctor['user']);

    $consultation = $service->create($ctx['encounter'], $request);
    $service->start($consultation, $request);
    $service->update($consultation, [
        'chief_complaint' => 'Pain',
        'history_presenting_illness' => 'Ongoing',
        'assessment_summary' => 'Stable',
        'plan_summary' => 'Review',
    ], $request);
    $consultation = $service->complete($consultation->fresh(), $request);

    expect(fn () => $service->update($consultation, ['clinical_notes' => 'late edit'], $request))->toThrow(ValidationException::class);
    expect(fn () => $service->start($consultation, $request))->toThrow(ValidationException::class);
    expect(fn () => $service->cancel($consultation, 'too late', $request))->toThrow(ValidationException::class);
});

test('a completed consultation can be corrected, stamping who/when/why', function () {
    $ctx = consultationReadyEncounter();
    $doctor = doctorWithPractitioner($ctx['hospital']);
    $service = app(ConsultationService::class);
    $request = requestAs($doctor['user']);

    $consultation = $service->create($ctx['encounter'], $request);
    $service->start($consultation, $request);
    $service->update($consultation, [
        'chief_complaint' => 'Pain',
        'history_presenting_illness' => 'Ongoing',
        'assessment_summary' => 'Stable',
        'plan_summary' => 'Review',
    ], $request);
    $consultation = $service->complete($consultation->fresh(), $request);

    $corrected = $service->correct($consultation, ['assessment_summary' => 'Revised assessment'], 'Typo in original assessment', $request);

    expect($corrected->assessment_summary)->toBe('Revised assessment');
    expect($corrected->corrected_by)->toBe($doctor['user']->id);
    expect($corrected->correction_reason)->toBe('Typo in original assessment');
    expect($corrected->corrected_at)->not->toBeNull();
    expect($corrected->status)->toBe(Consultation::STATUS_COMPLETED);

    $this->assertDatabaseHas('audit_logs', ['action' => 'consultation_corrected', 'auditable_id' => $consultation->id]);
});

test('an in_progress consultation cannot be corrected', function () {
    $ctx = consultationReadyEncounter();
    $doctor = doctorWithPractitioner($ctx['hospital']);
    $service = app(ConsultationService::class);
    $request = requestAs($doctor['user']);

    $consultation = $service->create($ctx['encounter'], $request);
    $service->start($consultation, $request);

    expect(fn () => $service->correct($consultation, ['clinical_notes' => 'x'], 'reason', $request))->toThrow(ValidationException::class);
});
