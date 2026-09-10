<?php

use App\Models\Hospital;
use App\Models\LaboratoryResult;
use App\Models\Staff;
use App\Models\User;
use App\Services\LaboratoryResultService;

test('a hospital-admin cannot view, print, or download another hospital\'s report by guessing its id', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    releasedForHospital($hospitalA, activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']));
    $ctxB = releasedForHospital($hospitalB, activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']));

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->get(route('laboratory.reports.show', $ctxB['report']))->assertForbidden();
    $this->actingAs($adminA)->post(route('laboratory.reports.print', $ctxB['report']))->assertForbidden();
    $this->actingAs($adminA)->post(route('laboratory.reports.download', $ctxB['report']))->assertForbidden();
});

test('a hospital-admin cannot release another hospital\'s authorized result by guessing its id', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $resultB = pendingVerificationForHospital($hospitalB, activeLaboratoryTest(['specimen_type' => 'serum', 'result_type' => 'numeric']));
    $supervisorB = User::factory()->create();
    $supervisorB->assignRole('laboratory-supervisor');
    Staff::factory()->create(['user_id' => $supervisorB->id, 'hospital_id' => $hospitalB->id]);
    $resultService = app(LaboratoryResultService::class);
    $resultB = $resultService->startReview($resultB, requestAs($supervisorB));
    $resultB = $resultService->verifyResult($resultB, [], requestAs($supervisorB));
    $resultB = $resultService->authorizeResult($resultB, requestAs($supervisorB));

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->post(route('laboratory.results.release', $resultB))->assertForbidden();

    expect($resultB->fresh()->status)->toBe(LaboratoryResult::STATUS_AUTHORIZED);
});
