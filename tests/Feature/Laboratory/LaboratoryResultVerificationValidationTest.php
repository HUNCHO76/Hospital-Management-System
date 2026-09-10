<?php

use App\Models\LaboratoryResult;
use App\Services\LaboratoryResultService;
use Illuminate\Validation\ValidationException;

test('returning a result without a reason is rejected over HTTP', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');

    $this->actingAs($supervisor)->post(route('laboratory.results.review', $ctx['result']))->assertRedirect();

    $this->actingAs($supervisor)
        ->post(route('laboratory.results.return', $ctx['result']->fresh()), [])
        ->assertSessionHasErrors('return_reason');
});

test('returning a result with an invalid reason is rejected over HTTP', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'serum', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');

    $this->actingAs($supervisor)->post(route('laboratory.results.review', $ctx['result']))->assertRedirect();

    $this->actingAs($supervisor)
        ->post(route('laboratory.results.return', $ctx['result']->fresh()), ['return_reason' => 'not-a-real-reason'])
        ->assertSessionHasErrors('return_reason');
});

test('returning a result with reason "other" requires notes', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'plasma', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');

    $this->actingAs($supervisor)->post(route('laboratory.results.review', $ctx['result']))->assertRedirect();

    $this->actingAs($supervisor)
        ->post(route('laboratory.results.return', $ctx['result']->fresh()), ['return_reason' => 'other'])
        ->assertSessionHasErrors('return_notes');
});

test('a controlled amendment of a verified result requires a reason over HTTP', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'csf', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');

    $this->actingAs($supervisor)->post(route('laboratory.results.review', $ctx['result']))->assertRedirect();
    $this->actingAs($supervisor)->post(route('laboratory.results.verify', $ctx['result']->fresh()))->assertRedirect();

    $this->actingAs($supervisor)
        ->post(route('laboratory.results.amend', $ctx['result']->fresh()), ['value_numeric' => '20.0'])
        ->assertSessionHasErrors('amendment_reason');
});

test('an invalid verification comment length is rejected over HTTP', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'sputum', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');

    $this->actingAs($supervisor)->post(route('laboratory.results.review', $ctx['result']))->assertRedirect();

    $this->actingAs($supervisor)
        ->post(route('laboratory.results.verify', $ctx['result']->fresh()), ['verification_comment' => str_repeat('a', 1001)])
        ->assertSessionHasErrors('verification_comment');
});

test('every invalid verification status transition is rejected by the service regardless of frontend state', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'urine', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');
    $request = requestAs($supervisor);
    $service = app(LaboratoryResultService::class);

    // PENDING_VERIFICATION -> VERIFIED (skipping review) is never valid.
    expect(fn () => $service->verifyResult($ctx['result'], [], $request))->toThrow(ValidationException::class);

    // PENDING_VERIFICATION -> RETURNED_FOR_CORRECTION (skipping review) is never valid.
    expect(fn () => $service->returnForCorrection($ctx['result'], ['return_reason' => 'other'], $request))->toThrow(ValidationException::class);

    $result = $service->startReview($ctx['result'], $request);

    // UNDER_REVIEW -> AUTHORIZED (skipping verify) is never valid.
    expect(fn () => $service->authorizeResult($result, $request))->toThrow(ValidationException::class);

    // UNDER_REVIEW -> UNDER_REVIEW (double review-start) is never valid.
    expect(fn () => $service->startReview($result, $request))->toThrow(ValidationException::class);

    $result = $service->verifyResult($result, [], $request);

    // VERIFIED -> VERIFIED (double verification) is never valid.
    expect(fn () => $service->verifyResult($result, [], $request))->toThrow(ValidationException::class);

    // VERIFIED -> RETURNED_FOR_CORRECTION is never valid — only UNDER_REVIEW can be returned.
    expect(fn () => $service->returnForCorrection($result, ['return_reason' => 'other'], $request))->toThrow(ValidationException::class);

    $result = $service->authorizeResult($result, $request);

    // AUTHORIZED -> AUTHORIZED (double authorization) is never valid.
    expect(fn () => $service->authorizeResult($result, $request))->toThrow(ValidationException::class);
});

test('a result not yet under review cannot be returned for correction', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'body_fluid', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');
    $request = requestAs($supervisor);

    expect(fn () => app(LaboratoryResultService::class)->returnForCorrection($ctx['result'], ['return_reason' => 'incorrect_value'], $request))
        ->toThrow(ValidationException::class);

    expect($ctx['result']->fresh()->status)->toBe(LaboratoryResult::STATUS_PENDING_VERIFICATION);
});
