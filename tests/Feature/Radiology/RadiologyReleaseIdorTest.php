<?php

use App\Models\RadiologyReport;
use App\Models\Staff;
use App\Models\User;

/**
 * IDOR protection for the release surface (Phase 5, Step 44, Part 12/22):
 * changing the report id in the URL must never expose or release another
 * hospital's result.
 */
test('a hospital-admin cannot release another hospital\'s result by changing the id in the URL', function () {
    $ctxA = radiologyOrderReadyForScheduling();
    $reportA = verifiedRadiologyReportFor($ctxA, null, null, ['resource_name' => 'X-Ray Room A']);

    $ctxB = radiologyOrderReadyForScheduling();
    $reportB = verifiedRadiologyReportFor($ctxB, null, null, ['resource_name' => 'X-Ray Room B']);

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $ctxA['hospital']->id]);

    // adminA legitimately reaches reportA...
    $this->actingAs($adminA)->get(route('radiology-release.show', $reportA))->assertOk();

    // ...but swapping the id in the URL for reportB (a different hospital)
    // must be refused, not silently redirected as if it didn't exist.
    $this->actingAs($adminA)->get(route('radiology-release.show', $reportB))->assertForbidden();
    $this->actingAs($adminA)->post(route('radiology-release.release', $reportB))->assertForbidden();

    expect($reportB->fresh()->status)->toBe(RadiologyReport::STATUS_VERIFIED);
});

test('the release worklist itself never leaks another hospital\'s reports to a hospital-admin', function () {
    $ctxA = radiologyOrderReadyForScheduling();
    $reportA = verifiedRadiologyReportFor($ctxA, null, null, ['resource_name' => 'X-Ray Room A']);

    $ctxB = radiologyOrderReadyForScheduling();
    $reportB = verifiedRadiologyReportFor($ctxB, null, null, ['resource_name' => 'X-Ray Room B']);

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $ctxA['hospital']->id]);

    $this->actingAs($adminA)->get(route('radiology-release.index'))->assertInertia(fn ($page) => $page
        ->has('reports.data', 1)
        ->where('reports.data.0.id', $reportA->id));

    expect(RadiologyReport::query()->whereKey($reportB->id)->exists())->toBeTrue();
});
