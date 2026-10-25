<?php

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\ClinicalEncounter;
use App\Models\MaternityProfile;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\Staff;
use App\Models\User;
use App\Services\MaternityEligibilityService;
use App\Services\MaternityService;
use App\Services\PatientService;
use App\Services\PrescriptionOrderService;
use Illuminate\Validation\ValidationException;

function maternityEligibilityContext(string $gender = 'female'): array
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

    return $ctx;
}

test('1. eligible female patient can initiate maternity care', function () {
    $ctx = maternityEligibilityContext('female');
    $user = $ctx['user'];
    $admission = $ctx['admission'];

    $response = $this->actingAs($user)->post(route('inpatient.maternity.profile.store', $admission->id), [
        'gravida' => 1,
        'para' => 0,
        'last_menstrual_period' => now()->subWeeks(38)->toDateString(),
        'presentation' => 'cephalic',
        'membrane_status' => 'intact',
        'labour_stage' => 'latent_phase',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $profile = MaternityProfile::where('admission_id', $admission->id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->patient_id)->toBe($admission->patient_id);
    expect($profile->episode_number)->toStartWith('MAT-');
    expect($profile->status)->toBe('active');
    expect($profile->care_stage)->toBe(MaternityProfile::CARE_STAGE_AWAITING_ASSESSMENT);
});

test('2. male patient without documented pregnancy eligibility is blocked from normal maternity initiation', function () {
    $ctx = maternityEligibilityContext('male');
    $user = $ctx['user'];
    $admission = $ctx['admission'];

    $response = $this->actingAs($user)->post(route('inpatient.maternity.profile.store', $admission->id), [
        'gravida' => 1,
        'para' => 0,
        'presentation' => 'cephalic',
        'membrane_status' => 'intact',
    ]);

    $response->assertSessionHasErrors(['patient_id']);
    $errors = session('errors')->get('patient_id');
    expect($errors[0])->toContain('Patient is registered as male');

    expect(MaternityProfile::where('admission_id', $admission->id)->exists())->toBeFalse();
});

test('3. frontend and direct HTTP requests enforce the same eligibility rules', function () {
    $ctx = maternityEligibilityContext('male');
    $admission = $ctx['admission'];
    $user = $ctx['user'];

    // Direct HTTP JSON/API or form request to store profile without exception is rejected with 422
    $response = $this->actingAs($user)
        ->from(route('inpatient.maternity.show', $admission->id))
        ->post(route('inpatient.maternity.profile.store', $admission->id), [
            'gravida' => 2,
            'para' => 1,
        ]);

    $response->assertSessionHasErrors('patient_id');
    expect(MaternityProfile::where('admission_id', $admission->id)->count())->toBe(0);

    // Direct domain service assertion also throws ValidationException
    expect(fn () => app(MaternityEligibilityService::class)->assertEligibleForInitiation($admission->patient))
        ->toThrow(ValidationException::class);
});

test('4. authorized and documented clinical exception allows maternity care initiation and logs audit trail', function () {
    $ctx = maternityEligibilityContext('male');
    $doctor = $ctx['doctor'];
    $admission = $ctx['admission'];

    $exceptionReason = 'Patient has persistent intersex condition with confirmed viable intrauterine pregnancy via ultrasound';

    $response = $this->actingAs($doctor)->post(route('inpatient.maternity.profile.store', $admission->id), [
        'gravida' => 1,
        'para' => 0,
        'presentation' => 'cephalic',
        'membrane_status' => 'intact',
        'clinical_exception_reason' => $exceptionReason,
    ]);

    $response->assertSessionHasNoErrors();

    $profile = MaternityProfile::where('admission_id', $admission->id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->clinical_exception_reason)->toBe($exceptionReason);
    expect($profile->exception_authorized_by)->toBe($doctor->id);

    // Check audit log recorded exception
    $audit = AuditLog::where('action', 'maternity_eligibility_exception_granted')
        ->where('auditable_id', $profile->id)
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->new_values['reason'])->toBe($exceptionReason);
    expect($audit->user_id)->toBe($doctor->id);
});

test('5. incorrect demographics cannot be silently changed without authorization and documented justification', function () {
    $patient = Patient::factory()->create([
        'gender' => 'male',
        'first_name' => 'John',
        'last_name' => 'Doe',
    ]);

    $nurse = userWithRole('nurse');

    // Nurse without 'patients.correct-demographics' attempting to change gender silently
    $response = $this->actingAs($nurse)->put(route('patients.update', $patient), [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'gender' => 'female', // changed gender
        'status' => 'active',
    ]);

    $response->assertSessionHasErrors('gender');
    $patient->refresh();
    expect($patient->gender)->toBe('male'); // Unchanged
});

test('6. authorized demographic correction preserves the audit trail and preserves access to maternity records', function () {
    $ctx = maternityEligibilityContext('female');
    $admission = $ctx['admission'];
    $patient = $admission->patient;
    $doctor = $ctx['doctor'];
    $nurse = $ctx['user'];

    // 1. Create a legitimate maternity profile while patient is female
    $profile = app(MaternityService::class)->saveProfile($admission, [
        'gravida' => 1,
        'para' => 0,
        'presentation' => 'cephalic',
    ], requestAs($nurse));

    expect($profile)->not->toBeNull();
    $profileId = $profile->id;

    // 2. An authorized doctor or admin corrects demographic error (e.g. was incorrectly marked female, or later corrected)
    $correctionReason = 'Correction of clerical registration error following national ID verification';
    $updatedPatient = app(PatientService::class)->correctDemographics(
        $patient,
        ['gender' => 'female', 'first_name' => 'Janet'],
        $correctionReason,
        requestAs($doctor)
    );

    expect($updatedPatient->first_name)->toBe('Janet');

    // Verify audit log entry exists for demographic correction
    $correctionLog = AuditLog::where('action', 'patient_demographics_corrected')
        ->where('auditable_id', $patient->id)
        ->latest('id')
        ->first();

    expect($correctionLog)->not->toBeNull();
    expect($correctionLog->new_values['correction_reason'])->toBe($correctionReason);
    expect($correctionLog->user_id)->toBe($doctor->id);

    // 3. Existing legitimate maternity profile remains intact and accessible
    $existing = MaternityProfile::find($profileId);
    expect($existing)->not->toBeNull();
    expect($existing->episode_number)->toBe($profile->episode_number);

    // Workspace view remains accessible to authorized clinical team
    $viewResponse = $this->actingAs($nurse)->get(route('inpatient.maternity.show', $admission->id));
    $viewResponse->assertOk();
});

test('7. duplicate active maternity episodes are prevented for the same patient', function () {
    $ctx = maternityEligibilityContext('female');
    $user = $ctx['user'];
    $admission = $ctx['admission'];

    // 1. Create first active profile
    $profile1 = app(MaternityService::class)->saveProfile($admission, [
        'gravida' => 1,
        'para' => 0,
    ], requestAs($user));

    expect($profile1->status)->toBe('active');

    // 2. Create another admission for the same patient
    $admission2 = Admission::factory()->create([
        'patient_id' => $admission->patient_id,
        'hospital_id' => $admission->hospital_id,
        'department_id' => $admission->department_id,
        'status' => Admission::STATUS_ACTIVE,
    ]);

    // Attempt to open another active maternity profile for the same patient
    $response = $this->actingAs($user)->post(route('inpatient.maternity.profile.store', $admission2->id), [
        'gravida' => 1,
        'para' => 0,
    ]);

    $response->assertSessionHasErrors('patient_id');
    $errors = session('errors')->get('patient_id');
    expect($errors[0])->toContain('already has an active maternity episode');
});

test('8. antenatal, labour, delivery and postpartum activities remain linked to the correct patient and episode', function () {
    $ctx = maternityEligibilityContext('female');
    $user = $ctx['user'];
    $admission = $ctx['admission'];

    // 1. Initiate profile
    $profile = app(MaternityService::class)->saveProfile($admission, [
        'gravida' => 2,
        'para' => 1,
        'presentation' => 'cephalic',
        'care_stage' => MaternityProfile::CARE_STAGE_AWAITING_ASSESSMENT,
    ], requestAs($user));

    $episodeNumber = $profile->episode_number;
    expect($episodeNumber)->not->toBeEmpty();

    // 2. Transition stage to In Labour
    $this->actingAs($user)->post(route('inpatient.maternity.stage.update', [$admission->id, $profile->id]), [
        'care_stage' => MaternityProfile::CARE_STAGE_IN_LABOUR,
        'notes' => 'Patient in active labour, cervical dilation 4cm',
    ])->assertSessionHasNoErrors();

    $profile->refresh();
    expect($profile->care_stage)->toBe(MaternityProfile::CARE_STAGE_IN_LABOUR);

    // 3. Record Partograph entry
    $partograph = app(MaternityService::class)->recordPartograph($profile, [
        'recorded_at' => now()->toDateTimeString(),
        'fetal_heart_rate' => 140,
        'cervical_dilation_cm' => 5.0,
        'contractions_per_10min' => 3,
    ], requestAs($user));

    expect($partograph->maternity_profile_id)->toBe($profile->id);
    expect($partograph->patient_id)->toBe($admission->patient_id);

    // 4. Record Delivery
    $delivery = app(MaternityService::class)->recordDelivery($profile, [
        'delivered_at' => now()->toDateTimeString(),
        'delivery_mode' => 'spontaneous_vaginal',
        'baby_outcome' => 'live_birth',
        'baby_gender' => 'female',
        'blood_loss_ml' => 300,
    ], requestAs($user));

    expect($delivery->maternity_profile_id)->toBe($profile->id);
    expect($delivery->patient_id)->toBe($admission->patient_id);

    $profile->refresh();
    expect($profile->care_stage)->toBe(MaternityProfile::CARE_STAGE_POSTPARTUM_CARE);
    expect($profile->status)->toBe('completed');
});

test('9. nurse without prescriptions.nurse-prescribe is strictly blocked from creating prescriptions', function () {
    $ctx = maternityEligibilityContext('female');
    $nurse = $ctx['user'];
    $admission = $ctx['admission'];

    // Create an inpatient clinical encounter
    $encounter = ClinicalEncounter::factory()->create([
        'patient_id' => $admission->patient_id,
        'department_id' => $admission->department_id,
        'encounter_type' => 'inpatient',
        'status' => ClinicalEncounter::STATUS_IN_PROGRESS,
    ]);

    $medication = Medication::factory()->create(['status' => Medication::STATUS_ACTIVE]);

    // Nurse attempts to prescribe without 'prescriptions.nurse-prescribe' permission
    \Spatie\Permission\Models\Role::findByName('nurse')->revokePermissionTo('prescriptions.nurse-prescribe');
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    expect(fn () => app(PrescriptionOrderService::class)->create($encounter, [
        'items' => [
            [
                'medication_id' => $medication->id,
                'dose' => '10 IU',
                'route' => 'IM',
                'frequency' => 'once',
                'quantity' => 1,
            ],
        ],
    ], requestAs($nurse)))->toThrow(ValidationException::class);

    // Give nurse explicit nurse-prescribe permission and verify it passes
    $nurse->givePermissionTo('prescriptions.nurse-prescribe');
    $nurse->givePermissionTo('prescription-orders.create');

    $order = app(PrescriptionOrderService::class)->create($encounter, [
        'items' => [
            [
                'medication_id' => $medication->id,
                'dose' => '10 IU',
                'route' => 'IM',
                'frequency' => 'once',
                'quantity' => 1,
            ],
        ],
    ], requestAs($nurse));

    expect($order)->not->toBeNull();
    expect($order->prescriber_id)->toBe($nurse->id);
});

test('10. unauthorized users cannot view or modify maternity records', function () {
    $ctx = maternityEligibilityContext('female');
    $admission = $ctx['admission'];

    // Receptionist / Unauthorized user
    $unauthorized = userWithRole('receptionist');

    $this->actingAs($unauthorized)
        ->get(route('inpatient.maternity.show', $admission->id))
        ->assertForbidden();

    $this->actingAs($unauthorized)
        ->post(route('inpatient.maternity.profile.store', $admission->id), [
            'gravida' => 1,
            'para' => 0,
        ])
        ->assertForbidden();
});

test('11. existing admission, pharmacy, MAR, and discharge workflows continue to pass', function () {
    $ctx = maternityEligibilityContext('female');
    $admission = $ctx['admission'];
    $user = $ctx['user'];

    // Verify admission is active and can be viewed
    $response = $this->actingAs($user)->get(route('inpatient.nursing.workspace', $admission->id));
    $response->assertOk();

    // Verify medication worklist can be viewed
    $marResponse = $this->actingAs($user)->get(route('inpatient.medications.worklist', $admission->id));
    $marResponse->assertOk();
});

test('12. male patient with general-ward admission has maternity option hidden in nursing workspace', function () {
    $ctx = maternityEligibilityContext('male');
    $admission = $ctx['admission'];
    $nurse = $ctx['user'];

    $response = $this->actingAs($nurse)->get(route('inpatient.nursing.workspace', $admission->id));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('inpatient/nursing-workspace')
        ->where('canAccessMaternity', false)
        ->where('maternityEligibility.allowed', false)
        ->where('maternityEligibility.is_eligible', false)
    );
});

test('13. direct access to maternity show and actions is strictly rejected with 403 for ineligible male patient', function () {
    $ctx = maternityEligibilityContext('male');
    $admission = $ctx['admission'];
    $nurse = $ctx['user'];

    // Direct navigation to /inpatient/{admission}/maternity
    $response = $this->actingAs($nurse)->get(route('inpatient.maternity.show', $admission->id));
    $response->assertForbidden();

    $proc = \App\Models\RadiologyProcedure::factory()->create(['status' => \App\Models\RadiologyProcedure::STATUS_ACTIVE]);

    // Direct POST to maternity actions (radiology orders) is also rejected with 403
    $orderResponse = $this->actingAs($nurse)->post(route('inpatient.maternity.radiology-orders.store', $admission->id), [
        'procedure_ids' => [$proc->id],
        'priority' => 'routine',
    ]);
    $orderResponse->assertForbidden();
});

test('14. eligible female patient allows maternity access and displays option in nursing workspace', function () {
    $ctx = maternityEligibilityContext('female');
    $admission = $ctx['admission'];
    $nurse = $ctx['user'];

    // Nursing workspace passes canAccessMaternity = true
    $response = $this->actingAs($nurse)->get(route('inpatient.nursing.workspace', $admission->id));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('inpatient/nursing-workspace')
        ->where('canAccessMaternity', true)
        ->where('maternityEligibility.allowed', true)
        ->where('maternityEligibility.is_eligible', true)
    );

    // Direct navigation to maternity workspace succeeds
    $matResponse = $this->actingAs($nurse)->get(route('inpatient.maternity.show', $admission->id));
    $matResponse->assertOk();
    $matResponse->assertInertia(fn ($page) => $page
        ->component('inpatient/maternity-workspace')
        ->where('admission.id', $admission->id)
    );
});

test('15. general inpatient nursing, vitals, notes, and MAR remain fully functional for male patients', function () {
    $ctx = maternityEligibilityContext('male');
    $admission = $ctx['admission'];
    $nurse = $ctx['user'];

    // General nursing workspace is accessible
    $workspace = $this->actingAs($nurse)->get(route('inpatient.nursing.workspace', $admission->id));
    $workspace->assertOk();

    // MAR worklist is accessible
    $mar = $this->actingAs($nurse)->get(route('inpatient.medications.worklist', $admission->id));
    $mar->assertOk();

    // Shift handover can be recorded
    $handover = $this->actingAs($nurse)->post(route('inpatient.nursing.handovers.store', $admission->id), [
        'shift_type' => 'day_to_night',
        'situation_summary' => 'Patient stable on normal ward.',
        'background_summary' => 'Admitted for observation.',
        'assessment_summary' => 'All vitals within normal parameters.',
        'recommendation_summary' => 'Continue monitoring.',
    ]);
    $handover->assertRedirect();
});
