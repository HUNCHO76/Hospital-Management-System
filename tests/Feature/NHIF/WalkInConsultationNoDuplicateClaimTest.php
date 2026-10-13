<?php

use App\Models\ClinicalEncounter;
use App\Models\Consultation;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\InsuranceProvider;
use App\Models\NhifPreApprovalSession;
use App\Models\NhifPreApprovalSessionItem;
use App\Models\NhifSchemeService;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\Practitioner;
use App\Models\ReceptionVisit;
use App\Models\Service;
use App\Models\Staff;
use App\Services\NHIF\ClaimCalculationService;

it('walk-in NHIF patient with selected specialty consultation does not produce duplicate 10001 charge on claim', function () {
    $hospital = Hospital::factory()->create(['code' => 'HOSP-TEST-DUP']);
    $department = Department::factory()->create(['hospital_id' => $hospital->id]);
    $provider = InsuranceProvider::factory()->create(['api_enabled' => true, 'status' => 'active']);

    $patient = Patient::factory()->create();
    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'insurance_provider_id' => $provider->id,
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_authorization_no' => 'AUTH-DUP-TEST-001',
        'nhif_scheme_id' => 9001,
        'is_primary' => true,
    ]);

    $user = userWithRole('receptionist');
    Staff::factory()->create(['user_id' => $user->id, 'hospital_id' => $hospital->id]);

    $practitioner = Practitioner::factory()->create([
        'nhif_practitioner_no' => 'PRAC-001',
    ]);

    // User selected specialist consultation code 163698
    $selectedCode = '163698';
    $selectedName = 'Medical Officer/Dental Surgeon follow up consultation fee';

    $hmsService = Service::factory()->create([
        'code' => $selectedCode,
        'name' => $selectedName,
        'category' => 'consultation',
    ]);

    NhifSchemeService::create([
        'scheme_id' => 9001,
        'item_code' => $selectedCode,
        'item_name' => $selectedName,
        'item_type_id' => 1,
        'type_name' => 'Registration/Consultation Charges',
        'unit_price' => 3500,
        'is_active' => true,
    ]);

    $visit = ReceptionVisit::create([
        'patient_id' => $patient->id,
        'department_id' => $department->id,
        'practitioner_id' => $practitioner->id,
        'visit_type' => 'walk_in',
        'status' => ReceptionVisit::STATUS_CHECKED_IN,
    ]);

    // Pre-approval session item created at walk-in
    $session = NhifPreApprovalSession::create([
        'patient_id' => $patient->id,
        'patient_insurance_id' => $insurance->id,
        'hospital_id' => $hospital->id,
        'nhif_authorization_no' => $insurance->nhif_authorization_no,
        'nhif_card_no' => $insurance->card_number,
        'status' => NhifPreApprovalSession::STATUS_ISSUED,
        'created_by' => $user->id,
    ]);

    NhifPreApprovalSessionItem::create([
        'nhif_pre_approval_session_id' => $session->id,
        'source_type' => ReceptionVisit::class,
        'source_id' => $visit->id,
        'nhif_item_code' => $selectedCode,
        'nhif_item_name' => $selectedName,
        'quantity' => 1,
        'unit_price' => 3500.0,
        'approval_status' => NhifPreApprovalSessionItem::APPROVAL_STATUS_ACCEPTED,
    ]);

    // Patient attended by doctor: clinical encounter & completed consultation
    $encounter = ClinicalEncounter::create([
        'patient_id' => $patient->id,
        'reception_visit_id' => $visit->id,
        'department_id' => $department->id,
        'practitioner_id' => $practitioner->id,
        'status' => ClinicalEncounter::STATUS_COMPLETED,
        'encounter_type' => 'walk_in',
        'created_by' => $user->id,
    ]);

    Consultation::create([
        'clinical_encounter_id' => $encounter->id,
        'practitioner_id' => $practitioner->id,
        'patient_id' => $patient->id,
        'status' => Consultation::STATUS_COMPLETED,
        'completed_at' => now(),
        'created_by' => $user->id,
    ]);

    $calculator = app(ClaimCalculationService::class);
    $readiness = $calculator->validateClaimReadiness($encounter);

    $items = collect($readiness['items']);
    $consultationItems = $items->filter(fn ($i) => (int) $i['item_type_id'] === 1);

    // Expect exactly ONE consultation item on the claim
    expect($consultationItems)->toHaveCount(1);

    // Expect the consultation item to be the user-selected service (163698), NOT 10001
    expect($consultationItems->first()['nhif_item_code'])->toBe($selectedCode);
    expect($consultationItems->first()['nhif_item_name'])->toBe($selectedName);

    // Verify code 10001 is NOT anywhere in the claimable items
    expect($items->pluck('nhif_item_code')->all())->not->toContain('10001');
});
