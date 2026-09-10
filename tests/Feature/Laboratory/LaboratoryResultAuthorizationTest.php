<?php

use App\Models\Hospital;
use App\Models\Staff;
use App\Models\User;

test('a guest is redirected to login for every result route', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);

    $this->get(route('laboratory.results.index'))->assertRedirect(route('login'));
    $this->get(route('laboratory.results.show', $ctx['result']))->assertRedirect(route('login'));
    $this->put(route('laboratory.results.update', $ctx['result']))->assertRedirect(route('login'));
    $this->post(route('laboratory.results.submit', $ctx['result']))->assertRedirect(route('login'));
});

test('a receptionist has no result-entry access at all', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'urine', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $receptionist = userWithRole('receptionist');

    $this->actingAs($receptionist)->get(route('laboratory.results.index'))->assertForbidden();
    $this->actingAs($receptionist)->get(route('laboratory.results.show', $ctx['result']))->assertForbidden();
    $this->actingAs($receptionist)->put(route('laboratory.results.update', $ctx['result']), ['value_numeric' => '13.5'])->assertForbidden();
});

test('a nurse has no result-entry access', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'sputum', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $nurse = userWithRole('nurse');

    $this->actingAs($nurse)->get(route('laboratory.results.index'))->assertForbidden();
    $this->actingAs($nurse)->put(route('laboratory.results.update', $ctx['result']), ['value_numeric' => '13.5'])->assertForbidden();
});

test('a clinician can view results but cannot enter, update, submit, or amend them', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'plasma', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);

    $this->actingAs($ctx['doctor']['user'])->get(route('laboratory.results.index'))->assertOk();
    $this->actingAs($ctx['doctor']['user'])->get(route('laboratory.results.show', $ctx['result']))->assertOk();
    $this->actingAs($ctx['doctor']['user'])->put(route('laboratory.results.update', $ctx['result']), ['value_numeric' => '13.5'])->assertForbidden();
    $this->actingAs($ctx['doctor']['user'])->post(route('laboratory.results.submit', $ctx['result']))->assertForbidden();
});

test('a laboratory technician can enter, save, submit, and amend a result', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'csf', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $technician = userWithRole('laboratory-technician');

    $this->actingAs($technician)
        ->put(route('laboratory.results.update', $ctx['result']), ['value_numeric' => '13.5'])
        ->assertRedirect();

    $this->actingAs($technician)->post(route('laboratory.results.submit', $ctx['result']))->assertRedirect();

    $this->actingAs($technician)
        ->post(route('laboratory.results.amend', $ctx['result']->fresh()), ['value_numeric' => '14.0', 'amendment_reason' => 'correction'])
        ->assertRedirect();

    expect($ctx['result']->fresh()->value_numeric)->toBe('14.0000');
});

test('a hospital-admin can act on results within their own hospital but not another', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $resultA = resultPendingForHospital($hospitalA, activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']));
    $resultB = resultPendingForHospital($hospitalB, activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']));

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->get(route('laboratory.results.show', $resultA))->assertOk();
    $this->actingAs($adminA)->get(route('laboratory.results.show', $resultB))->assertForbidden();

    $this->actingAs($adminA)->put(route('laboratory.results.update', $resultA), ['value_numeric' => '13.5'])->assertRedirect();
    $this->actingAs($adminA)->put(route('laboratory.results.update', $resultB), ['value_numeric' => '13.5'])->assertForbidden();
});

test('a super-admin bypasses laboratory-results permission checks and hospital scoping', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'tissue', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $user = userWithRole('super-admin');

    $this->actingAs($user)->put(route('laboratory.results.update', $ctx['result']), ['value_numeric' => '13.5'])->assertRedirect();

    expect($ctx['result']->fresh()->status)->toBe('draft');
});
