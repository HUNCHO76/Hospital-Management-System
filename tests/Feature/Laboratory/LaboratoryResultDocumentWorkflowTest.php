<?php

use App\Models\LaboratoryResult;
use App\Models\LaboratoryResultDocument;
use App\Models\User;
use App\Services\LaboratoryResultDocumentService;
use App\Services\LaboratoryResultService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

test('upload is rejected once the result has been submitted for verification', function () {
    seedRolesAndPermissions();
    Storage::fake('local');

    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $technician = userWithRole('laboratory-technician');

    $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

    // The service's status guard fires after authorization passes, producing a
    // redirect back with a validation error — not a 403.
    $this->actingAs($technician)
        ->post(route('laboratory.results.documents.store', $ctx['result']), ['document' => $file])
        ->assertSessionHasErrors('status');

    expect(LaboratoryResultDocument::where('laboratory_result_id', $ctx['result']->id)->count())->toBe(0);
});

test('document service throws when uploading to a non-editable result', function () {
    seedRolesAndPermissions();
    Storage::fake('local');

    $test = activeLaboratoryTest(['specimen_type' => 'serum', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $request = requestAs(userWithRole('laboratory-technician'));
    $service = app(LaboratoryResultDocumentService::class);

    $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

    expect(fn () => $service->upload($ctx['result'], $file, $request))
        ->toThrow(ValidationException::class);
});

test('upload is rejected for a released result', function () {
    seedRolesAndPermissions();
    Storage::fake('local');

    $test = activeLaboratoryTest(['specimen_type' => 'urine', 'result_type' => 'numeric']);
    $ctx = releasedForItem($test);
    $request = requestAs(userWithRole('laboratory-technician'));
    $service = app(LaboratoryResultDocumentService::class);
    $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

    expect($ctx['result']->status)->toBe(LaboratoryResult::STATUS_RELEASED);
    expect(fn () => $service->upload($ctx['result'], $file, $request))
        ->toThrow(ValidationException::class);
});

test('deleting a document from a non-editable result is rejected', function () {
    seedRolesAndPermissions();
    Storage::fake('local');

    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $technician = userWithRole('laboratory-technician');
    $request = requestAs($technician);
    $docService = app(LaboratoryResultDocumentService::class);
    $resultService = app(LaboratoryResultService::class);

    // Upload while editable.
    $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
    $document = $docService->upload($ctx['result'], $file, $request);

    // Submit to move out of draft.
    $resultService->saveDraft($ctx['result'], ['value_numeric' => '13.5'], $request);
    $result = $resultService->submit($ctx['result']->fresh(), $request);

    expect($result->status)->toBe(LaboratoryResult::STATUS_PENDING_VERIFICATION);

    // Delete should now be rejected.
    expect(fn () => $docService->delete($document, $request))
        ->toThrow(ValidationException::class);

    expect(LaboratoryResultDocument::find($document->id))->not->toBeNull();
});

test('documents survive the full release lifecycle', function () {
    seedRolesAndPermissions();
    Storage::fake('local');

    $test = activeLaboratoryTest(['specimen_type' => 'plasma', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $technician = userWithRole('laboratory-technician');
    $request = requestAs($technician);
    $docService = app(LaboratoryResultDocumentService::class);

    // Upload while still editable.
    $file = UploadedFile::fake()->create('analyzer.pdf', 100, 'application/pdf');
    $document = $docService->upload($ctx['result'], $file, $request);
    $documentId = $document->id;

    // Run the full lifecycle to RELEASED.
    releasedForItem($test);

    // The document record is still present.
    expect(LaboratoryResultDocument::find($documentId))->not->toBeNull();
});

test('the show page for a result includes its documents when loaded', function () {
    seedRolesAndPermissions();
    Storage::fake('local');

    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');
    $request = requestAs($superAdmin);

    $file = UploadedFile::fake()->create('machine_output.pdf', 100, 'application/pdf');
    app(LaboratoryResultDocumentService::class)->upload($ctx['result'], $file, $request);

    $this->actingAs($superAdmin)
        ->get(route('laboratory.results.show', $ctx['result']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('result.documents', 1)
            ->where('result.documents.0.original_filename', 'machine_output.pdf')
            ->where('result.result_source', LaboratoryResult::RESULT_SOURCE_MACHINE_DOCUMENT),
        );
});
