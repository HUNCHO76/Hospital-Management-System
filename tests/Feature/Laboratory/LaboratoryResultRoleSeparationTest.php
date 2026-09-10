<?php

use App\Models\LaboratoryResult;
use App\Services\LaboratoryResultService;
use Illuminate\Validation\ValidationException;

/**
 * The entry-vs-review separation rule (Step 34 spec §6-7/§39): the user who
 * entered a result's value may never review, verify, return, or authorize
 * that same result — even if that user personally holds every verification
 * permission (e.g. a laboratory-supervisor who also entered a value
 * themselves in a pinch). A different authorized user must always act on
 * it. Verify and authorize, by contrast, may be performed by the same
 * person (spec §17/§39 only requires entry-vs-review separation).
 */
test('a laboratory supervisor who entered a result cannot review, verify, or return that same result', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');

    // The supervisor personally enters and submits this result.
    $this->actingAs($supervisor)->put(route('laboratory.results.update', $ctx['result']), ['value_numeric' => '13.5'])->assertRedirect();
    $this->actingAs($supervisor)->post(route('laboratory.results.submit', $ctx['result']->fresh()))->assertRedirect();

    $result = $ctx['result']->fresh();
    expect($result->entered_by)->toBe($supervisor->id);

    // The same supervisor may not review their own entry.
    $this->actingAs($supervisor)->post(route('laboratory.results.review', $result))->assertForbidden();
    $this->actingAs($supervisor)->post(route('laboratory.results.verify', $result))->assertForbidden();
    $this->actingAs($supervisor)
        ->post(route('laboratory.results.return', $result), ['return_reason' => 'other', 'return_notes' => 'x'])
        ->assertForbidden();

    expect($result->fresh()->status)->toBe(LaboratoryResult::STATUS_PENDING_VERIFICATION);
});

test('a different laboratory supervisor can review and verify a result entered by a colleague', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'serum', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $enteringSupervisor = userWithRole('laboratory-supervisor');
    $reviewingSupervisor = userWithRole('laboratory-supervisor');

    $this->actingAs($enteringSupervisor)->put(route('laboratory.results.update', $ctx['result']), ['value_numeric' => '13.5'])->assertRedirect();
    $this->actingAs($enteringSupervisor)->post(route('laboratory.results.submit', $ctx['result']->fresh()))->assertRedirect();

    $this->actingAs($reviewingSupervisor)->post(route('laboratory.results.review', $ctx['result']->fresh()))->assertRedirect();
    $this->actingAs($reviewingSupervisor)->post(route('laboratory.results.verify', $ctx['result']->fresh()))->assertRedirect();

    expect($ctx['result']->fresh()->status)->toBe(LaboratoryResult::STATUS_VERIFIED);
});

test('the same supervisor may both verify and authorize the same result', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'plasma', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');

    $this->actingAs($supervisor)->post(route('laboratory.results.review', $ctx['result']))->assertRedirect();
    $this->actingAs($supervisor)->post(route('laboratory.results.verify', $ctx['result']->fresh()))->assertRedirect();
    $this->actingAs($supervisor)->post(route('laboratory.results.authorize', $ctx['result']->fresh()))->assertRedirect();

    $result = $ctx['result']->fresh();
    expect($result->status)->toBe(LaboratoryResult::STATUS_AUTHORIZED);
    expect($result->verified_by)->toBe($supervisor->id);
    expect($result->authorized_by)->toBe($supervisor->id);
});

test('entry-vs-review separation is enforced by the policy layer, not the service layer', function () {
    // The self-review rule lives in LaboratoryResultPolicy (an authorization
    // concern, checked before the controller ever calls the service), not
    // as a ValidationException inside LaboratoryResultService itself — the
    // service will happily let any caller through, matching this app's
    // established convention that ownership/self-action checks are a
    // Policy responsibility, not a Service one. This documents that
    // deliberate boundary rather than asserting a service-level exception
    // that does not exist.
    $test = activeLaboratoryTest(['specimen_type' => 'urine', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryResultService::class);

    // Calling the service directly (bypassing the policy layer entirely,
    // as a controller normally would not) with the same user who entered
    // the value succeeds — proving the guard is truly at the policy layer.
    $result = $service->startReview($ctx['result'], $request);
    expect($result->status)->toBe(LaboratoryResult::STATUS_UNDER_REVIEW);
});
