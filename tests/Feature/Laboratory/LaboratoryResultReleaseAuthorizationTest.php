<?php

use App\Models\Hospital;
use App\Models\LaboratoryResult;
use App\Models\Staff;
use App\Models\User;
use App\Services\LaboratoryResultService;

test('a guest is redirected to login for release and report routes', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = releasedForItem($test);

    $this->post(route('laboratory.results.release', $ctx['result']))->assertRedirect(route('login'));
    $this->get(route('laboratory.reports.show', $ctx['report']))->assertRedirect(route('login'));
    $this->post(route('laboratory.reports.print', $ctx['report']))->assertRedirect(route('login'));
    $this->post(route('laboratory.reports.download', $ctx['report']))->assertRedirect(route('login'));
});

test('a receptionist, nurse, and laboratory technician cannot release a result', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'serum', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');
    app(LaboratoryResultService::class)->startReview($ctx['result'], requestAs($supervisor));
    $result = app(LaboratoryResultService::class)->verifyResult($ctx['result']->fresh(), [], requestAs($supervisor));
    $result = app(LaboratoryResultService::class)->authorizeResult($result, requestAs($supervisor));

    foreach (['receptionist', 'nurse', 'laboratory-technician'] as $role) {
        $this->actingAs(userWithRole($role))->post(route('laboratory.results.release', $result))->assertForbidden();
    }
});

test('a laboratory technician can view a released report but cannot print or download it', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'plasma', 'result_type' => 'numeric']);
    $ctx = releasedForItem($test);
    $technician = userWithRole('laboratory-technician');

    $this->actingAs($technician)->get(route('laboratory.reports.show', $ctx['report']))->assertOk();
    $this->actingAs($technician)->post(route('laboratory.reports.print', $ctx['report']))->assertForbidden();
    $this->actingAs($technician)->post(route('laboratory.reports.download', $ctx['report']))->assertForbidden();
});

test('a doctor can view, print, and download a released report but cannot release the result', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'csf', 'result_type' => 'numeric']);
    $ctx = releasedForItem($test);

    $this->actingAs($ctx['doctor']['user'])->get(route('laboratory.reports.show', $ctx['report']))->assertOk();
    $this->actingAs($ctx['doctor']['user'])->post(route('laboratory.reports.print', $ctx['report']))->assertRedirect();
    $this->actingAs($ctx['doctor']['user'])->post(route('laboratory.reports.download', $ctx['report']))->assertRedirect();
});

test('a laboratory supervisor can release an authorized result', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'sputum', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');
    $resultService = app(LaboratoryResultService::class);
    $result = $resultService->startReview($ctx['result'], requestAs($supervisor));
    $result = $resultService->verifyResult($result, [], requestAs($supervisor));
    $result = $resultService->authorizeResult($result, requestAs($supervisor));

    $this->actingAs($supervisor)->post(route('laboratory.results.release', $result))->assertRedirect();

    expect($result->fresh()->status)->toBe(LaboratoryResult::STATUS_RELEASED);
});

test('a hospital-admin can release within their own hospital but not another', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $resultA = pendingVerificationForHospital($hospitalA, activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']));
    $resultB = pendingVerificationForHospital($hospitalB, activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']));

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->post(route('laboratory.results.review', $resultA))->assertRedirect();
    $this->actingAs($adminA)->post(route('laboratory.results.verify', $resultA->fresh()))->assertRedirect();
    $this->actingAs($adminA)->post(route('laboratory.results.authorize', $resultA->fresh()))->assertRedirect();
    $this->actingAs($adminA)->post(route('laboratory.results.release', $resultA->fresh()))->assertRedirect();

    $this->actingAs($adminA)->post(route('laboratory.results.review', $resultB))->assertForbidden();
});

test('a super-admin bypasses laboratory-results release permission checks', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'tissue', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $user = userWithRole('super-admin');
    $resultService = app(LaboratoryResultService::class);
    $result = $resultService->startReview($ctx['result'], requestAs($user));
    $result = $resultService->verifyResult($result, [], requestAs($user));
    $result = $resultService->authorizeResult($result, requestAs($user));

    $this->actingAs($user)->post(route('laboratory.results.release', $result))->assertRedirect();

    expect($result->fresh()->status)->toBe(LaboratoryResult::STATUS_RELEASED);
});
