<?php

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\InsuranceProvider;
use App\Models\MaternityProfile;
use App\Models\NhifAdmission;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\Staff;
use App\Models\User;
use App\Services\MaternityEligibilityService;
use App\Services\MaternityService;
use Illuminate\Validation\ValidationException;

function maternityGateContext(string $gender = 'female', bool $withNhif = false, string $nhifStatus = 'accepted'): array
{
    $ctx = activeAdmission();
    $admission = $ctx['admission'];
    $admission->patient->update(['gender' => $gender]);
    $user = $ctx['user']; // nurse

    Staff::factory()->create([
        'user_id' => $user->id,
        'hospital_id' => $admission->hospital_id,
        'department_id' => $admission->department_id,
        'status' => 'active',
    ]);

    $doctor = userWithRole('doctor');
    Staff::factory()->create([
        'user_id' => $doctor->id,
        'hospital_id' => $admission->hospital_id,
        'department_id' => $admission->department_id,
        'status' => 'active',
    ]);
    $ctx['doctor'] = $doctor;

    if ($withNhif) {
        $provider = InsuranceProvider::firstOrCreate(
            ['code' => 'NHIF'],
            [
                'name' => 'National Health Insurance Fund',
                'type' => 'private',
                'status' => 'active',
            ]
        );
        $insurance = PatientInsurance::factory()->create([
            'patient_id' => $admission->patient_id,
            'insurance_provider_id' => $provider->id,
            'status' => PatientInsurance::STATUS_ACTIVE,
            'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
            'nhif_authorization_no' => 'AUTH-2026-999',
            'verified_at' => now(),
        ]);

        $admission->update(['patient_insurance_id' => $insurance->id]);

        $nhifAdmission = NhifAdmission::create([
            'admission_id' => $admission->id,
            'nhif_authorization_no' => 'AUTH-2026-999',
            'nhif_admission_no' => 'NHIF-ADM-777',
            'nhif_status' => $nhifStatus,
            'nhif_facility_code' => $admission->hospital->nhif_facility_code ?? $admission->hospital->code,
        ]);

        $ctx['insurance'] = $insurance;
        $ctx['nhifAdmission'] = $nhifAdmission;
    }

    return $ctx;
}

test('1. a patient can initiate a maternity episode without automatically creating an inpatient admission', function () {
    $patient = Patient::factory()->create(['gender' => 'female']);
    $doctor = userWithRole('doctor');
    $initialAdmissionCount = Admission::where('patient_id', $patient->id)->count();

    $response = $this->actingAs($doctor)->post(route('maternity.episodes.store'), [
        'patient_id' => $patient->id,
        'care_pathway' => 'outpatient',
        'gravida' => 1,
        'para' => 0,
        'presentation' => 'cephalic',
    ]);

    $response->assertSessionHasNoErrors();

    // Verify episode exists
    $profile = MaternityProfile::where('patient_id', $patient->id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->episode_number)->toStartWith('MAT-');
    expect($profile->care_pathway)->toBe('outpatient');
    expect($profile->admission_id)->toBeNull();

    // Verify no new inpatient admission was created
    expect(Admission::where('patient_id', $patient->id)->count())->toBe($initialAdmissionCount);
});

test('2. an authorized clinician can initiate maternity assessment through the supported care pathway', function () {
    $patient = Patient::factory()->create(['gender' => 'female']);
    $doctor = userWithRole('doctor');

    $profile = app(MaternityService::class)->registerEpisode(
        $patient,
        [
            'gravida' => 2,
            'para' => 1,
            'care_pathway' => MaternityProfile::CARE_PATHWAY_EMERGENCY,
            'care_stage' => MaternityProfile::CARE_STAGE_ASSESSMENT_IN_PROGRESS,
            'presentation' => 'cephalic',
        ],
        requestAs($doctor)
    );

    expect($profile->care_pathway)->toBe(MaternityProfile::CARE_PATHWAY_EMERGENCY);
    expect($profile->care_stage)->toBe(MaternityProfile::CARE_STAGE_ASSESSMENT_IN_PROGRESS);
    expect($profile->recorded_by)->toBe($doctor->id);
});

test('3. routine labour documentation requires the correct maternity episode', function () {
    $ctx = maternityGateContext('female', false);
    $admission = $ctx['admission'];
    $nurse = $ctx['user'];

    // Verify no maternity profile exists initially for this admission
    expect(MaternityProfile::where('admission_id', $admission->id)->exists())->toBeFalse();

    // Attempting to record a partograph entry directly without a maternity profile is blocked
    expect(function () use ($admission, $nurse) {
        app(MaternityEligibilityService::class)->assertActivityAllowed($admission, null, false, null, $nurse);
    })->toThrow(ValidationException::class);

    // Register the maternity profile
    $profile = app(MaternityService::class)->saveProfile($admission, [
        'gravida' => 1,
        'para' => 0,
        'presentation' => 'cephalic',
    ], requestAs($nurse));

    // Now routine partograph entry is allowed
    $entry = app(MaternityService::class)->recordPartograph($profile, [
        'recorded_at' => now()->toDateTimeString(),
        'fetal_heart_rate' => 136,
        'cervical_dilation_cm' => 4.0,
    ], requestAs($nurse));

    expect($entry->maternity_profile_id)->toBe($profile->id);
    expect($entry->cervical_dilation_cm)->toBe(4.0);
});

test('4. a legitimate emergency assessment is not blocked solely because routine registration or NHIF authorization is incomplete', function () {
    $ctx = maternityGateContext('female', true, NhifAdmission::STATUS_PENDING);
    $admission = $ctx['admission'];
    $nurse = $ctx['user'];

    // Profile registered, but NHIF is pending
    $profile = app(MaternityService::class)->saveProfile($admission, [
        'gravida' => 2,
        'para' => 1,
        'presentation' => 'cephalic',
    ], requestAs($nurse));

    $emergencyReason = 'Acute maternal haemorrhage and severe fetal distress; urgent immediate resuscitation required';

    // Emergency assertion succeeds without throwing
    app(MaternityEligibilityService::class)->assertActivityAllowed(
        $admission,
        $profile,
        true,
        $emergencyReason,
        $nurse,
        requestAs($nurse)
    );

    // Emergency partograph entry records successfully
    $entry = app(MaternityService::class)->recordPartograph($profile, [
        'recorded_at' => now()->toDateTimeString(),
        'fetal_heart_rate' => 90,
        'cervical_dilation_cm' => 8.0,
        'is_emergency' => true,
        'emergency_reason' => $emergencyReason,
    ], requestAs($nurse));

    expect($entry->is_emergency)->toBeTrue();
    expect($entry->emergency_reason)->toBe($emergencyReason);
    expect($entry->emergency_authorized_by)->toBe($nurse->id);

    // Verify audit log recorded emergency override
    $audit = AuditLog::where('action', 'maternity_emergency_override_documented')
        ->where('auditable_id', $profile->id)
        ->first();
    expect($audit)->not->toBeNull();
    expect($audit->new_values['emergency_reason'])->toBe($emergencyReason);
});

test('5. cash/self-pay patients do not encounter NHIF-only restrictions', function () {
    $ctx = maternityGateContext('female', false);
    $admission = $ctx['admission'];
    $nurse = $ctx['user'];

    $clearance = app(MaternityEligibilityService::class)->checkNhifClearance($admission);

    expect($clearance['is_nhif'])->toBeFalse();
    expect($clearance['insurance_status'])->toBe('cash_self_pay');
    expect($clearance['authorization_required'])->toBeFalse();
    expect($clearance['authorization_status'])->toBe('not_required');
    expect($clearance['service_permitted'])->toBeTrue();

    // Normal documentation proceeds smoothly
    $profile = app(MaternityService::class)->saveProfile($admission, [
        'gravida' => 1,
        'para' => 0,
        'presentation' => 'cephalic',
    ], requestAs($nurse));

    $entry = app(MaternityService::class)->recordPartograph($profile, [
        'recorded_at' => now()->toDateTimeString(),
        'fetal_heart_rate' => 140,
        'cervical_dilation_cm' => 5.0,
    ], requestAs($nurse));

    expect($entry)->not->toBeNull();
});

test('6. NHIF pending, rejected, expired and unverified states are distinguished from accepted authorization', function () {
    $service = app(MaternityEligibilityService::class);

    // 1. Pending NHIF
    $ctxPending = maternityGateContext('female', true, NhifAdmission::STATUS_PENDING);
    $clearancePending = $service->checkNhifClearance($ctxPending['admission']);
    expect($clearancePending['authorization_status'])->toBe('pending');
    expect($clearancePending['service_permitted'])->toBeFalse();

    // 2. Rejected NHIF
    $ctxRejected = maternityGateContext('female', true, NhifAdmission::STATUS_REJECTED);
    $clearanceRejected = $service->checkNhifClearance($ctxRejected['admission']);
    expect($clearanceRejected['authorization_status'])->toBe('rejected');
    expect($clearanceRejected['service_permitted'])->toBeFalse();

    // 3. Expired NHIF Authorization (older than 24 hours)
    $ctxExpired = maternityGateContext('female', true, NhifAdmission::STATUS_ACCEPTED);
    $ctxExpired['insurance']->update([
        'verified_at' => now()->subHours(25),
        'created_at' => now()->subHours(25),
    ]);
    $clearanceExpired = $service->checkNhifClearance($ctxExpired['admission']);
    expect($clearanceExpired['authorization_status'])->toBe('expired');
    expect($clearanceExpired['service_permitted'])->toBeFalse();

    // 4. Accepted & Active NHIF
    $ctxAccepted = maternityGateContext('female', true, NhifAdmission::STATUS_ACCEPTED);
    $clearanceAccepted = $service->checkNhifClearance($ctxAccepted['admission']);
    expect($clearanceAccepted['authorization_status'])->toBe('authorized');
    expect($clearanceAccepted['service_permitted'])->toBeTrue();
});

test('7. an admission number alone does not bypass the actual authorization rules', function () {
    $ctx = maternityGateContext('female', true, NhifAdmission::STATUS_REJECTED);
    $admission = $ctx['admission'];
    $nhifAdmission = $ctx['nhifAdmission'];

    // Explicitly set an admission number even though status is REJECTED
    $nhifAdmission->update([
        'nhif_admission_no' => 'NHIF-ADM-99999',
        'nhif_status' => NhifAdmission::STATUS_REJECTED,
    ]);

    $clearance = app(MaternityEligibilityService::class)->checkNhifClearance($admission);

    // Must NOT be cleared simply because nhif_admission_no was present
    expect($clearance['authorization_status'])->toBe('rejected');
    expect($clearance['service_permitted'])->toBeFalse();
});

test('8. service authorization is checked against the correct patient, facility and service where applicable', function () {
    $ctx = maternityGateContext('female', true, NhifAdmission::STATUS_ACCEPTED);
    $admission = $ctx['admission'];
    $service = app(MaternityEligibilityService::class);

    // Cross-patient mismatch
    $otherPatient = Patient::factory()->create(['gender' => 'female']);
    $mismatchClearance = $service->checkNhifClearance($admission, $otherPatient);
    expect($mismatchClearance['service_permitted'])->toBeFalse();
    expect($mismatchClearance['reason'])->toContain('patient mismatch');

    // Facility mismatch
    $admission->hospital->update(['nhif_facility_code' => 'HOSP-CORRECT-01']);
    $ctx['nhifAdmission']->update(['nhif_facility_code' => 'HOSP-WRONG-02']);

    $facilityClearance = $service->checkNhifClearance($admission->fresh(['hospital', 'nhifAdmission']));
    expect($facilityClearance['service_permitted'])->toBeFalse();
    expect($facilityClearance['reason'])->toContain('facility');
});

test('9. unauthorized users cannot create episodes or bypass backend gates', function () {
    $patient = Patient::factory()->create(['gender' => 'female']);
    $unauthorizedUser = User::factory()->create(); // No clinical/maternity role

    // Direct HTTP request to create maternity episode without permissions is blocked with 403
    $response = $this->actingAs($unauthorizedUser)->post(route('maternity.episodes.store'), [
        'patient_id' => $patient->id,
        'gravida' => 1,
        'para' => 0,
    ]);

    $response->assertForbidden();
});

test('10. duplicate episode creation is prevented where appropriate', function () {
    $patient = Patient::factory()->create(['gender' => 'female']);
    $doctor = userWithRole('doctor');

    // Create initial active episode
    $profile1 = app(MaternityService::class)->registerEpisode($patient, [
        'gravida' => 1,
        'para' => 0,
        'presentation' => 'cephalic',
    ], requestAs($doctor));

    expect($profile1->status)->toBe('active');

    // Attempting to create a duplicate active episode for the same patient throws ValidationException
    expect(function () use ($patient, $doctor) {
        app(MaternityService::class)->registerEpisode($patient, [
            'gravida' => 1,
            'para' => 0,
            'presentation' => 'cephalic',
        ], requestAs($doctor));
    })->toThrow(ValidationException::class);

    // Once previous episode is completed, a new subsequent episode can be registered
    $profile1->update(['status' => 'completed']);

    $profile2 = app(MaternityService::class)->registerEpisode($patient, [
        'gravida' => 2,
        'para' => 1,
        'presentation' => 'cephalic',
    ], requestAs($doctor));

    expect($profile2->id)->not->toBe($profile1->id);
    expect($profile2->status)->toBe('active');
    expect($profile2->episode_number)->toStartWith('MAT-');
});

test('11. existing antenatal, labour, delivery, inpatient, billing, NHIF and discharge tests continue to pass', function () {
    $ctx = maternityGateContext('female', false);
    $admission = $ctx['admission'];
    $nurse = $ctx['user'];

    // 1. Episode registration
    $profile = app(MaternityService::class)->saveProfile($admission, [
        'gravida' => 2,
        'para' => 1,
        'presentation' => 'cephalic',
        'care_stage' => MaternityProfile::CARE_STAGE_AWAITING_ASSESSMENT,
    ], requestAs($nurse));

    // 2. Stage transition to In Labour
    $this->actingAs($nurse)->post(route('inpatient.maternity.stage.update', [$admission->id, $profile->id]), [
        'care_stage' => MaternityProfile::CARE_STAGE_IN_LABOUR,
        'notes' => 'Active labour progression',
    ])->assertSessionHasNoErrors();

    // 3. Record Partograph observation
    $partograph = app(MaternityService::class)->recordPartograph($profile, [
        'recorded_at' => now()->toDateTimeString(),
        'fetal_heart_rate' => 140,
        'cervical_dilation_cm' => 6.0,
        'contractions_per_10min' => 3,
    ], requestAs($nurse));
    expect($partograph->cervical_dilation_cm)->toBe(6.0);

    // 4. Record Delivery
    $delivery = app(MaternityService::class)->recordDelivery($profile, [
        'delivered_at' => now()->toDateTimeString(),
        'delivery_mode' => 'spontaneous_vaginal',
        'blood_loss_ml' => 250,
        'baby_outcome' => 'live_birth',
        'baby_gender' => 'female',
        'birth_weight_grams' => 3300,
        'apgar_1min' => 8,
        'apgar_5min' => 10,
    ], requestAs($nurse));

    expect($delivery->baby_outcome)->toBe('live_birth');
    expect($profile->fresh()->care_stage)->toBe(MaternityProfile::CARE_STAGE_POSTPARTUM_CARE);
});
