<?php

use App\Models\LaboratoryResult;
use App\Models\LaboratoryResultDocument;
use App\Services\LaboratoryResultDocumentService;
use App\Services\LaboratoryResultService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('a valid PDF document can be uploaded to a result-pending result', function () {
    seedRolesAndPermissions();
    Storage::fake('local');

    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $result = $ctx['result'];
    $technician = userWithRole('laboratory-technician');

    $file = UploadedFile::fake()->create('analyzer_output.pdf', 100, 'application/pdf');

    $this->actingAs($technician)
        ->post(route('laboratory.results.documents.store', $result), ['document' => $file])
        ->assertRedirect();

    expect(LaboratoryResultDocument::where('laboratory_result_id', $result->id)->count())->toBe(1);

    $document = LaboratoryResultDocument::where('laboratory_result_id', $result->id)->first();
    expect($document->original_filename)->toBe('analyzer_output.pdf');
    expect($document->mime_type)->toBe('application/pdf');
    expect($document->uploaded_by)->toBe($technician->id);
    Storage::disk('local')->assertExists($document->path);
});

test('uploading a document sets result_source to machine_document', function () {
    seedRolesAndPermissions();
    Storage::fake('local');

    $test = activeLaboratoryTest(['specimen_type' => 'serum', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $result = $ctx['result'];

    expect($result->result_source)->toBeNull();

    $file = UploadedFile::fake()->create('result.pdf', 50, 'application/pdf');

    $this->actingAs(userWithRole('laboratory-technician'))
        ->post(route('laboratory.results.documents.store', $result), ['document' => $file])
        ->assertRedirect();

    expect($result->fresh()->result_source)->toBe(LaboratoryResult::RESULT_SOURCE_MACHINE_DOCUMENT);
});

test('a JPEG and PNG image can also be uploaded', function () {
    seedRolesAndPermissions();
    Storage::fake('local');

    $test = activeLaboratoryTest(['specimen_type' => 'urine', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $technician = userWithRole('laboratory-technician');

    foreach (['image.jpg' => 'image/jpeg', 'image.png' => 'image/png'] as $name => $mime) {
        $file = UploadedFile::fake()->create($name, 200, $mime);

        $this->actingAs($technician)
            ->post(route('laboratory.results.documents.store', $ctx['result']), ['document' => $file])
            ->assertRedirect();
    }

    expect(LaboratoryResultDocument::where('laboratory_result_id', $ctx['result']->id)->count())->toBe(2);
});

test('a file exceeding 20MB is rejected', function () {
    seedRolesAndPermissions();
    Storage::fake('local');

    $test = activeLaboratoryTest(['specimen_type' => 'plasma', 'result_type' => 'text']);
    $ctx = resultPendingForItem($test);
    $technician = userWithRole('laboratory-technician');

    $file = UploadedFile::fake()->create('big.pdf', 21000, 'application/pdf'); // 21 MB

    $this->actingAs($technician)
        ->post(route('laboratory.results.documents.store', $ctx['result']), ['document' => $file])
        ->assertSessionHasErrors('document');

    expect(LaboratoryResultDocument::where('laboratory_result_id', $ctx['result']->id)->count())->toBe(0);
});

test('an unsupported MIME type is rejected', function () {
    seedRolesAndPermissions();
    Storage::fake('local');

    $test = activeLaboratoryTest(['specimen_type' => 'plasma', 'result_type' => 'text']);
    $ctx = resultPendingForItem($test);
    $technician = userWithRole('laboratory-technician');

    $file = UploadedFile::fake()->create('macro.xlsx', 100, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $this->actingAs($technician)
        ->post(route('laboratory.results.documents.store', $ctx['result']), ['document' => $file])
        ->assertSessionHasErrors('document');

    expect(LaboratoryResultDocument::where('laboratory_result_id', $ctx['result']->id)->count())->toBe(0);
});

test('no file in the request is rejected', function () {
    seedRolesAndPermissions();
    Storage::fake('local');

    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $technician = userWithRole('laboratory-technician');

    $this->actingAs($technician)
        ->post(route('laboratory.results.documents.store', $ctx['result']), [])
        ->assertSessionHasErrors('document');
});

test('an uploaded document can be deleted and its storage file is removed', function () {
    seedRolesAndPermissions();
    Storage::fake('local');

    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $technician = userWithRole('laboratory-technician');

    $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');
    $service = app(LaboratoryResultDocumentService::class);
    $request = requestAs($technician);

    $document = $service->upload($ctx['result'], $file, $request);
    $storagePath = $document->path;

    Storage::disk('local')->assertExists($storagePath);

    $this->actingAs($technician)
        ->delete(route('laboratory.results.documents.destroy', [$ctx['result'], $document]))
        ->assertRedirect();

    expect(LaboratoryResultDocument::find($document->id))->toBeNull();
    Storage::disk('local')->assertMissing($storagePath);
});

test('a document can be uploaded to a draft result', function () {
    seedRolesAndPermissions();
    Storage::fake('local');

    $test = activeLaboratoryTest(['specimen_type' => 'serum', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $technician = userWithRole('laboratory-technician');
    $request = requestAs($technician);

    app(LaboratoryResultService::class)->saveDraft($ctx['result'], ['value_numeric' => '5.0'], $request);
    $draft = $ctx['result']->fresh();

    expect($draft->status)->toBe(LaboratoryResult::STATUS_DRAFT);

    $file = UploadedFile::fake()->create('draft_doc.pdf', 100, 'application/pdf');
    $this->actingAs($technician)
        ->post(route('laboratory.results.documents.store', $draft), ['document' => $file])
        ->assertRedirect();

    expect(LaboratoryResultDocument::where('laboratory_result_id', $draft->id)->count())->toBe(1);
});

test('a document can be uploaded to a returned-for-correction result', function () {
    seedRolesAndPermissions();
    Storage::fake('local');

    $test = activeLaboratoryTest(['specimen_type' => 'urine', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $resultService = app(LaboratoryResultService::class);
    $supervisor = userWithRole('laboratory-supervisor');
    $supervisorRequest = requestAs($supervisor);

    $result = $resultService->startReview($ctx['result'], $supervisorRequest);
    $result = $resultService->returnForCorrection(
        $result,
        ['return_reason' => 'incorrect_value', 'return_notes' => null],
        $supervisorRequest,
    );

    expect($result->status)->toBe(LaboratoryResult::STATUS_RETURNED_FOR_CORRECTION);

    $technician = userWithRole('laboratory-technician');
    $file = UploadedFile::fake()->create('corrected.pdf', 100, 'application/pdf');
    $this->actingAs($technician)
        ->post(route('laboratory.results.documents.store', $result), ['document' => $file])
        ->assertRedirect();

    expect(LaboratoryResultDocument::where('laboratory_result_id', $result->id)->count())->toBe(1);
});
