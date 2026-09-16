<?php

use App\Models\Hospital;
use App\Models\RadiologyImage;
use App\Models\RadiologyReport;
use App\Models\Staff;
use App\Models\User;
use App\Services\RadiologyExaminationService;
use App\Services\RadiologyImageService;
use App\Services\RadiologyReportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

test('a guest is redirected to login for every image route', function () {
    $ctx = radiologyOrderReadyForScheduling();
    ['appointment' => $appointment, 'technician' => $technician] = radiologyExaminationInProgress($ctx);
    $image = app(RadiologyImageService::class)->upload($appointment, [
        UploadedFile::fake()->create('x.jpg', 100, 'image/jpeg'),
    ], requestAs($technician))->sole();

    $this->post(route('radiology-images.store', $appointment))->assertRedirect(route('login'));
    $this->get(route('radiology-images.show', [$appointment, $image]))->assertRedirect(route('login'));
    $this->delete(route('radiology-images.destroy', [$appointment, $image]))->assertRedirect(route('login'));
});

test('a doctor can neither manage nor view examination images', function () {
    $ctx = radiologyOrderReadyForScheduling();
    ['appointment' => $appointment, 'technician' => $technician] = radiologyExaminationInProgress($ctx);
    $image = app(RadiologyImageService::class)->upload($appointment, [
        UploadedFile::fake()->create('x.jpg', 100, 'image/jpeg'),
    ], requestAs($technician))->sole();

    $this->actingAs($ctx['doctor']['user'])->post(route('radiology-images.store', $appointment), [
        'images' => [UploadedFile::fake()->create('y.jpg', 100, 'image/jpeg')],
    ])->assertForbidden();

    $this->actingAs($ctx['doctor']['user'])->get(route('radiology-images.show', [$appointment, $image]))->assertForbidden();
});

test('a permission-less user cannot manage or view images', function () {
    $ctx = radiologyOrderReadyForScheduling();
    ['appointment' => $appointment, 'technician' => $technician] = radiologyExaminationInProgress($ctx);
    $image = app(RadiologyImageService::class)->upload($appointment, [
        UploadedFile::fake()->create('x.jpg', 100, 'image/jpeg'),
    ], requestAs($technician))->sole();
    seedRolesAndPermissions();
    $noPermissionUser = User::factory()->create();

    $this->actingAs($noPermissionUser)->get(route('radiology-images.show', [$appointment, $image]))->assertForbidden();
    $this->actingAs($noPermissionUser)->post(route('radiology-images.store', $appointment), [
        'images' => [UploadedFile::fake()->create('y.jpg', 100, 'image/jpeg')],
    ])->assertForbidden();
});

test('a radiologist can view but not manage examination images', function () {
    $ctx = radiologyOrderReadyForScheduling();
    ['appointment' => $appointment, 'technician' => $technician] = radiologyExaminationInProgress($ctx);
    $image = app(RadiologyImageService::class)->upload($appointment, [
        UploadedFile::fake()->create('x.jpg', 100, 'image/jpeg'),
    ], requestAs($technician))->sole();
    $radiologist = radiologistWithPractitioner($ctx['hospital']);

    $this->actingAs($radiologist['user'])->get(route('radiology-images.show', [$appointment, $image]))->assertOk();
    $this->actingAs($radiologist['user'])->post(route('radiology-images.store', $appointment), [
        'images' => [UploadedFile::fake()->create('y.jpg', 100, 'image/jpeg')],
    ])->assertForbidden();
    $this->actingAs($radiologist['user'])->delete(route('radiology-images.destroy', [$appointment, $image]))->assertForbidden();
});

test('the technician who performed the examination can manage its images', function () {
    $ctx = radiologyOrderReadyForScheduling();
    ['appointment' => $appointment, 'technician' => $technician] = radiologyExaminationInProgress($ctx);

    $this->actingAs($technician)->post(route('radiology-images.store', $appointment), [
        'images' => [UploadedFile::fake()->create('x.jpg', 100, 'image/jpeg')],
    ])->assertRedirect();

    expect(RadiologyImage::query()->where('radiology_appointment_id', $appointment->id)->count())->toBe(1);
});

test('a hospital-admin can act within their own hospital but not another', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $ctxA = radiologyOrderReadyForScheduling();
    $ctxB = radiologyOrderReadyForScheduling();

    // Anchor each context's encounter/department to its own hospital so
    // hospital-scoping is meaningful.
    $ctxA['department']->update(['hospital_id' => $hospitalA->id]);
    $ctxB['department']->update(['hospital_id' => $hospitalB->id]);

    ['appointment' => $appointmentA, 'technician' => $technicianA] = radiologyExaminationInProgress($ctxA, ['resource_name' => 'X-Ray Room 1']);
    ['appointment' => $appointmentB, 'technician' => $technicianB] = radiologyExaminationInProgress($ctxB, ['resource_name' => 'X-Ray Room 2']);

    $imageA = app(RadiologyImageService::class)->upload($appointmentA, [
        UploadedFile::fake()->create('a.jpg', 100, 'image/jpeg'),
    ], requestAs($technicianA))->sole();
    $imageB = app(RadiologyImageService::class)->upload($appointmentB, [
        UploadedFile::fake()->create('b.jpg', 100, 'image/jpeg'),
    ], requestAs($technicianB))->sole();

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->get(route('radiology-images.show', [$appointmentA, $imageA]))->assertOk();
    $this->actingAs($adminA)->get(route('radiology-images.show', [$appointmentB, $imageB]))->assertForbidden();

    $this->actingAs($adminA)->post(route('radiology-images.store', $appointmentB), [
        'images' => [UploadedFile::fake()->create('c.jpg', 100, 'image/jpeg')],
    ])->assertForbidden();
});

test('a super-admin bypasses image permission checks', function () {
    $ctx = radiologyOrderReadyForScheduling();
    ['appointment' => $appointment] = radiologyExaminationInProgress($ctx);
    $user = userWithRole('super-admin');

    $this->actingAs($user)->post(route('radiology-images.store', $appointment), [
        'images' => [UploadedFile::fake()->create('x.jpg', 100, 'image/jpeg')],
    ])->assertRedirect();

    $this->assertDatabaseCount('radiology_images', 1);
});

test('a doctor cannot view examination images before the report is released', function () {
    $ctx = radiologyOrderReadyForScheduling();
    ['appointment' => $appointment, 'technician' => $technician] = radiologyExaminationInProgress($ctx);
    $image = app(RadiologyImageService::class)->upload($appointment, [
        UploadedFile::fake()->create('x.jpg', 100, 'image/jpeg'),
    ], requestAs($technician))->sole();
    app(RadiologyExaminationService::class)->complete($appointment->fresh(), [], requestAs($technician));

    $reporter = radiologistWithPractitioner($ctx['hospital']);
    $report = app(RadiologyReportService::class)->createDraft($appointment->fresh(), [
        'findings' => 'x', 'impression' => 'y',
    ], requestAs($reporter['user']));
    app(RadiologyReportService::class)->submit($report, requestAs($reporter['user']));

    // Still only SUBMITTED (not yet verified/released) — the doctor must
    // not see the image yet, mirroring RadiologyResultsSection's own
    // RELEASED-only boundary for the text findings.
    $this->actingAs($ctx['doctor']['user'])->get(route('radiology-images.show', [$appointment, $image]))->assertForbidden();
});

test('a doctor can view examination images once the report is released as the official clinical result', function () {
    $ctx = radiologyOrderReadyForScheduling();
    ['appointment' => $appointment, 'technician' => $technician] = radiologyExaminationInProgress($ctx);
    $image = app(RadiologyImageService::class)->upload($appointment, [
        UploadedFile::fake()->create('x.jpg', 100, 'image/jpeg'),
    ], requestAs($technician))->sole();
    app(RadiologyExaminationService::class)->complete($appointment->fresh(), [], requestAs($technician));

    $reporter = radiologistWithPractitioner($ctx['hospital']);
    $report = app(RadiologyReportService::class)->createDraft($appointment->fresh(), [
        'findings' => 'x', 'impression' => 'y',
    ], requestAs($reporter['user']));
    app(RadiologyReportService::class)->submit($report, requestAs($reporter['user']));

    $supervisor = radiologySupervisorWithPractitioner($ctx['hospital']);
    $verified = app(RadiologyReportService::class)->verify($report, [], requestAs($supervisor['user']));
    app(RadiologyReportService::class)->release($verified, [], requestAs($supervisor['user']));

    expect($report->fresh()->status)->toBe(RadiologyReport::STATUS_RELEASED);

    $this->actingAs($ctx['doctor']['user'])->get(route('radiology-images.show', [$appointment, $image]))->assertOk();
});

test('a hospital-admin from another hospital still cannot view a released image', function () {
    $ctx = radiologyOrderReadyForScheduling();
    ['appointment' => $appointment, 'technician' => $technician] = radiologyExaminationInProgress($ctx);
    $image = app(RadiologyImageService::class)->upload($appointment, [
        UploadedFile::fake()->create('x.jpg', 100, 'image/jpeg'),
    ], requestAs($technician))->sole();
    app(RadiologyExaminationService::class)->complete($appointment->fresh(), [], requestAs($technician));

    $reporter = radiologistWithPractitioner($ctx['hospital']);
    $report = app(RadiologyReportService::class)->createDraft($appointment->fresh(), [
        'findings' => 'x', 'impression' => 'y',
    ], requestAs($reporter['user']));
    app(RadiologyReportService::class)->submit($report, requestAs($reporter['user']));
    $supervisor = radiologySupervisorWithPractitioner($ctx['hospital']);
    app(RadiologyReportService::class)->release(
        app(RadiologyReportService::class)->verify($report, [], requestAs($supervisor['user'])),
        [],
        requestAs($supervisor['user']),
    );

    // Unlike a plain 'doctor' (this app's policies only ever scope
    // hospital-admin by hospital — every other role relies on object-level
    // checks, the same established convention every other Radiology policy
    // already follows), a hospital-admin from a *different* hospital is
    // genuinely restricted, proving the new doctor-via-encounter path still
    // routes through the real ClinicalEncounterPolicy::view() hospital
    // check rather than bypassing it.
    $otherAdmin = User::factory()->create();
    $otherAdmin->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $otherAdmin->id, 'hospital_id' => Hospital::factory()->create()->id]);

    $this->actingAs($otherAdmin)->get(route('radiology-images.show', [$appointment, $image]))->assertForbidden();
});

test('a viewable image is served with its correct content type and is never a public storage URL', function () {
    $ctx = radiologyOrderReadyForScheduling();
    ['appointment' => $appointment, 'technician' => $technician] = radiologyExaminationInProgress($ctx);
    $image = app(RadiologyImageService::class)->upload($appointment, [
        UploadedFile::fake()->create('chest.jpg', 100, 'image/jpeg'),
    ], requestAs($technician))->sole();

    $response = $this->actingAs($technician)->get(route('radiology-images.show', [$appointment, $image]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/jpeg');

    // The file must not be reachable through the public disk/symlink — it
    // was written to the private `local` disk only.
    Storage::disk('local')->assertExists($image->path);
    expect(Storage::disk('public')->exists($image->path))->toBeFalse();
});
