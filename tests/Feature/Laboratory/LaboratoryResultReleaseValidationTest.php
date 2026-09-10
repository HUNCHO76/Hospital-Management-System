<?php

use App\Models\LaboratoryResult;
use App\Services\LaboratoryResultService;
use Illuminate\Validation\ValidationException;

test('draft cannot be released — release is only reachable from authorized', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $service = app(LaboratoryResultService::class);
    $result = $service->saveDraft($ctx['result'], ['value_numeric' => '13.5'], requestAs($ctx['doctor']['user']));

    expect(fn () => $service->release($result, [], requestAs($ctx['doctor']['user'])))->toThrow(ValidationException::class);
});

test('pending_verification cannot be released', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'serum', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');

    expect(fn () => app(LaboratoryResultService::class)->release($ctx['result'], [], requestAs($supervisor)))
        ->toThrow(ValidationException::class);
});

test('under_review cannot be released', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'plasma', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');
    $result = app(LaboratoryResultService::class)->startReview($ctx['result'], requestAs($supervisor));

    expect(fn () => app(LaboratoryResultService::class)->release($result, [], requestAs($supervisor)))
        ->toThrow(ValidationException::class);
});

test('verified but not yet authorized cannot be released', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'csf', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');
    $service = app(LaboratoryResultService::class);
    $result = $service->startReview($ctx['result'], requestAs($supervisor));
    $result = $service->verifyResult($result, [], requestAs($supervisor));

    expect(fn () => $service->release($result, [], requestAs($supervisor)))->toThrow(ValidationException::class);
});

test('authorized can be released', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'sputum', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');
    $service = app(LaboratoryResultService::class);
    $result = $service->startReview($ctx['result'], requestAs($supervisor));
    $result = $service->verifyResult($result, [], requestAs($supervisor));
    $result = $service->authorizeResult($result, requestAs($supervisor));

    $result = $service->release($result, [], requestAs($supervisor));

    expect($result->status)->toBe(LaboratoryResult::STATUS_RELEASED);
});

test('a release comment exceeding the maximum length is rejected over HTTP', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'urine', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');
    $service = app(LaboratoryResultService::class);
    $result = $service->startReview($ctx['result'], requestAs($supervisor));
    $result = $service->verifyResult($result, [], requestAs($supervisor));
    $result = $service->authorizeResult($result, requestAs($supervisor));

    $this->actingAs($supervisor)
        ->post(route('laboratory.results.release', $result), ['release_comment' => str_repeat('a', 1001)])
        ->assertSessionHasErrors('release_comment');
});
