<?php

use App\Models\ClinicalEncounter;
use App\Models\ClinicalNote;
use App\Models\Hospital;
use App\Models\Practitioner;
use App\Models\Staff;
use App\Models\User;
use App\Services\ClinicalNoteService;

test('a guest is redirected to login for every clinical note route', function () {
    $ctx = clinicalNoteReadyEncounter();

    $this->post(route('clinical-encounters.clinical-notes.store', $ctx['encounter']))->assertRedirect(route('login'));
});

test('a receptionist cannot create, view-gate, or act on clinical notes despite reception access to the encounter', function () {
    $ctx = clinicalNoteReadyEncounter();
    $receptionist = userWithRole('receptionist');

    $this->actingAs($receptionist)->post(route('clinical-encounters.clinical-notes.store', $ctx['encounter']), [
        'note_type' => 'nursing',
        'note_content' => 'Should not be allowed',
    ])->assertForbidden();

    $this->assertDatabaseCount('clinical_notes', 0);
});

test('a doctor can create and sign their own clinical note over HTTP', function () {
    $ctx = clinicalNoteReadyEncounter();

    $this->actingAs($ctx['doctor']['user'])->post(route('clinical-encounters.clinical-notes.store', $ctx['encounter']), [
        'note_type' => 'progress',
        'note_content' => 'Patient stable',
    ])->assertRedirect();

    $note = ClinicalNote::query()->where('clinical_encounter_id', $ctx['encounter']->id)->firstOrFail();

    $this->actingAs($ctx['doctor']['user'])
        ->post(route('clinical-encounters.clinical-notes.sign', [$ctx['encounter'], $note]))
        ->assertRedirect();

    expect($note->fresh()->status)->toBe(ClinicalNote::STATUS_SIGNED);
});

test('a doctor cannot amend or sign another author\'s note', function () {
    $ctx = clinicalNoteReadyEncounter();
    $service = app(ClinicalNoteService::class);

    $note = $service->create($ctx['encounter'], ['note_type' => 'nursing', 'note_content' => 'x'], requestAs($ctx['nurse']));
    $service->sign($note, requestAs($ctx['nurse']));

    $this->actingAs($ctx['doctor']['user'])
        ->post(route('clinical-encounters.clinical-notes.amend', [$ctx['encounter'], $note]), [
            'note_content' => 'hijacked',
            'amendment_reason' => 'attempt',
        ])
        ->assertForbidden();

    expect($note->fresh()->note_content)->toBe('x');
});

test('a doctor cannot update another author\'s draft note', function () {
    $ctx = clinicalNoteReadyEncounter();
    $service = app(ClinicalNoteService::class);

    $note = $service->create($ctx['encounter'], ['note_type' => 'nursing', 'note_content' => 'x'], requestAs($ctx['nurse']));

    $this->actingAs($ctx['doctor']['user'])
        ->put(route('clinical-encounters.clinical-notes.update', [$ctx['encounter'], $note]), [
            'note_type' => 'nursing',
            'note_content' => 'hijacked',
        ])
        ->assertForbidden();

    expect($note->fresh()->note_content)->toBe('x');
});

test('a hospital-admin can amend a note within their own hospital but not another', function () {
    seedRolesAndPermissions();
    $service = app(ClinicalNoteService::class);

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $ctxA = clinicalNoteReadyEncounterFor($hospitalA);
    $ctxB = clinicalNoteReadyEncounterFor($hospitalB);

    $noteA = $service->create($ctxA['encounter'], ['note_type' => 'progress', 'note_content' => 'a'], requestAs($ctxA['doctor']['user']));
    $service->sign($noteA, requestAs($ctxA['doctor']['user']));

    $noteB = $service->create($ctxB['encounter'], ['note_type' => 'progress', 'note_content' => 'b'], requestAs($ctxB['doctor']['user']));
    $service->sign($noteB, requestAs($ctxB['doctor']['user']));

    $adminUser = User::factory()->create();
    $adminUser->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminUser->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminUser)
        ->post(route('clinical-encounters.clinical-notes.amend', [$ctxA['encounter'], $noteA]), [
            'note_content' => 'admin fix',
            'amendment_reason' => 'supervisory correction',
        ])
        ->assertRedirect();

    $this->actingAs($adminUser)
        ->post(route('clinical-encounters.clinical-notes.amend', [$ctxB['encounter'], $noteB]), [
            'note_content' => 'cross-hospital attempt',
            'amendment_reason' => 'x',
        ])
        ->assertForbidden();

    expect($noteA->fresh()->note_content)->toBe('admin fix');
    expect($noteB->fresh()->note_content)->toBe('b');
});

test('a clinical note cannot be accessed through a mismatched encounter id in the URL', function () {
    $ctxA = clinicalNoteReadyEncounter();
    $ctxB = clinicalNoteReadyEncounter();
    $service = app(ClinicalNoteService::class);

    $note = $service->create($ctxA['encounter'], ['note_type' => 'progress', 'note_content' => 'x'], requestAs($ctxA['doctor']['user']));

    $this->actingAs($ctxA['doctor']['user'])
        ->put(route('clinical-encounters.clinical-notes.update', [$ctxB['encounter'], $note]), [
            'note_type' => 'progress',
            'note_content' => 'y',
        ])
        ->assertNotFound();
});

test('mass-assignable protected fields cannot be set through the store request', function () {
    $ctx = clinicalNoteReadyEncounter();

    $this->actingAs($ctx['doctor']['user'])->post(route('clinical-encounters.clinical-notes.store', $ctx['encounter']), [
        'note_type' => 'progress',
        'note_content' => 'Legitimate content',
        'status' => ClinicalNote::STATUS_SIGNED,
        'author_id' => 99999,
        'patient_id' => 99999,
    ])->assertRedirect();

    $note = ClinicalNote::query()->where('clinical_encounter_id', $ctx['encounter']->id)->firstOrFail();
    expect($note->status)->toBe(ClinicalNote::STATUS_DRAFT);
    expect($note->author_id)->toBe($ctx['doctor']['user']->id);
    expect($note->patient_id)->toBe($ctx['encounter']->patient_id);
});

test('a super-admin bypasses clinical note permission and ownership checks', function () {
    $ctx = clinicalNoteReadyEncounter();
    $user = userWithRole('super-admin');

    $this->actingAs($user)->post(route('clinical-encounters.clinical-notes.store', $ctx['encounter']), [
        'note_type' => 'progress',
        'note_content' => 'x',
    ])->assertRedirect();

    $this->assertDatabaseCount('clinical_notes', 1);
});

/**
 * Build a clinical-note-ready encounter under a specific hospital.
 *
 * @return array{encounter: ClinicalEncounter, doctor: array{user: User, staff: Staff, practitioner: Practitioner}, nurse: User}
 */
function clinicalNoteReadyEncounterFor(Hospital $hospital): array
{
    $ctx = clinicalEncounterContext($hospital);
    $encounter = ClinicalEncounter::factory()->create(['department_id' => $ctx['department']->id, 'patient_id' => $ctx['patient']->id]);
    $doctor = doctorWithPractitioner($hospital);

    return [
        'encounter' => $encounter->fresh(),
        'doctor' => $doctor,
        'nurse' => userWithRole('nurse'),
    ];
}
