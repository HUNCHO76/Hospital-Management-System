<?php

use App\Models\ClinicalEncounter;
use App\Models\EncounterDiagnosis;
use App\Services\DiagnosisService;
use Illuminate\Validation\ValidationException;

test('recording a diagnosis creates a provisional diagnosis tied to the encounter and consultation', function () {
    $ctx = diagnosisReadyEncounter();
    $code = activeDiagnosisCode();
    $service = app(DiagnosisService::class);

    $diagnosis = $service->create($ctx['encounter'], [
        'diagnosis_code_id' => $code->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
        'clinical_note' => 'Consistent with clinical findings.',
    ], requestAs($ctx['doctor']['user']));

    expect($diagnosis->status)->toBe(EncounterDiagnosis::STATUS_PROVISIONAL);
    expect($diagnosis->clinical_encounter_id)->toBe($ctx['encounter']->id);
    expect($diagnosis->consultation_id)->toBe($ctx['encounter']->fresh()->consultation->id);
    expect($diagnosis->diagnosis_code_id)->toBe($code->id);
    expect($diagnosis->diagnosed_by)->toBe($ctx['doctor']['user']->id);
});

test('recording a diagnosis records an audit event', function () {
    $ctx = diagnosisReadyEncounter();
    $code = activeDiagnosisCode();
    $service = app(DiagnosisService::class);

    $diagnosis = $service->create($ctx['encounter'], [
        'diagnosis_code_id' => $code->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
    ], requestAs($ctx['doctor']['user']));

    $this->assertDatabaseHas('audit_logs', ['action' => 'diagnosis_created', 'auditable_id' => $diagnosis->id]);
});

test('a second active primary diagnosis for the same encounter is rejected at creation', function () {
    $ctx = diagnosisReadyEncounter();
    $service = app(DiagnosisService::class);
    $request = requestAs($ctx['doctor']['user']);

    $service->create($ctx['encounter'], [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
    ], $request);

    expect(fn () => $service->create($ctx['encounter'], [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
    ], $request))->toThrow(ValidationException::class);

    expect(EncounterDiagnosis::query()->where('clinical_encounter_id', $ctx['encounter']->id)->count())->toBe(1);
});

test('a diagnosis cannot be recorded before the consultation is engaged', function () {
    $ctx = consultationReadyEncounter();
    $doctor = doctorWithPractitioner($ctx['hospital']);
    $service = app(DiagnosisService::class);

    expect(fn () => $service->create($ctx['encounter'], [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
    ], requestAs($doctor['user'])))->toThrow(ValidationException::class);

    expect(EncounterDiagnosis::query()->count())->toBe(0);
});

test('a diagnosis cannot be recorded against a cancelled encounter', function () {
    $ctx = diagnosisReadyEncounter();
    $ctx['encounter']->update(['status' => ClinicalEncounter::STATUS_CANCELLED]);
    $service = app(DiagnosisService::class);

    expect(fn () => $service->create($ctx['encounter'], [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
    ], requestAs($ctx['doctor']['user'])))->toThrow(ValidationException::class);
});

test('a provisional diagnosis can be updated, but a confirmed one cannot', function () {
    $ctx = diagnosisReadyEncounter();
    $service = app(DiagnosisService::class);
    $request = requestAs($ctx['doctor']['user']);

    $diagnosis = $service->create($ctx['encounter'], [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
    ], $request);

    $updated = $service->update($diagnosis, ['clinical_note' => 'Updated note'], $request);
    expect($updated->clinical_note)->toBe('Updated note');

    $service->changeStatus($diagnosis->fresh(), EncounterDiagnosis::STATUS_CONFIRMED, $request);

    expect(fn () => $service->update($diagnosis->fresh(), ['clinical_note' => 'too late'], $request))->toThrow(ValidationException::class);
});

test('the full provisional -> confirmed -> resolved lifecycle works', function () {
    $ctx = diagnosisReadyEncounter();
    $service = app(DiagnosisService::class);
    $request = requestAs($ctx['doctor']['user']);

    $diagnosis = $service->create($ctx['encounter'], [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
    ], $request);

    $confirmed = $service->changeStatus($diagnosis, EncounterDiagnosis::STATUS_CONFIRMED, $request);
    expect($confirmed->status)->toBe(EncounterDiagnosis::STATUS_CONFIRMED);

    $resolved = $service->changeStatus($confirmed->fresh(), EncounterDiagnosis::STATUS_RESOLVED, $request);
    expect($resolved->status)->toBe(EncounterDiagnosis::STATUS_RESOLVED);
});

test('provisional cannot jump straight to resolved', function () {
    $ctx = diagnosisReadyEncounter();
    $service = app(DiagnosisService::class);
    $request = requestAs($ctx['doctor']['user']);

    $diagnosis = $service->create($ctx['encounter'], [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
    ], $request);

    expect(fn () => $service->changeStatus($diagnosis, EncounterDiagnosis::STATUS_RESOLVED, $request))->toThrow(ValidationException::class);
});

test('cancellation is rejected through changeStatus and must use the dedicated cancel action', function () {
    $ctx = diagnosisReadyEncounter();
    $service = app(DiagnosisService::class);
    $request = requestAs($ctx['doctor']['user']);

    $diagnosis = $service->create($ctx['encounter'], [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
    ], $request);

    expect(fn () => $service->changeStatus($diagnosis, EncounterDiagnosis::STATUS_CANCELLED, $request))->toThrow(ValidationException::class);

    $cancelled = $service->cancel($diagnosis, 'Recorded in error', $request);
    expect($cancelled->status)->toBe(EncounterDiagnosis::STATUS_CANCELLED);
    expect($cancelled->cancellation_reason)->toBe('Recorded in error');
});

test('a resolved or cancelled diagnosis cannot be cancelled again', function () {
    $ctx = diagnosisReadyEncounter();
    $service = app(DiagnosisService::class);
    $request = requestAs($ctx['doctor']['user']);

    $diagnosis = $service->create($ctx['encounter'], [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
    ], $request);
    $service->cancel($diagnosis, 'first cancel', $request);

    expect(fn () => $service->cancel($diagnosis->fresh(), 'second cancel', $request))->toThrow(ValidationException::class);
});

test('only a confirmed or resolved diagnosis can be corrected', function () {
    $ctx = diagnosisReadyEncounter();
    $service = app(DiagnosisService::class);
    $request = requestAs($ctx['doctor']['user']);

    $diagnosis = $service->create($ctx['encounter'], [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
    ], $request);

    expect(fn () => $service->correct($diagnosis, ['clinical_note' => 'x'], 'reason', $request))->toThrow(ValidationException::class);

    $service->changeStatus($diagnosis->fresh(), EncounterDiagnosis::STATUS_CONFIRMED, $request);
    $corrected = $service->correct($diagnosis->fresh(), ['clinical_note' => 'Amended finding'], 'Typo in original note', $request);

    expect($corrected->clinical_note)->toBe('Amended finding');
    expect($corrected->corrected_by)->toBe($ctx['doctor']['user']->id);
    expect($corrected->correction_reason)->toBe('Typo in original note');

    $this->assertDatabaseHas('audit_logs', ['action' => 'diagnosis_corrected', 'auditable_id' => $diagnosis->id]);
});

test('setPrimary promotes a secondary diagnosis and demotes the existing primary', function () {
    $ctx = diagnosisReadyEncounter();
    $service = app(DiagnosisService::class);
    $request = requestAs($ctx['doctor']['user']);

    $primary = $service->create($ctx['encounter'], [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
    ], $request);

    $secondary = $service->create($ctx['encounter'], [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_SECONDARY,
    ], $request);

    $promoted = $service->setPrimary($secondary, $request);

    expect($promoted->fresh()->diagnosis_type)->toBe(EncounterDiagnosis::TYPE_PRIMARY);
    expect($primary->fresh()->diagnosis_type)->toBe(EncounterDiagnosis::TYPE_SECONDARY);

    $this->assertDatabaseHas('audit_logs', ['action' => 'diagnosis_set_primary', 'auditable_id' => $primary->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'diagnosis_set_primary', 'auditable_id' => $secondary->id]);
});

test('a cancelled diagnosis cannot be promoted to primary', function () {
    $ctx = diagnosisReadyEncounter();
    $service = app(DiagnosisService::class);
    $request = requestAs($ctx['doctor']['user']);

    $diagnosis = $service->create($ctx['encounter'], [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_SECONDARY,
    ], $request);
    $service->cancel($diagnosis, 'no longer relevant', $request);

    expect(fn () => $service->setPrimary($diagnosis->fresh(), $request))->toThrow(ValidationException::class);
});

test('recording a diagnosis with only NHIF disease code automatically resolves or creates diagnosis code', function () {
    $ctx = diagnosisReadyEncounter();
    $service = app(DiagnosisService::class);

    $diagnosis = $service->create($ctx['encounter'], [
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
        'nhif_disease_code' => 'A01.0',
        'nhif_disease_name' => 'Typhoid fever',
        'nhif_icd_version_code' => 'ICD10',
    ], requestAs($ctx['doctor']['user']));

    expect($diagnosis->status)->toBe(EncounterDiagnosis::STATUS_PROVISIONAL);
    expect($diagnosis->nhif_disease_code)->toBe('A01.0');
    expect($diagnosis->nhif_disease_name)->toBe('Typhoid fever');
    expect($diagnosis->diagnosis_code_id)->not->toBeNull();
    expect($diagnosis->diagnosis_code->code)->toBe('A01.0');
});

