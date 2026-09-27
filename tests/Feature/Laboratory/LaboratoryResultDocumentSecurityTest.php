<?php

use App\Models\Hospital;
use App\Models\LaboratoryResultDocument;
use App\Models\Staff;
use App\Models\User;
use App\Services\LaboratoryResultDocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('a hospital-admin cannot upload a document to another hospital\'s result', function () {
    seedRolesAndPermissions();
    Storage::fake('local');

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $resultB = resultPendingForHospital($hospitalB, activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']));

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $file = UploadedFile::fake()->create('interloper.pdf', 100, 'application/pdf');

    $this->actingAs($adminA)
        ->post(route('laboratory.results.documents.store', $resultB), ['document' => $file])
        ->assertForbidden();

    expect(LaboratoryResultDocument::where('laboratory_result_id', $resultB->id)->count())->toBe(0);
});

test('a hospital-admin cannot view a document belonging to another hospital\'s result', function () {
    seedRolesAndPermissions();
    Storage::fake('local');

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $resultB = resultPendingForHospital($hospitalB, activeLaboratoryTest(['specimen_type' => 'serum', 'result_type' => 'numeric']));

    // Create a document under hospital B's result using the super-admin (no hospital restriction).
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');
    $file = UploadedFile::fake()->create('result.pdf', 100, 'application/pdf');
    $document = app(LaboratoryResultDocumentService::class)->upload($resultB, $file, requestAs($superAdmin));
    Storage::disk('local')->put($document->path, 'content');

    // Hospital A admin tries to view it.
    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)
        ->get(route('laboratory.results.documents.show', [$resultB, $document]))
        ->assertForbidden();
});

test('a hospital-admin cannot delete a document belonging to another hospital\'s result', function () {
    seedRolesAndPermissions();
    Storage::fake('local');

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $resultB = resultPendingForHospital($hospitalB, activeLaboratoryTest(['specimen_type' => 'plasma', 'result_type' => 'numeric']));

    $adminB = User::factory()->create();
    $adminB->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminB->id, 'hospital_id' => $hospitalB->id]);

    $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
    $document = app(LaboratoryResultDocumentService::class)->upload($resultB, $file, requestAs($adminB));

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)
        ->delete(route('laboratory.results.documents.destroy', [$resultB, $document]))
        ->assertForbidden();

    expect(LaboratoryResultDocument::find($document->id))->not->toBeNull();
});

test('accessing a document via another result\'s URL returns 404 (IDOR guard)', function () {
    seedRolesAndPermissions();
    Storage::fake('local');

    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctxA = resultPendingForItem($test);
    $ctxB = resultPendingForItem($test);

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
    $documentA = app(LaboratoryResultDocumentService::class)->upload($ctxA['result'], $file, requestAs($superAdmin));
    Storage::disk('local')->put($documentA->path, 'content');

    // Try to view resultA's document via resultB's URL.
    $this->actingAs($superAdmin)
        ->get(route('laboratory.results.documents.show', [$ctxB['result'], $documentA]))
        ->assertNotFound();
});

test('deleting a document via another result\'s URL returns 404 (IDOR guard)', function () {
    seedRolesAndPermissions();
    Storage::fake('local');

    $test = activeLaboratoryTest(['specimen_type' => 'serum', 'result_type' => 'numeric']);
    $ctxA = resultPendingForItem($test);
    $ctxB = resultPendingForItem($test);

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
    $documentA = app(LaboratoryResultDocumentService::class)->upload($ctxA['result'], $file, requestAs($superAdmin));

    $this->actingAs($superAdmin)
        ->delete(route('laboratory.results.documents.destroy', [$ctxB['result'], $documentA]))
        ->assertNotFound();

    expect(LaboratoryResultDocument::find($documentA->id))->not->toBeNull();
});

test('a user without the update permission cannot upload documents', function () {
    seedRolesAndPermissions();
    Storage::fake('local');

    $test = activeLaboratoryTest(['specimen_type' => 'urine', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);

    // Doctor/clinician has view but not update.
    $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
    $this->actingAs($ctx['doctor']['user'])
        ->post(route('laboratory.results.documents.store', $ctx['result']), ['document' => $file])
        ->assertForbidden();
});

test('a verifier with only view permission can stream a document', function () {
    seedRolesAndPermissions();
    Storage::fake('local');

    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');

    $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');
    $document = app(LaboratoryResultDocumentService::class)->upload($ctx['result'], $file, requestAs($superAdmin));
    Storage::disk('local')->put($document->path, 'PDF content');

    // Clinician/doctor has laboratory-results.view but not update.
    $this->actingAs($ctx['doctor']['user'])
        ->get(route('laboratory.results.documents.show', [$ctx['result'], $document]))
        ->assertOk();
});
