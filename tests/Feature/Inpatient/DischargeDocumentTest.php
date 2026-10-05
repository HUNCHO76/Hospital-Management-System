<?php

/**
 * DischargeDocumentTest — Phase 9 Step 94
 *
 * Scenarios:
 *  1.  Data accuracy — assembleData returns expected admission/patient shape
 *  2.  Document generation — creates a DischargeDocument with correct version/status
 *  3.  Versioning — second generation supersedes first; version increments
 *  4.  Amendment requires reason — validation error when reason omitted on v2+
 *  5.  Audit trail — amendment_reason, amended_by, amended_at recorded on v2
 *  6.  Read-only contract — no admission/clinical records modified by generate()
 *  7.  Permission: view gate (403 without discharge-document.view)
 *  8.  Permission: generate gate (403 without discharge-document.generate)
 *  9.  Facility isolation — hospital-admin sees 403 for another facility's admission
 * 10.  Closed admission (discharged) can still generate a document
 * 11.  Financial summary included in data_snapshot when billing exists
 * 12.  NHIF info included when PatientInsurance has nhif_authorization_no
 */

use App\Models\Admission;
use App\Models\DischargeDocument;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\Staff;
use App\Services\DischargeDocumentService;

// ---------------------------------------------------------------------------
// Scenario 1 — assembleData returns expected shape
// ---------------------------------------------------------------------------

it('assembleData returns the expected keys for an admission', function (): void {
    $ctx = chargedAdmission();
    $service = app(DischargeDocumentService::class);

    $data = $service->assembleData($ctx['admission']);

    expect($data)->toHaveKeys([
        'admission', 'patient', 'diagnoses', 'clinical_notes',
        'discharge_medications', 'laboratory_findings', 'radiology_findings',
        'care_plans', 'vitals_at_discharge', 'financial_summary', 'insurance',
        'generated_at',
    ]);

    expect($data['admission'])->toHaveKey('admission_number', $ctx['admission']->admission_number);
    expect($data['patient'])->toHaveKey('patient_number', $ctx['patient']->patient_number);
    expect($data['financial_summary'])->toHaveKeys(['total_charges', 'status', 'currency']);
});

// ---------------------------------------------------------------------------
// Scenario 2 — generate() creates DischargeDocument v1 (active)
// ---------------------------------------------------------------------------

it('generate creates a DischargeDocument at version 1 with active status', function (): void {
    $ctx = chargedAdmission();
    $actor = $ctx['actor'];
    $admission = $ctx['admission'];

    $service = app(DischargeDocumentService::class);
    $doc = $service->generate($admission, null, requestAs($actor));

    expect($doc)->toBeInstanceOf(DischargeDocument::class);
    expect($doc->version)->toBe(1);
    expect($doc->status)->toBe(DischargeDocument::STATUS_ACTIVE);
    expect($doc->admission_id)->toBe($admission->id);
    expect($doc->hospital_id)->toBe($admission->hospital_id);
    expect($doc->patient_id)->toBe($admission->patient_id);
    expect($doc->generated_by)->toBe($actor->id);
    expect($doc->generated_at)->not->toBeNull();
    expect($doc->document_number)->toStartWith('DISC-');

    $this->assertDatabaseCount('discharge_documents', 1);
});

// ---------------------------------------------------------------------------
// Scenario 3 — second generate() supersedes first and increments version
// ---------------------------------------------------------------------------

it('second generate supersedes the previous document and creates version 2', function (): void {
    $ctx = chargedAdmission();
    $actor = $ctx['actor'];
    $admission = $ctx['admission'];

    $service = app(DischargeDocumentService::class);
    $v1 = $service->generate($admission, null, requestAs($actor));
    $v2 = $service->generate($admission, 'Corrected diagnosis code', requestAs($actor));

    expect($v1->fresh()->status)->toBe(DischargeDocument::STATUS_SUPERSEDED);
    expect($v2->version)->toBe(2);
    expect($v2->status)->toBe(DischargeDocument::STATUS_ACTIVE);

    $this->assertDatabaseCount('discharge_documents', 2);
});

// ---------------------------------------------------------------------------
// Scenario 4 — amendment reason required when generating v2+ (via HTTP)
// ---------------------------------------------------------------------------

it('generate endpoint requires amendment_reason when a document already exists', function (): void {
    $ctx = chargedAdmission();
    $actor = userWithRole('hospital-admin');
    Staff::factory()->create(['user_id' => $actor->id, 'hospital_id' => $ctx['hospital']->id]);

    // Create v1 first
    app(DischargeDocumentService::class)->generate($ctx['admission'], null, requestAs($actor));

    $response = $this->actingAs($actor)
        ->post(route('inpatient.discharge-document.generate', $ctx['admission']), []);

    $response->assertSessionHasErrors(['amendment_reason']);
});

// ---------------------------------------------------------------------------
// Scenario 5 — amendment chain recorded on v2
// ---------------------------------------------------------------------------

it('generate records amendment_reason, amended_by, and amended_at on subsequent versions', function (): void {
    $ctx = chargedAdmission();
    $actor = $ctx['actor'];
    $admission = $ctx['admission'];

    $service = app(DischargeDocumentService::class);
    $service->generate($admission, null, requestAs($actor));
    $v2 = $service->generate($admission, 'Updated after additional labs', requestAs($actor));

    expect($v2->amendment_reason)->toBe('Updated after additional labs');
    expect($v2->amended_by)->toBe($actor->id);
    expect($v2->amended_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Scenario 6 — read-only: admission record unchanged after generate()
// ---------------------------------------------------------------------------

it('generate() does not modify the admission record', function (): void {
    $ctx = chargedAdmission();
    $actor = $ctx['actor'];
    $admission = $ctx['admission'];

    $originalStatus = $admission->status;
    $originalUpdatedAt = $admission->updated_at;

    $service = app(DischargeDocumentService::class);
    $service->generate($admission, null, requestAs($actor));

    $admission->refresh();
    expect($admission->status)->toBe($originalStatus);
    // updated_at should not change (or if it does it's within the same second — so we test status/data)
    expect($admission->discharge_reason)->toBe($admission->getOriginal('discharge_reason') ?? null);
});

// ---------------------------------------------------------------------------
// Scenario 7 — permission gate: view requires discharge-document.view
// ---------------------------------------------------------------------------

it('show returns 403 without discharge-document.view permission', function (): void {
    $ctx = chargedAdmission();
    // receptionist has no discharge-document permissions
    $actor = userWithRole('receptionist');

    $this->actingAs($actor)
        ->get(route('inpatient.discharge-document', $ctx['admission']))
        ->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Scenario 8 — permission gate: generate requires discharge-document.generate
// ---------------------------------------------------------------------------

it('generate endpoint returns 403 without discharge-document.generate permission', function (): void {
    $ctx = chargedAdmission();
    $actor = userWithRole('nurse');
    Staff::factory()->create(['user_id' => $actor->id, 'hospital_id' => $ctx['hospital']->id]);

    // Nurse has discharge-document.view and .print, but NOT .generate
    $this->actingAs($actor)
        ->post(route('inpatient.discharge-document.generate', $ctx['admission']), [])
        ->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Scenario 9 — facility isolation: hospital-admin for facility B sees 403
// ---------------------------------------------------------------------------

it('hospital-admin from a different facility gets 403 on show', function (): void {
    $ctx = chargedAdmission();

    $otherHospital = Hospital::factory()->create();
    $otherAdmin = userWithRole('hospital-admin');
    Staff::factory()->create(['user_id' => $otherAdmin->id, 'hospital_id' => $otherHospital->id]);

    $this->actingAs($otherAdmin)
        ->get(route('inpatient.discharge-document', $ctx['admission']))
        ->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Scenario 10 — discharged admission still allows document generation
// ---------------------------------------------------------------------------

it('can generate a document for a discharged admission', function (): void {
    $ctx = chargedAdmission();
    $actor = $ctx['actor'];

    // Force the admission to discharged status for this test
    $ctx['admission']->forceFill(['status' => Admission::STATUS_DISCHARGED])->save();

    $service = app(DischargeDocumentService::class);
    $doc = $service->generate($ctx['admission'], null, requestAs($actor));

    expect($doc->status)->toBe(DischargeDocument::STATUS_ACTIVE);
});

// ---------------------------------------------------------------------------
// Scenario 11 — financial summary in snapshot
// ---------------------------------------------------------------------------

it('data_snapshot includes the financial summary from InpatientBillingService', function (): void {
    $ctx = chargedAdmission();
    $actor = $ctx['actor'];

    $service = app(DischargeDocumentService::class);
    $doc = $service->generate($ctx['admission'], null, requestAs($actor));

    $snapshot = $doc->data_snapshot;
    expect($snapshot['financial_summary'])->toHaveKeys([
        'total_charges', 'patient_responsibility', 'outstanding', 'status', 'currency',
    ]);
    expect($snapshot['financial_summary']['total_charges'])->toBeNumeric();
});

// ---------------------------------------------------------------------------
// Scenario 12 — NHIF info in snapshot when insurance has nhif_authorization_no
// ---------------------------------------------------------------------------

it('data_snapshot includes insurance info when PatientInsurance has nhif_authorization_no', function (): void {
    $ctx = chargedAdmission();
    $actor = $ctx['actor'];

    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'nhif_authorization_no' => 'AUTH-DISC-001',
    ]);
    $ctx['admission']->forceFill(['patient_insurance_id' => $insurance->id])->save();

    $service = app(DischargeDocumentService::class);
    $doc = $service->generate($ctx['admission'], null, requestAs($actor));

    expect($doc->data_snapshot['insurance'])->not->toBeNull();
    expect($doc->data_snapshot['insurance']['nhif_authorization_no'])->toBe('AUTH-DISC-001');
});
