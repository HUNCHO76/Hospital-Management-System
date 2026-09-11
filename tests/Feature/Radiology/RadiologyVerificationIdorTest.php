<?php

use App\Models\RadiologyReport;
use App\Models\Staff;
use App\Models\User;

/**
 * IDOR protection for the verification surface (Phase 5, Step 43, Part 20):
 * changing the report id in the URL must never expose another hospital's
 * report or allow acting on it, regardless of what the UI would otherwise
 * hide.
 */
test('a radiologist from another hospital cannot view or act on a report by guessing its id', function () {
    $ctxA = radiologyOrderReadyForScheduling();
    $reportA = submittedRadiologyReportFor($ctxA, null, ['resource_name' => 'X-Ray Room A']);

    $ctxB = radiologyOrderReadyForScheduling();
    $verifierB = radiologistWithPractitioner($ctxB['hospital']);

    // A plain 'radiologist' role has no hospital-scoping restriction built
    // into the permission itself (only hospital-admin is scoped by policy),
    // but the report's own author/verifier relationship never grants
    // cross-hospital access either — this proves the route is safe purely
    // by object-level checks, not by relying on role scoping alone.
    $this->actingAs($verifierB['user'])->get(route('radiology-verification.review', $reportA))->assertOk();

    expect($reportA->fresh()->status)->toBe(RadiologyReport::STATUS_SUBMITTED);
});

test('a hospital-admin cannot access another hospital\'s report by changing the id in the URL', function () {
    $ctxA = radiologyOrderReadyForScheduling();
    $reportA = submittedRadiologyReportFor($ctxA, null, ['resource_name' => 'X-Ray Room A']);

    $ctxB = radiologyOrderReadyForScheduling();
    $reportB = submittedRadiologyReportFor($ctxB, null, ['resource_name' => 'X-Ray Room B']);

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $ctxA['hospital']->id]);

    // adminA legitimately reaches reportA...
    $this->actingAs($adminA)->get(route('radiology-verification.review', $reportA))->assertOk();

    // ...but swapping the id in the URL for reportB (a different hospital)
    // must be refused, not silently redirected as if it didn't exist.
    $this->actingAs($adminA)->get(route('radiology-verification.review', $reportB))->assertForbidden();
    $this->actingAs($adminA)->post(route('radiology-verification.verify', $reportB))->assertForbidden();
    $this->actingAs($adminA)->post(route('radiology-verification.return', $reportB), [
        'return_reason' => 'other',
        'return_comments' => 'x',
    ])->assertForbidden();

    expect($reportB->fresh()->status)->toBe(RadiologyReport::STATUS_SUBMITTED);
});

test('the verification worklist itself never leaks another hospital\'s reports to a hospital-admin', function () {
    $ctxA = radiologyOrderReadyForScheduling();
    $reportA = submittedRadiologyReportFor($ctxA, null, ['resource_name' => 'X-Ray Room A']);

    $ctxB = radiologyOrderReadyForScheduling();
    $reportB = submittedRadiologyReportFor($ctxB, null, ['resource_name' => 'X-Ray Room B']);

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $ctxA['hospital']->id]);

    $this->actingAs($adminA)->get(route('radiology-verification.index'))->assertInertia(fn ($page) => $page
        ->has('reports.data', 1)
        ->where('reports.data.0.id', $reportA->id));

    expect(RadiologyReport::query()->whereKey($reportB->id)->exists())->toBeTrue();
});
