<?php

use App\Models\Hospital;
use App\Models\Staff;
use App\Models\User;

test('a hospital-admin cannot open, edit, or submit another hospital\'s result by guessing its id', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $resultA = resultPendingForHospital($hospitalA, activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']));
    $resultB = resultPendingForHospital($hospitalB, activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']));

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->get(route('laboratory.results.show', $resultB))->assertForbidden();
    $this->actingAs($adminA)->put(route('laboratory.results.update', $resultB), ['value_numeric' => '99'])->assertForbidden();
    $this->actingAs($adminA)->post(route('laboratory.results.submit', $resultB))->assertForbidden();

    expect($resultA->id)->not->toBe($resultB->id);
    expect($resultB->fresh()->status)->toBe('result_pending');
});

test('a hospital-admin cannot amend another hospital\'s already-submitted result', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $resultB = resultPendingForHospital($hospitalB, activeLaboratoryTest(['specimen_type' => 'serum', 'result_type' => 'numeric']));

    $adminB = User::factory()->create();
    $adminB->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminB->id, 'hospital_id' => $hospitalB->id]);

    $this->actingAs($adminB)->put(route('laboratory.results.update', $resultB), ['value_numeric' => '13.5'])->assertRedirect();
    $this->actingAs($adminB)->post(route('laboratory.results.submit', $resultB->fresh()))->assertRedirect();

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)
        ->post(route('laboratory.results.amend', $resultB->fresh()), ['value_numeric' => '20', 'amendment_reason' => 'x'])
        ->assertForbidden();
});
