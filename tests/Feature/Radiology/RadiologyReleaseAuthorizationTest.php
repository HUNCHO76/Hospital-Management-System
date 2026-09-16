<?php

use App\Models\Practitioner;
use App\Models\RadiologyReport;
use App\Models\Staff;
use App\Models\User;

test('a guest is redirected to login for the release worklist and every mutation', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = verifiedRadiologyReportFor($ctx);

    $this->get(route('radiology-release.index'))->assertRedirect(route('login'));
    $this->get(route('radiology-release.show', $report))->assertRedirect(route('login'));
    $this->post(route('radiology-release.release', $report))->assertRedirect(route('login'));
});

test('a doctor cannot view or act on the release worklist', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = verifiedRadiologyReportFor($ctx);

    $this->actingAs($ctx['doctor']['user'])->get(route('radiology-release.index'))->assertForbidden();
    $this->actingAs($ctx['doctor']['user'])->post(route('radiology-release.release', $report))->assertForbidden();
});

test('a radiology technician cannot release results', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = verifiedRadiologyReportFor($ctx);
    $technician = userWithRole('radiology-technician');

    $this->actingAs($technician)->get(route('radiology-release.index'))->assertForbidden();
    $this->actingAs($technician)->post(route('radiology-release.release', $report))->assertForbidden();

    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_VERIFIED);
});

test('a permission-less user cannot view or act on the release worklist', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = verifiedRadiologyReportFor($ctx);
    seedRolesAndPermissions();
    $noPermissionUser = User::factory()->create();

    $this->actingAs($noPermissionUser)->get(route('radiology-release.index'))->assertForbidden();
    $this->actingAs($noPermissionUser)->get(route('radiology-release.show', $report))->assertForbidden();
    $this->actingAs($noPermissionUser)->post(route('radiology-release.release', $report))->assertForbidden();
});

test('a radiologist holding the release permission can view, show, and release a verified report', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = verifiedRadiologyReportFor($ctx);
    $releaser = radiologySupervisorWithPractitioner($ctx['hospital']);

    $this->actingAs($releaser['user'])->get(route('radiology-release.index'))->assertOk();
    $this->actingAs($releaser['user'])->get(route('radiology-release.show', $report))->assertOk();

    $this->actingAs($releaser['user'])->post(route('radiology-release.release', $report), [
        'release_notes' => 'Reviewed.',
    ])->assertRedirect();

    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_RELEASED);
});

test('the reporting practitioner or verifier may also release their own chain — no independence restriction applies', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $reporter = radiologistWithPractitioner($ctx['hospital']);
    $verifier = radiologySupervisorWithPractitioner($ctx['hospital']);
    $report = verifiedRadiologyReportFor($ctx, $reporter, $verifier);

    // Part 9-11 of Step 44 impose no independence-from-author/verifier
    // restriction on release, unlike Step 43's self-verification rule.
    $this->actingAs($verifier['user'])->post(route('radiology-release.release', $report))->assertRedirect();

    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_RELEASED);
});

test('a hospital-admin can release within their own hospital but not another', function () {
    $ctxA = radiologyOrderReadyForScheduling();
    $reportA = verifiedRadiologyReportFor($ctxA, null, null, ['resource_name' => 'X-Ray Room A']);

    $ctxB = radiologyOrderReadyForScheduling();
    $reportB = verifiedRadiologyReportFor($ctxB, null, null, ['resource_name' => 'X-Ray Room B']);

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $ctxA['hospital']->id]);

    $this->actingAs($adminA)->get(route('radiology-release.show', $reportA))->assertOk();
    $this->actingAs($adminA)->post(route('radiology-release.release', $reportA))->assertRedirect();

    // IDOR: hospital B's report must never be reachable by hospital A's admin.
    $this->actingAs($adminA)->get(route('radiology-release.show', $reportB))->assertForbidden();
    $this->actingAs($adminA)->post(route('radiology-release.release', $reportB))->assertForbidden();

    expect($reportB->fresh()->status)->toBe(RadiologyReport::STATUS_VERIFIED);
});

test('a super-admin bypasses radiology release permission checks', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = verifiedRadiologyReportFor($ctx);
    $user = userWithRole('super-admin');
    $staff = Staff::factory()->create(['user_id' => $user->id, 'hospital_id' => $ctx['hospital']->id]);
    Practitioner::factory()->create(['staff_id' => $staff->id, 'practitioner_type' => 'radiologist']);

    $this->actingAs($user)->post(route('radiology-release.release', $report))->assertRedirect();

    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_RELEASED);
});
