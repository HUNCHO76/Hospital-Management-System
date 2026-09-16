<?php

use App\Http\Resources\RadiologyImageResource;
use App\Models\RadiologyImage;
use App\Services\RadiologyExaminationService;
use App\Services\RadiologyImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Radiology examination imaging (Radiology UI completion pass, Parts 7-9):
 * upload/delete only while IN_PROGRESS, MIME/size/count validation, and
 * that a stored image's private path is never exposed in an API response.
 *
 * `UploadedFile::fake()->create($name, $kb, $mimeType)` reports the given
 * MIME type from `getMimeType()` (Illuminate\Http\Testing\File overrides
 * it) rather than sniffing real file content — this lets these tests
 * exercise the `mimetypes` validation rule deterministically without a GD/
 * Imagick dependency, while production uploads are still content-sniffed
 * for real by Symfony's MimeTypes component.
 */
beforeEach(function () {
    Storage::fake('local');
});

test('images can be uploaded to an IN_PROGRESS examination', function () {
    $ctx = radiologyOrderReadyForScheduling();
    ['appointment' => $appointment, 'technician' => $technician] = radiologyExaminationInProgress($ctx);

    $files = [
        UploadedFile::fake()->create('chest-pa.jpg', 500, 'image/jpeg'),
        UploadedFile::fake()->create('chest-lateral.png', 800, 'image/png'),
    ];

    $this->actingAs($technician)->post(route('radiology-images.store', $appointment), [
        'images' => $files,
    ])->assertRedirect();

    expect(RadiologyImage::query()->where('radiology_appointment_id', $appointment->id)->count())->toBe(2);

    $image = RadiologyImage::query()->where('radiology_appointment_id', $appointment->id)->first();
    Storage::disk('local')->assertExists($image->path);

    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_examination_image_uploaded']);
});

test('images cannot be uploaded to a READY examination', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyAppointmentReadyForExamination($ctx);
    $technician = userWithRole('radiology-technician');

    $this->actingAs($technician)->post(route('radiology-images.store', $appointment), [
        'images' => [UploadedFile::fake()->create('x.jpg', 100, 'image/jpeg')],
    ])->assertSessionHasErrors('status');

    $this->assertDatabaseCount('radiology_images', 0);
});

test('images cannot be uploaded to a COMPLETED examination', function () {
    $ctx = radiologyOrderReadyForScheduling();
    $appointment = radiologyExaminationCompleted($ctx);
    $technician = userWithRole('radiology-technician');

    $this->actingAs($technician)->post(route('radiology-images.store', $appointment), [
        'images' => [UploadedFile::fake()->create('x.jpg', 100, 'image/jpeg')],
    ])->assertSessionHasErrors('status');
});

test('images cannot be uploaded to an ABORTED examination', function () {
    $ctx = radiologyOrderReadyForScheduling();
    ['appointment' => $appointment, 'technician' => $technician] = radiologyExaminationInProgress($ctx);
    app(RadiologyExaminationService::class)->abort($appointment, 'other', requestAs($technician));

    $this->actingAs($technician)->post(route('radiology-images.store', $appointment->fresh()), [
        'images' => [UploadedFile::fake()->create('x.jpg', 100, 'image/jpeg')],
    ])->assertSessionHasErrors('status');
});

test('uploading a non-image file is rejected', function () {
    $ctx = radiologyOrderReadyForScheduling();
    ['appointment' => $appointment, 'technician' => $technician] = radiologyExaminationInProgress($ctx);

    $this->actingAs($technician)->post(route('radiology-images.store', $appointment), [
        'images' => [UploadedFile::fake()->create('report.pdf', 200, 'application/pdf')],
    ])->assertSessionHasErrors('images.0');

    $this->assertDatabaseCount('radiology_images', 0);
});

test('uploading an oversized file is rejected', function () {
    $ctx = radiologyOrderReadyForScheduling();
    ['appointment' => $appointment, 'technician' => $technician] = radiologyExaminationInProgress($ctx);

    $this->actingAs($technician)->post(route('radiology-images.store', $appointment), [
        'images' => [UploadedFile::fake()->create('huge.jpg', RadiologyImageService::MAX_FILE_SIZE_KB + 100, 'image/jpeg')],
    ])->assertSessionHasErrors('images.0');
});

test('uploading with no files is rejected', function () {
    $ctx = radiologyOrderReadyForScheduling();
    ['appointment' => $appointment, 'technician' => $technician] = radiologyExaminationInProgress($ctx);

    $this->actingAs($technician)->post(route('radiology-images.store', $appointment), [
        'images' => [],
    ])->assertSessionHasErrors('images');
});

test('uploading more than the maximum allowed files at once is rejected', function () {
    $ctx = radiologyOrderReadyForScheduling();
    ['appointment' => $appointment, 'technician' => $technician] = radiologyExaminationInProgress($ctx);

    $files = collect(range(1, RadiologyImageService::MAX_FILES_PER_UPLOAD + 1))
        ->map(fn ($i) => UploadedFile::fake()->create("img{$i}.jpg", 50, 'image/jpeg'))
        ->all();

    $this->actingAs($technician)->post(route('radiology-images.store', $appointment), [
        'images' => $files,
    ])->assertSessionHasErrors('images');

    $this->assertDatabaseCount('radiology_images', 0);
});

test('an image can be deleted while the examination is in progress', function () {
    $ctx = radiologyOrderReadyForScheduling();
    ['appointment' => $appointment, 'technician' => $technician] = radiologyExaminationInProgress($ctx);

    $image = app(RadiologyImageService::class)->upload($appointment, [
        UploadedFile::fake()->create('x.jpg', 100, 'image/jpeg'),
    ], requestAs($technician))->sole();

    Storage::disk('local')->assertExists($image->path);

    $this->actingAs($technician)->delete(route('radiology-images.destroy', [$appointment, $image]))->assertRedirect();

    Storage::disk('local')->assertMissing($image->path);
    expect(RadiologyImage::query()->find($image->id))->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'radiology_examination_image_deleted']);
});

test('an image cannot be deleted once the examination is completed', function () {
    $ctx = radiologyOrderReadyForScheduling();
    ['appointment' => $appointment, 'technician' => $technician] = radiologyExaminationInProgress($ctx);

    $image = app(RadiologyImageService::class)->upload($appointment, [
        UploadedFile::fake()->create('x.jpg', 100, 'image/jpeg'),
    ], requestAs($technician))->sole();

    app(RadiologyExaminationService::class)->complete($appointment->fresh(), [], requestAs($technician));

    $this->actingAs($technician)->delete(route('radiology-images.destroy', [$appointment->fresh(), $image]))
        ->assertSessionHasErrors('status');

    expect(RadiologyImage::query()->find($image->id))->not->toBeNull();
});

test('an image id that does not belong to the appointment in the URL is rejected', function () {
    $ctxA = radiologyOrderReadyForScheduling();
    $ctxB = radiologyOrderReadyForScheduling();
    ['appointment' => $appointmentA, 'technician' => $technicianA] = radiologyExaminationInProgress($ctxA, ['resource_name' => 'X-Ray Room 1']);
    ['appointment' => $appointmentB] = radiologyExaminationInProgress($ctxB, ['resource_name' => 'X-Ray Room 2']);

    $imageB = app(RadiologyImageService::class)->upload($appointmentB, [
        UploadedFile::fake()->create('x.jpg', 100, 'image/jpeg'),
    ], requestAs(userWithRole('radiology-technician')))->sole();

    $this->actingAs($technicianA)->get(route('radiology-images.show', [$appointmentA, $imageB]))->assertNotFound();
    $this->actingAs($technicianA)->delete(route('radiology-images.destroy', [$appointmentA, $imageB]))->assertNotFound();

    expect(RadiologyImage::query()->find($imageB->id))->not->toBeNull();
});

test('the image resource never exposes the private storage path', function () {
    $ctx = radiologyOrderReadyForScheduling();
    ['appointment' => $appointment, 'technician' => $technician] = radiologyExaminationInProgress($ctx);

    $image = app(RadiologyImageService::class)->upload($appointment, [
        UploadedFile::fake()->create('x.jpg', 100, 'image/jpeg'),
    ], requestAs($technician))->sole();

    $resourceArray = (new RadiologyImageResource($image))->resolve(request());

    expect($resourceArray)->not->toHaveKey('path');
});
