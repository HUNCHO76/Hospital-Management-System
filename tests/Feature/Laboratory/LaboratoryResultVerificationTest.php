<?php

use App\Models\AuditLog;
use App\Models\LaboratoryResult;
use App\Services\LaboratoryResultService;
use Illuminate\Validation\ValidationException;

test('a submitted result can be reviewed, verified, and authorized in sequence', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');
    $request = requestAs($supervisor);
    $service = app(LaboratoryResultService::class);

    $result = $service->startReview($ctx['result'], $request);
    expect($result->status)->toBe(LaboratoryResult::STATUS_UNDER_REVIEW);
    expect($result->reviewed_by)->toBe($supervisor->id);
    expect($result->reviewed_at)->not->toBeNull();

    $result = $service->verifyResult($result, ['verification_comment' => 'Looks correct'], $request);
    expect($result->status)->toBe(LaboratoryResult::STATUS_VERIFIED);
    expect($result->verified_by)->toBe($supervisor->id);
    expect($result->verified_at)->not->toBeNull();
    expect($result->verification_comment)->toBe('Looks correct');

    $result = $service->authorizeResult($result, $request);
    expect($result->status)->toBe(LaboratoryResult::STATUS_AUTHORIZED);
    expect($result->authorized_by)->toBe($supervisor->id);
    expect($result->authorized_at)->not->toBeNull();
    expect($result->isReadyForRelease())->toBeTrue();
});

test('verification cannot be skipped — verify is only reachable after review has started', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'serum', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');
    $request = requestAs($supervisor);
    $service = app(LaboratoryResultService::class);

    expect(fn () => $service->verifyResult($ctx['result'], [], $request))->toThrow(ValidationException::class);
});

test('authorization cannot be skipped — authorize is only reachable after verification', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'plasma', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');
    $request = requestAs($supervisor);
    $service = app(LaboratoryResultService::class);

    $result = $service->startReview($ctx['result'], $request);

    expect(fn () => $service->authorizeResult($result, $request))->toThrow(ValidationException::class);
});

test('a result under review can be returned for correction, corrected, and resubmitted through the same submit path', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'urine', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test, ['value_numeric' => '999']);
    $supervisor = userWithRole('laboratory-supervisor');
    $reviewerRequest = requestAs($supervisor);
    $service = app(LaboratoryResultService::class);

    $result = $service->startReview($ctx['result'], $reviewerRequest);
    $result = $service->returnForCorrection($result, ['return_reason' => 'incorrect_value'], $reviewerRequest);

    expect($result->status)->toBe(LaboratoryResult::STATUS_RETURNED_FOR_CORRECTION);
    expect($result->returned_by)->toBe($supervisor->id);
    expect($result->return_reason)->toBe('incorrect_value');

    $entererRequest = requestAs($ctx['doctor']['user']);
    $result = $service->saveDraft($result, ['value_numeric' => '13.5'], $entererRequest);
    expect($result->status)->toBe(LaboratoryResult::STATUS_DRAFT);
    expect($result->value_numeric)->toBe('13.5000');

    $result = $service->submit($result, $entererRequest);
    expect($result->status)->toBe(LaboratoryResult::STATUS_PENDING_VERIFICATION);

    // Second pass: review, verify, authorize.
    $result = $service->startReview($result, $reviewerRequest);
    $result = $service->verifyResult($result, [], $reviewerRequest);
    $result = $service->authorizeResult($result, $reviewerRequest);
    expect($result->status)->toBe(LaboratoryResult::STATUS_AUTHORIZED);
});

test('a verified result can be amended, which resets it to pending_verification and clears verification metadata', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'csf', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');
    $request = requestAs($supervisor);
    $service = app(LaboratoryResultService::class);

    $result = $service->startReview($ctx['result'], $request);
    $result = $service->verifyResult($result, [], $request);

    expect($result->status)->toBe(LaboratoryResult::STATUS_VERIFIED);

    $result = $service->amend($result, ['value_numeric' => '20.0', 'amendment_reason' => 'Repeat analysis required'], $request);

    expect($result->status)->toBe(LaboratoryResult::STATUS_PENDING_VERIFICATION);
    expect($result->value_numeric)->toBe('20.0000');
    expect($result->verified_by)->toBeNull();
    expect($result->verified_at)->toBeNull();
    expect($result->verification_comment)->toBeNull();

    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_result_amendment_requested', 'auditable_id' => $result->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_result_reverification_required', 'auditable_id' => $result->id]);

    // The original verification is still recoverable from the audit log.
    $verifiedEvent = AuditLog::where('action', 'laboratory_result_verified')->where('auditable_id', $result->id)->sole();
    expect($verifiedEvent->new_values['verified_by'])->toBe($supervisor->id);
});

test('an authorized result can be amended, which also resets it all the way back to pending_verification', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'sputum', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');
    $request = requestAs($supervisor);
    $service = app(LaboratoryResultService::class);

    $result = $service->startReview($ctx['result'], $request);
    $result = $service->verifyResult($result, [], $request);
    $result = $service->authorizeResult($result, $request);

    expect($result->status)->toBe(LaboratoryResult::STATUS_AUTHORIZED);

    $result = $service->amend($result, ['value_numeric' => '21.0', 'amendment_reason' => 'Transcription correction'], $request);

    expect($result->status)->toBe(LaboratoryResult::STATUS_PENDING_VERIFICATION);
    expect($result->authorized_by)->toBeNull();
    expect($result->authorized_at)->toBeNull();
    expect($result->verified_by)->toBeNull();
});

test('a draft/pending_verification amendment (Step 33 behavior) is unaffected — status stays at pending_verification', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'tissue', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryResultService::class);

    $result = $service->amend($ctx['result'], ['value_numeric' => '14.0', 'amendment_reason' => 'typo'], $request);

    expect($result->status)->toBe(LaboratoryResult::STATUS_PENDING_VERIFICATION);
    expect($result->value_numeric)->toBe('14.0000');
});

test('once authorized, an ordinary draft save is rejected — verified results are locked', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'body_fluid', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');
    $request = requestAs($supervisor);
    $service = app(LaboratoryResultService::class);

    $result = $service->startReview($ctx['result'], $request);
    $result = $service->verifyResult($result, [], $request);
    $result = $service->authorizeResult($result, $request);

    expect(fn () => $service->saveDraft($result, ['value_numeric' => '99'], $request))->toThrow(ValidationException::class);
    expect(fn () => $service->submit($result, $request))->toThrow(ValidationException::class);
});
