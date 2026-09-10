<?php

use App\Models\LaboratoryResult;

/**
 * Once VERIFIED (spec §26), a result's ordinary edit/submit actions must be
 * rejected outright — the frontend hides the buttons, but this proves the
 * backend independently enforces it regardless of what the client sends.
 */
test('ordinary update and submit are rejected over HTTP once a result is verified', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');

    $this->actingAs($supervisor)->post(route('laboratory.results.review', $ctx['result']))->assertRedirect();
    $this->actingAs($supervisor)->post(route('laboratory.results.verify', $ctx['result']->fresh()))->assertRedirect();

    $result = $ctx['result']->fresh();
    expect($result->status)->toBe(LaboratoryResult::STATUS_VERIFIED);

    $this->actingAs($supervisor)
        ->put(route('laboratory.results.update', $result), ['value_numeric' => '999'])
        ->assertSessionHasErrors('status');

    $this->actingAs($supervisor)->post(route('laboratory.results.submit', $result))->assertSessionHasErrors('status');

    expect($result->fresh()->value_numeric)->toBe('13.5000');
});

test('ordinary update and submit are rejected over HTTP once a result is authorized', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'serum', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');

    $this->actingAs($supervisor)->post(route('laboratory.results.review', $ctx['result']))->assertRedirect();
    $this->actingAs($supervisor)->post(route('laboratory.results.verify', $ctx['result']->fresh()))->assertRedirect();
    $this->actingAs($supervisor)->post(route('laboratory.results.authorize', $ctx['result']->fresh()))->assertRedirect();

    $result = $ctx['result']->fresh();
    expect($result->status)->toBe(LaboratoryResult::STATUS_AUTHORIZED);

    $this->actingAs($supervisor)
        ->put(route('laboratory.results.update', $result), ['value_numeric' => '999'])
        ->assertSessionHasErrors('status');
});

test('a laboratory technician cannot amend a verified result even though they can amend a pending_verification one', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'plasma', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');
    $technician = userWithRole('laboratory-technician');

    $this->actingAs($technician)
        ->post(route('laboratory.results.amend', $ctx['result']), ['value_numeric' => '14.0', 'amendment_reason' => 'typo'])
        ->assertRedirect();

    $this->actingAs($supervisor)->post(route('laboratory.results.review', $ctx['result']->fresh()))->assertRedirect();
    $this->actingAs($supervisor)->post(route('laboratory.results.verify', $ctx['result']->fresh()))->assertRedirect();

    $this->actingAs($technician)
        ->post(route('laboratory.results.amend', $ctx['result']->fresh()), ['value_numeric' => '20.0', 'amendment_reason' => 'x'])
        ->assertForbidden();

    $this->actingAs($supervisor)
        ->post(route('laboratory.results.amend', $ctx['result']->fresh()), ['value_numeric' => '20.0', 'amendment_reason' => 'controlled correction'])
        ->assertRedirect();

    expect($ctx['result']->fresh()->status)->toBe(LaboratoryResult::STATUS_PENDING_VERIFICATION);
});
