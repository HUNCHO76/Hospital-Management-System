<?php

use App\Models\AuditLog;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalNote;
use App\Services\ClinicalNoteService;
use Illuminate\Validation\ValidationException;

test('recording a note creates a draft tied to the encounter and patient', function () {
    $ctx = clinicalNoteReadyEncounter();
    $service = app(ClinicalNoteService::class);

    $note = $service->create($ctx['encounter'], ['note_type' => 'nursing', 'note_content' => 'Patient resting comfortably.'], requestAs($ctx['nurse']));

    expect($note->status)->toBe(ClinicalNote::STATUS_DRAFT);
    expect($note->clinical_encounter_id)->toBe($ctx['encounter']->id);
    expect($note->patient_id)->toBe($ctx['encounter']->patient_id);
    expect($note->author_id)->toBe($ctx['nurse']->id);
});

test('recording a note against a cancelled encounter is rejected', function () {
    $ctx = clinicalNoteReadyEncounter();
    $ctx['encounter']->update(['status' => ClinicalEncounter::STATUS_CANCELLED]);
    $service = app(ClinicalNoteService::class);

    expect(fn () => $service->create($ctx['encounter'], ['note_type' => 'nursing', 'note_content' => 'x'], requestAs($ctx['nurse'])))
        ->toThrow(ValidationException::class);
});

test('recording a note stores an audit event without leaking its content', function () {
    $ctx = clinicalNoteReadyEncounter();
    $service = app(ClinicalNoteService::class);

    $note = $service->create($ctx['encounter'], ['note_type' => 'progress', 'note_content' => 'Sensitive clinical detail'], requestAs($ctx['doctor']['user']));

    $log = AuditLog::query()->where('action', 'clinical_note_created')->where('auditable_id', $note->id)->firstOrFail();
    expect(json_encode($log->new_values))->not->toContain('Sensitive clinical detail');
});

test('a draft note can be updated, but a signed one cannot', function () {
    $ctx = clinicalNoteReadyEncounter();
    $service = app(ClinicalNoteService::class);
    $request = requestAs($ctx['doctor']['user']);

    $note = $service->create($ctx['encounter'], ['note_type' => 'progress', 'note_content' => 'Initial content'], $request);
    $updated = $service->update($note, ['note_type' => 'progress', 'note_content' => 'Revised content'], $request);
    expect($updated->note_content)->toBe('Revised content');

    $service->sign($note->fresh(), $request);

    expect(fn () => $service->update($note->fresh(), ['note_type' => 'progress', 'note_content' => 'too late'], $request))
        ->toThrow(ValidationException::class);
});

test('signing a draft note freezes it and creates version 1', function () {
    $ctx = clinicalNoteReadyEncounter();
    $service = app(ClinicalNoteService::class);
    $request = requestAs($ctx['doctor']['user']);

    $note = $service->create($ctx['encounter'], ['note_type' => 'progress', 'note_content' => 'Original content'], $request);
    $signed = $service->sign($note, $request);

    expect($signed->status)->toBe(ClinicalNote::STATUS_SIGNED);
    expect($signed->signed_at)->not->toBeNull();
    expect($signed->signed_by)->toBe($ctx['doctor']['user']->id);
    expect($signed->versions()->count())->toBe(1);
    expect($signed->versions()->first()->version_number)->toBe(1);
    expect($signed->versions()->first()->note_content)->toBe('Original content');
});

test('a note cannot be signed twice', function () {
    $ctx = clinicalNoteReadyEncounter();
    $service = app(ClinicalNoteService::class);
    $request = requestAs($ctx['doctor']['user']);

    $note = $service->create($ctx['encounter'], ['note_type' => 'progress', 'note_content' => 'x'], $request);
    $service->sign($note, $request);

    expect(fn () => $service->sign($note->fresh(), $request))->toThrow(ValidationException::class);
});

test('amending a signed note preserves the original as a version and updates the live content', function () {
    $ctx = clinicalNoteReadyEncounter();
    $service = app(ClinicalNoteService::class);
    $request = requestAs($ctx['doctor']['user']);

    $note = $service->create($ctx['encounter'], ['note_type' => 'progress', 'note_content' => 'Original content'], $request);
    $service->sign($note, $request);

    $amended = $service->amend($note->fresh(), ['note_content' => 'Corrected content'], 'Typo in original', $request);

    expect($amended->status)->toBe(ClinicalNote::STATUS_AMENDED);
    expect($amended->note_content)->toBe('Corrected content');
    expect($amended->correction_reason)->toBe('Typo in original');
    expect($amended->corrected_by)->toBe($ctx['doctor']['user']->id);

    expect($amended->versions()->count())->toBe(2);
    $versions = $amended->versions()->orderBy('version_number')->get();
    expect($versions[0]->version_number)->toBe(1);
    expect($versions[0]->note_content)->toBe('Original content');
    expect($versions[1]->version_number)->toBe(2);
    expect($versions[1]->note_content)->toBe('Corrected content');
    expect($versions[1]->reason)->toBe('Typo in original');
});

test('a note can be amended multiple times, producing sequential versions', function () {
    $ctx = clinicalNoteReadyEncounter();
    $service = app(ClinicalNoteService::class);
    $request = requestAs($ctx['doctor']['user']);

    $note = $service->create($ctx['encounter'], ['note_type' => 'progress', 'note_content' => 'v1'], $request);
    $service->sign($note, $request);
    $service->amend($note->fresh(), ['note_content' => 'v2'], 'reason 1', $request);
    $note = $service->amend($note->fresh(), ['note_content' => 'v3'], 'reason 2', $request);

    expect($note->versions()->count())->toBe(3);
    expect($note->versions()->pluck('version_number')->all())->toBe([1, 2, 3]);
});

test('a draft note cannot be amended', function () {
    $ctx = clinicalNoteReadyEncounter();
    $service = app(ClinicalNoteService::class);
    $request = requestAs($ctx['doctor']['user']);

    $note = $service->create($ctx['encounter'], ['note_type' => 'progress', 'note_content' => 'x'], $request);

    expect(fn () => $service->amend($note, ['note_content' => 'y'], 'reason', $request))->toThrow(ValidationException::class);
});

test('a draft note can be cancelled, but a signed note cannot', function () {
    $ctx = clinicalNoteReadyEncounter();
    $service = app(ClinicalNoteService::class);
    $request = requestAs($ctx['doctor']['user']);

    $draft = $service->create($ctx['encounter'], ['note_type' => 'progress', 'note_content' => 'x'], $request);
    $cancelled = $service->cancel($draft, 'Recorded against wrong encounter', $request);
    expect($cancelled->status)->toBe(ClinicalNote::STATUS_CANCELLED);

    $signedNote = $service->create($ctx['encounter'], ['note_type' => 'progress', 'note_content' => 'y'], $request);
    $service->sign($signedNote, $request);

    expect(fn () => $service->cancel($signedNote->fresh(), 'too late', $request))->toThrow(ValidationException::class);
});
