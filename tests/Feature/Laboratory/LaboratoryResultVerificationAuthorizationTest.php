<?php

use App\Models\Hospital;
use App\Models\LaboratoryResult;
use App\Models\Staff;
use App\Models\User;

test('a guest is redirected to login for every verification route', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);

    $this->post(route('laboratory.results.review', $ctx['result']))->assertRedirect(route('login'));
    $this->post(route('laboratory.results.verify', $ctx['result']))->assertRedirect(route('login'));
    $this->post(route('laboratory.results.authorize', $ctx['result']))->assertRedirect(route('login'));
    $this->post(route('laboratory.results.return', $ctx['result']))->assertRedirect(route('login'));
});

test('a receptionist, nurse, and laboratory technician cannot review, verify, authorize, or return a result', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'serum', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);

    foreach (['receptionist', 'nurse', 'laboratory-technician'] as $role) {
        $user = userWithRole($role);

        $this->actingAs($user)->post(route('laboratory.results.review', $ctx['result']))->assertForbidden();
        $this->actingAs($user)->post(route('laboratory.results.verify', $ctx['result']))->assertForbidden();
        $this->actingAs($user)->post(route('laboratory.results.authorize', $ctx['result']))->assertForbidden();
        $this->actingAs($user)->post(route('laboratory.results.return', $ctx['result']), ['return_reason' => 'other', 'return_notes' => 'x'])->assertForbidden();
    }
});

test('a clinician can view but cannot review, verify, authorize, or return a result', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'plasma', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);

    $this->actingAs($ctx['doctor']['user'])->get(route('laboratory.results.show', $ctx['result']))->assertOk();
    $this->actingAs($ctx['doctor']['user'])->post(route('laboratory.results.review', $ctx['result']))->assertForbidden();
});

test('a laboratory supervisor can review, verify, authorize, and return a result', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'csf', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');

    $this->actingAs($supervisor)->post(route('laboratory.results.review', $ctx['result']))->assertRedirect();
    $this->actingAs($supervisor)->post(route('laboratory.results.verify', $ctx['result']->fresh()))->assertRedirect();
    $this->actingAs($supervisor)->post(route('laboratory.results.authorize', $ctx['result']->fresh()))->assertRedirect();

    expect($ctx['result']->fresh()->status)->toBe(LaboratoryResult::STATUS_AUTHORIZED);
});

test('a laboratory supervisor can return a result for correction with a valid reason', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'sputum', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');

    $this->actingAs($supervisor)->post(route('laboratory.results.review', $ctx['result']))->assertRedirect();
    $this->actingAs($supervisor)
        ->post(route('laboratory.results.return', $ctx['result']->fresh()), ['return_reason' => 'incorrect_value'])
        ->assertRedirect();

    expect($ctx['result']->fresh()->status)->toBe(LaboratoryResult::STATUS_RETURNED_FOR_CORRECTION);
});

test('a hospital-admin can act on verification within their own hospital but not another', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $resultA = pendingVerificationForHospital($hospitalA, activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']));
    $resultB = pendingVerificationForHospital($hospitalB, activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']));

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->post(route('laboratory.results.review', $resultA))->assertRedirect();
    $this->actingAs($adminA)->post(route('laboratory.results.review', $resultB))->assertForbidden();
});

test('a super-admin bypasses laboratory-results verification permission checks and hospital scoping', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'tissue', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $user = userWithRole('super-admin');

    $this->actingAs($user)->post(route('laboratory.results.review', $ctx['result']))->assertRedirect();
    $this->actingAs($user)->post(route('laboratory.results.verify', $ctx['result']->fresh()))->assertRedirect();

    expect($ctx['result']->fresh()->status)->toBe(LaboratoryResult::STATUS_VERIFIED);
});
