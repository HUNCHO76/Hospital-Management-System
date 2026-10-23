<?php

use App\Models\AuditLog;
use App\Models\Hospital;
use App\Models\NhifClaim;
use App\Models\NhifClaimDocument;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

function createHospitalWithUser(string $role = 'hospital-admin'): array
{
    $hospital = Hospital::factory()->create();
    $user = userWithRole($role);
    $staff = Staff::factory()->create([
        'user_id' => $user->id,
        'hospital_id' => $hospital->id,
    ]);

    return compact('hospital', 'user', 'staff');
}

function createClaimForHospital(Hospital $hospital, User $creator): NhifClaim
{
    $patient = Patient::factory()->create();
    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'nhif_authorization_no' => 'AUTH-SEC-TEST',
    ]);

    return NhifClaim::factory()->create([
        'hospital_id' => $hospital->id,
        'patient_id' => $patient->id,
        'patient_insurance_id' => $insurance->id,
        'status' => NhifClaim::STATUS_DRAFT,
        'created_by' => $creator->id,
    ]);
}

test('an unauthenticated guest is redirected to login for claim endpoints', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $insurance = PatientInsurance::factory()->create(['patient_id' => $patient->id]);
    $claim = NhifClaim::factory()->create([
        'hospital_id' => $hospital->id,
        'patient_id' => $patient->id,
        'patient_insurance_id' => $insurance->id,
    ]);

    $this->get(route('nhif.claims.index'))->assertRedirect(route('login'));
    $this->get(route('nhif.claims.show', $claim))->assertRedirect(route('login'));
});

test('an unauthorized role missing nhif-claims.view cannot list or view claims', function () {
    $h1 = createHospitalWithUser('hospital-admin');
    $claim = createClaimForHospital($h1['hospital'], $h1['user']);

    // Receptionist has no nhif-claims.view permission
    $receptionist = userWithRole('receptionist');
    Staff::factory()->create([
        'user_id' => $receptionist->id,
        'hospital_id' => $h1['hospital']->id,
    ]);

    $this->actingAs($receptionist)
        ->get(route('nhif.claims.index'))
        ->assertForbidden();

    $this->actingAs($receptionist)
        ->get(route('nhif.claims.show', $claim))
        ->assertForbidden();
});

test('an authorized user can view claims at their own facility', function () {
    $h1 = createHospitalWithUser('hospital-admin');
    $claim = createClaimForHospital($h1['hospital'], $h1['user']);

    $this->actingAs($h1['user'])
        ->get(route('nhif.claims.index'))
        ->assertOk();

    $this->actingAs($h1['user'])
        ->get(route('nhif.claims.show', $claim))
        ->assertOk();
});

test('cross-facility access is denied: user at Hospital A cannot view a claim at Hospital B', function () {
    $h1 = createHospitalWithUser('hospital-admin');
    $h2 = createHospitalWithUser('hospital-admin');

    $claimH1 = createClaimForHospital($h1['hospital'], $h1['user']);

    // User at Hospital 2 attempts to view Hospital 1's claim
    $this->actingAs($h2['user'])
        ->get(route('nhif.claims.show', $claimH1))
        ->assertForbidden();
});

test('super-admin can view claims across all facilities', function () {
    $h1 = createHospitalWithUser('hospital-admin');
    $claim = createClaimForHospital($h1['hospital'], $h1['user']);

    $superAdmin = userWithRole('super-admin');

    $this->actingAs($superAdmin)
        ->get(route('nhif.claims.show', $claim))
        ->assertOk();
});

test('cross-facility document download is denied', function () {
    $h1 = createHospitalWithUser('hospital-admin');
    $h2 = createHospitalWithUser('hospital-admin');

    $claimH1 = createClaimForHospital($h1['hospital'], $h1['user']);

    $fakeFile = UploadedFile::fake()->create('referral.pdf', 100, 'application/pdf');
    $path = $fakeFile->store('nhif/claims/'.$claimH1->id, 'local');

    $document = NhifClaimDocument::create([
        'nhif_claim_id' => $claimH1->id,
        'hospital_id' => $h1['hospital']->id,
        'document_type' => 'referral_letter',
        'checksum' => hash('sha256', 'referral'),
        'path' => $path,
        'original_name' => 'referral.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1000,
        'uploaded_by' => $h1['user']->id,
    ]);

    // User at Hospital 2 attempts to download Hospital 1 document
    $this->actingAs($h2['user'])
        ->get(route('nhif.claims.documents.download', [$claimH1, $document]))
        ->assertForbidden();
});

test('mismatched claim and document ID is rejected with 404 (IDOR parent-child validation)', function () {
    $h1 = createHospitalWithUser('hospital-admin');

    $claim1 = createClaimForHospital($h1['hospital'], $h1['user']);
    $claim2 = createClaimForHospital($h1['hospital'], $h1['user']);

    $fakeFile = UploadedFile::fake()->create('secret.pdf', 50, 'application/pdf');
    $path = $fakeFile->store('nhif/claims/'.$claim2->id, 'local');

    $document2 = NhifClaimDocument::create([
        'nhif_claim_id' => $claim2->id,
        'hospital_id' => $h1['hospital']->id,
        'document_type' => 'referral_letter',
        'checksum' => hash('sha256', 'secret'),
        'path' => $path,
        'original_name' => 'secret.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 500,
        'uploaded_by' => $h1['user']->id,
    ]);

    // Accessing document2 through claim1 must fail with 404
    $this->actingAs($h1['user'])
        ->get(route('nhif.claims.documents.download', [$claim1, $document2]))
        ->assertNotFound();

    // Deleting document2 through claim1 must fail with 404
    $this->actingAs($h1['user'])
        ->delete(route('nhif.claims.documents.destroy', [$claim1, $document2]))
        ->assertNotFound();
});

test('authorized user at the same hospital can download a claim document and audit log is recorded', function () {
    $h1 = createHospitalWithUser('hospital-admin');
    $claim = createClaimForHospital($h1['hospital'], $h1['user']);

    $fakeFile = UploadedFile::fake()->create('medical_report.pdf', 80, 'application/pdf');
    $path = $fakeFile->store('nhif/claims/'.$claim->id, 'local');

    $document = NhifClaimDocument::create([
        'nhif_claim_id' => $claim->id,
        'hospital_id' => $h1['hospital']->id,
        'document_type' => 'referral_letter',
        'checksum' => hash('sha256', 'medical_report'),
        'path' => $path,
        'original_name' => 'medical_report.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 800,
        'uploaded_by' => $h1['user']->id,
    ]);

    $response = $this->actingAs($h1['user'])
        ->get(route('nhif.claims.documents.download', [$claim, $document]));

    $response->assertOk();

    // Verify audit log
    $logged = AuditLog::query()
        ->where('action', 'nhif_claim.document_downloaded')
        ->where('user_id', $h1['user']->id)
        ->first();

    expect($logged)->not->toBeNull();
    expect($logged->new_values['document_id'])->toBe($document->id);
});

test('authorized user at same hospital can delete document and audit log is recorded', function () {
    $h1 = createHospitalWithUser('hospital-admin');
    $claim = createClaimForHospital($h1['hospital'], $h1['user']);

    $fakeFile = UploadedFile::fake()->create('delete_me.pdf', 40, 'application/pdf');
    $path = $fakeFile->store('nhif/claims/'.$claim->id, 'local');

    $document = NhifClaimDocument::create([
        'nhif_claim_id' => $claim->id,
        'hospital_id' => $h1['hospital']->id,
        'document_type' => 'referral_letter',
        'checksum' => hash('sha256', 'delete_me'),
        'path' => $path,
        'original_name' => 'delete_me.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 400,
        'uploaded_by' => $h1['user']->id,
    ]);

    $this->actingAs($h1['user'])
        ->delete(route('nhif.claims.documents.destroy', [$claim, $document]))
        ->assertRedirect();

    expect(NhifClaimDocument::find($document->id))->toBeNull();
    expect(Storage::disk('local')->exists($path))->toBeFalse();

    $logged = AuditLog::query()
        ->where('action', 'nhif_claim.document_deleted')
        ->where('user_id', $h1['user']->id)
        ->first();

    expect($logged)->not->toBeNull();
    expect($logged->old_values['document_id'])->toBe($document->id);
});

test('a user cannot create a claim into another hospital facility', function () {
    $h1 = createHospitalWithUser('hospital-admin');
    $h2 = createHospitalWithUser('hospital-admin');

    $patient = Patient::factory()->create();
    $insurance = PatientInsurance::factory()->create(['patient_id' => $patient->id]);

    // User at Hospital 1 tries to create claim targeting Hospital 2
    $this->actingAs($h1['user'])
        ->post(route('nhif.claims.store'), [
            'hospital_id' => $h2['hospital']->id,
            'patient_id' => $patient->id,
            'patient_insurance_id' => $insurance->id,
            'claim_type' => 'outpatient',
            'attendance_date' => now()->toDateString(),
        ])
        ->assertForbidden();
});
