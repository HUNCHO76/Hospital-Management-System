<?php

use App\Models\Practitioner;
use App\Models\RadiologyReport;
use App\Models\Staff;
use App\Models\User;

test('a guest is redirected to login for the verification worklist and every mutation', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);

    $this->get(route('radiology-verification.index'))->assertRedirect(route('login'));
    $this->get(route('radiology-verification.review', $report))->assertRedirect(route('login'));
    $this->post(route('radiology-verification.verify', $report))->assertRedirect(route('login'));
    $this->post(route('radiology-verification.return', $report), ['return_reason' => 'other', 'return_comments' => 'x'])
        ->assertRedirect(route('login'));
});

test('a doctor cannot view or act on the verification worklist', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);

    $this->actingAs($ctx['doctor']['user'])->get(route('radiology-verification.index'))->assertForbidden();
    $this->actingAs($ctx['doctor']['user'])->get(route('radiology-verification.review', $report))->assertForbidden();
    $this->actingAs($ctx['doctor']['user'])->post(route('radiology-verification.verify', $report))->assertForbidden();
});

test('a radiology technician cannot view or act on the verification worklist', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);
    $technician = userWithRole('radiology-technician');

    $this->actingAs($technician)->get(route('radiology-verification.index'))->assertForbidden();
    $this->actingAs($technician)->post(route('radiology-verification.verify', $report))->assertForbidden();

    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_SUBMITTED);
});

test('the reporting practitioner cannot verify or return their own submitted report', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $reporter = radiologistWithPractitioner($ctx['hospital']);
    $report = submittedRadiologyReportFor($ctx, $reporter);

    // The review page itself is also gated by independence from the author.
    $this->actingAs($reporter['user'])->get(route('radiology-verification.review', $report))->assertForbidden();
    $this->actingAs($reporter['user'])->post(route('radiology-verification.verify', $report))->assertForbidden();
    $this->actingAs($reporter['user'])->post(route('radiology-verification.return', $report), [
        'return_reason' => 'other',
        'return_comments' => 'x',
    ])->assertForbidden();

    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_SUBMITTED);
});

test('an independent radiologist can view, verify, and return reports', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);
    $verifier = radiologistWithPractitioner($ctx['hospital']);

    $this->actingAs($verifier['user'])->get(route('radiology-verification.index'))->assertOk();
    $this->actingAs($verifier['user'])->get(route('radiology-verification.review', $report))->assertOk();

    $this->actingAs($verifier['user'])->post(route('radiology-verification.verify', $report), [
        'verification_notes' => 'Concur.',
    ])->assertRedirect();

    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_VERIFIED);
});

test('a permission-less user cannot view or act on the verification worklist', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);
    seedRolesAndPermissions();
    $noPermissionUser = User::factory()->create();

    $this->actingAs($noPermissionUser)->get(route('radiology-verification.index'))->assertForbidden();
    $this->actingAs($noPermissionUser)->get(route('radiology-verification.review', $report))->assertForbidden();
    $this->actingAs($noPermissionUser)->post(route('radiology-verification.verify', $report))->assertForbidden();
});

test('a hospital-admin can verify within their own hospital but not another', function () {
    $ctxA = radiologyOrderReadyForScheduling();
    $reportA = submittedRadiologyReportFor($ctxA, null, ['resource_name' => 'X-Ray Room A']);

    $ctxB = radiologyOrderReadyForScheduling();
    $reportB = submittedRadiologyReportFor($ctxB, null, ['resource_name' => 'X-Ray Room B']);

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $ctxA['hospital']->id]);

    $this->actingAs($adminA)->get(route('radiology-verification.review', $reportA))->assertOk();
    $this->actingAs($adminA)->post(route('radiology-verification.verify', $reportA))->assertRedirect();

    // IDOR: hospital B's report must never be reachable by hospital A's admin.
    $this->actingAs($adminA)->get(route('radiology-verification.review', $reportB))->assertForbidden();
    $this->actingAs($adminA)->post(route('radiology-verification.verify', $reportB))->assertForbidden();

    expect($reportB->fresh()->status)->toBe(RadiologyReport::STATUS_SUBMITTED);
});

test('a super-admin bypasses radiology verification permission checks', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $report = submittedRadiologyReportFor($ctx);
    $user = userWithRole('super-admin');
    $staff = Staff::factory()->create(['user_id' => $user->id, 'hospital_id' => $ctx['hospital']->id]);
    Practitioner::factory()->create(['staff_id' => $staff->id, 'practitioner_type' => 'radiologist']);

    $this->actingAs($user)->post(route('radiology-verification.verify', $report))->assertRedirect();

    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_VERIFIED);
});
