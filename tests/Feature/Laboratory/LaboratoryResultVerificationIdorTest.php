<?php

use App\Models\Hospital;
use App\Models\LaboratoryResult;
use App\Models\Staff;
use App\Models\User;

test('a hospital-admin cannot review, verify, authorize, or return another hospital\'s result by guessing its id', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $resultA = pendingVerificationForHospital($hospitalA, activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']));
    $resultB = pendingVerificationForHospital($hospitalB, activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']));

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->post(route('laboratory.results.review', $resultB))->assertForbidden();
    $this->actingAs($adminA)->post(route('laboratory.results.verify', $resultB))->assertForbidden();
    $this->actingAs($adminA)->post(route('laboratory.results.authorize', $resultB))->assertForbidden();
    $this->actingAs($adminA)
        ->post(route('laboratory.results.return', $resultB), ['return_reason' => 'other', 'return_notes' => 'x'])
        ->assertForbidden();

    expect($resultA->id)->not->toBe($resultB->id);
    expect($resultB->fresh()->status)->toBe(LaboratoryResult::STATUS_PENDING_VERIFICATION);
});

test('a hospital-admin cannot amend another hospital\'s verified result', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $resultB = pendingVerificationForHospital($hospitalB, activeLaboratoryTest(['specimen_type' => 'serum', 'result_type' => 'numeric']));

    $adminB = User::factory()->create();
    $adminB->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminB->id, 'hospital_id' => $hospitalB->id]);

    $this->actingAs($adminB)->post(route('laboratory.results.review', $resultB))->assertRedirect();
    $this->actingAs($adminB)->post(route('laboratory.results.verify', $resultB->fresh()))->assertRedirect();

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)
        ->post(route('laboratory.results.amend', $resultB->fresh()), ['value_numeric' => '20', 'amendment_reason' => 'x'])
        ->assertForbidden();
});
