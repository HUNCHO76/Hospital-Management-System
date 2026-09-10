<?php

use App\Models\LaboratoryResult;
use App\Services\LaboratoryResultService;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency protection (spec §23): row-locking inside
 * DB::transaction() must prevent two racing verification actions from both
 * succeeding. Mirrors LaboratoryProcessingConcurrencyTest's repeated-
 * attempt style.
 */
test('only one of several back-to-back attempts to verify the same result succeeds', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');
    $request = requestAs($supervisor);
    $service = app(LaboratoryResultService::class);

    $service->startReview($ctx['result'], $request);

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->verifyResult($ctx['result']->fresh(), [], $request);
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
    expect($ctx['result']->fresh()->status)->toBe(LaboratoryResult::STATUS_VERIFIED);
});

test('only one of two verifiers starting review at once succeeds — the second sees a controlled rejection, not a silent overwrite', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'serum', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $verifierA = userWithRole('laboratory-supervisor');
    $verifierB = userWithRole('laboratory-supervisor');
    $service = app(LaboratoryResultService::class);

    $result = $service->startReview($ctx['result'], requestAs($verifierA));

    expect(fn () => $service->startReview($result->fresh(), requestAs($verifierB)))->toThrow(ValidationException::class);

    expect($ctx['result']->fresh()->reviewed_by)->toBe($verifierA->id);
});

test('only one of several back-to-back attempts to authorize the same result succeeds', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'plasma', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');
    $request = requestAs($supervisor);
    $service = app(LaboratoryResultService::class);

    $service->startReview($ctx['result'], $request);
    $service->verifyResult($ctx['result']->fresh(), [], $request);

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->authorizeResult($ctx['result']->fresh(), $request);
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
    expect($ctx['result']->fresh()->status)->toBe(LaboratoryResult::STATUS_AUTHORIZED);
});
