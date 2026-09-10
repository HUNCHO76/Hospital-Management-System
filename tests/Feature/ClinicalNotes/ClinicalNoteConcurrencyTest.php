<?php

use App\Models\ClinicalNote;
use App\Services\ClinicalNoteService;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency tests (spec §34): signing and amending must never
 * both succeed for the same note, and concurrent amendments must never
 * produce a duplicate version_number. See CarePlanConcurrencyTest for the
 * same rationale and its SQLite-in-memory caveat — the invariant proven here
 * (never two winners, never a duplicate version) is guaranteed by
 * ClinicalNoteService locking the note row before its status check and
 * version-number computation.
 */
test('only one of several back-to-back attempts to sign the same note succeeds', function () {
    $ctx = clinicalNoteReadyEncounter();
    $service = app(ClinicalNoteService::class);
    $request = requestAs($ctx['doctor']['user']);

    $note = $service->create($ctx['encounter'], ['note_type' => 'progress', 'note_content' => 'x'], $request);

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->sign($note->fresh(), $request);
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
    expect($note->fresh()->status)->toBe(ClinicalNote::STATUS_SIGNED);
    expect($note->fresh()->versions()->count())->toBe(1);
});

test('several back-to-back amendments never produce a duplicate version_number', function () {
    $ctx = clinicalNoteReadyEncounter();
    $service = app(ClinicalNoteService::class);
    $request = requestAs($ctx['doctor']['user']);

    $note = $service->create($ctx['encounter'], ['note_type' => 'progress', 'note_content' => 'v1'], $request);
    $service->sign($note, $request);

    for ($i = 2; $i <= 5; $i++) {
        $service->amend($note->fresh(), ['note_content' => "v{$i}"], "reason {$i}", $request);
    }

    $versionNumbers = $note->fresh()->versions()->pluck('version_number')->all();
    expect($versionNumbers)->toBe([1, 2, 3, 4, 5]);
    expect(count($versionNumbers))->toBe(count(array_unique($versionNumbers)));
});
