<?php

use App\Models\Admission;
use App\Models\Department;
use App\Models\Medication;
use App\Models\MedicationAdministration;
use App\Models\NursingHandover;
use App\Models\ObservationEscalation;
use App\Models\PatientAllergy;
use App\Models\PrescriptionOrder;
use App\Models\PrescriptionOrderItem;
use App\Models\Staff;
use App\Models\WoundCareEvent;
use App\Services\InpatientClinicalService;
use App\Services\MedicationAdministrationService;
use App\Services\VitalSignsService;
use Illuminate\Validation\ValidationException;

function nursingTestContext(): array
{
    $ctx = activeAdmission();
    $admission = $ctx['admission'];
    $practitioner = $ctx['practitioner'];
    $user = $ctx['user'];

    // Ensure user has staff record mapped to the admission's hospital & department
    $staff = Staff::factory()->create([
        'user_id' => $user->id,
        'hospital_id' => $admission->hospital_id,
        'department_id' => $admission->department_id,
        'status' => 'active',
    ]);

    $encounter = app(InpatientClinicalService::class)->openEncounter(
        $admission,
        $practitioner->id,
        requestAs($user),
    );

    $medication = Medication::factory()->create([
        'generic_name' => 'Amoxicillin',
        'brand_name' => 'Amoxil',
    ]);

    $order = PrescriptionOrder::factory()->create([
        'clinical_encounter_id' => $encounter->id,
        'prescriber_id' => $user->id,
        'status' => 'active',
    ]);

    $item = PrescriptionOrderItem::factory()->create([
        'prescription_order_id' => $order->id,
        'medication_id' => $medication->id,
        'medication_name_snapshot' => 'Amoxicillin 500mg Capsule',
        'status' => 'active',
        'route' => 'oral',
        'dose' => '500',
        'dose_unit' => 'mg',
    ]);

    return array_merge($ctx, compact('staff', 'encounter', 'medication', 'order', 'item'));
}

test('nurse with matching department can access nursing dashboard and workspace', function () {
    $ctx = nursingTestContext();
    $user = $ctx['user'];
    $admission = $ctx['admission'];

    $response = $this->actingAs($user)->get(route('inpatient.nursing.dashboard'));
    $response->assertStatus(200);

    $response = $this->actingAs($user)->get(route('inpatient.nursing.workspace', $admission->id));
    $response->assertStatus(200);
});

test('nurse from another department without view-all permission is forbidden from workspace', function () {
    $ctx = nursingTestContext();
    $admission = $ctx['admission'];

    // Create another department and nurse in that other department
    $otherDept = Department::factory()->create(['hospital_id' => $admission->hospital_id]);
    $otherNurse = userWithRole('nurse');
    Staff::factory()->create([
        'user_id' => $otherNurse->id,
        'hospital_id' => $admission->hospital_id,
        'department_id' => $otherDept->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($otherNurse)->get(route('inpatient.nursing.workspace', $admission->id));
    $response->assertStatus(403);
});

test('nurse with inpatient-nursing.view-all-wards can access cross-department admissions', function () {
    $ctx = nursingTestContext();
    $admission = $ctx['admission'];

    $otherDept = Department::factory()->create(['hospital_id' => $admission->hospital_id]);
    $supervisorNurse = userWithRole('nurse');
    $supervisorNurse->givePermissionTo('inpatient-nursing.view-all-wards');

    Staff::factory()->create([
        'user_id' => $supervisorNurse->id,
        'hospital_id' => $admission->hospital_id,
        'department_id' => $otherDept->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($supervisorNurse)->get(route('inpatient.nursing.workspace', $admission->id));
    $response->assertStatus(200);
});

test('recording vital signs with nursing fields persists data and triggers auto-escalation on critical readings', function () {
    $ctx = nursingTestContext();
    $user = $ctx['user'];
    $admission = $ctx['admission'];
    $encounter = $ctx['encounter'];

    // Record critical observation: hypotensive with SpO2 = 85%
    $vital = app(VitalSignsService::class)->recordForAdmission($admission, $encounter, [
        'systolic_mmhg' => 65,
        'diastolic_mmhg' => 38,
        'pulse_bpm' => 155,
        'oxygen_saturation_pct' => 85,
        'respiratory_rate_bpm' => 32,
        'temperature_c' => 38.8,
        'pain_score' => 7,
        'consciousness_level' => 'voice',
        'oxygen_support' => 'nasal_cannula',
        'oxygen_flow_lpm' => 4.0,
        'intake_ml' => 500,
        'output_ml' => 200,
        'observation_notes' => 'Tachycardic and tachypneic, oxygen applied.',
    ], requestAs($user));

    expect($vital->pain_score)->toBe(7);
    expect($vital->consciousness_level)->toBe('voice');
    expect($vital->oxygen_support)->toBe('nasal_cannula');
    expect($vital->intake_ml)->toBe(500);
    expect($vital->output_ml)->toBe(200);

    // Auto escalation should have been generated
    $escalation = ObservationEscalation::where('vital_sign_id', $vital->id)->first();
    expect($escalation)->not->toBeNull();
    expect($escalation->admission_id)->toBe($admission->id);
    expect($escalation->status)->toBe(ObservationEscalation::STATUS_OPEN);
    expect($escalation->reason)->toContain('Critical measurement detected');
});

test('nurse can record and clinician can resolve an observation escalation', function () {
    $ctx = nursingTestContext();
    $user = $ctx['user'];
    $admission = $ctx['admission'];
    $practitioner = $ctx['practitioner'];

    $response = $this->actingAs($user)->post(route('inpatient.nursing.escalations.store', $admission->id), [
        'reason' => 'Patient complaining of severe sudden chest discomfort',
        'notified_practitioner_id' => $practitioner->id,
    ]);
    $response->assertSessionHasNoErrors();

    $escalation = ObservationEscalation::where('admission_id', $admission->id)
        ->where('reason', 'Patient complaining of severe sudden chest discomfort')
        ->first();
    expect($escalation)->not->toBeNull();
    expect($escalation->status)->toBe(ObservationEscalation::STATUS_OPEN);

    // Doctor resolves escalation
    $doctor = userWithRole('doctor');
    $doctor->givePermissionTo('inpatient-escalations.resolve');

    $resolveResponse = $this->actingAs($doctor)->post(route('inpatient.nursing.escalations.resolve', [$admission->id, $escalation->id]), [
        'resolution_note' => 'Attended bedside, ECG done, sublingual nitrates administered, vitals stabilized.',
    ]);
    $resolveResponse->assertSessionHasNoErrors();

    $escalation->refresh();
    expect($escalation->status)->toBe(ObservationEscalation::STATUS_RESOLVED);
    expect($escalation->resolved_by)->toBe($doctor->id);
    expect($escalation->resolution_note)->toContain('Attended bedside');
});

test('nurse can record shift handover with SBAR structure', function () {
    $ctx = nursingTestContext();
    $user = $ctx['user'];
    $admission = $ctx['admission'];

    $incomingNurse = userWithRole('nurse');

    $response = $this->actingAs($user)->post(route('inpatient.nursing.handovers.store', $admission->id), [
        'shift' => 'morning',
        'handover_at' => now()->toDateTimeString(),
        'received_by' => $incomingNurse->id,
        'situation_summary' => 'Day 3 post-op appendectomy, afebrile, vitals stable',
        'pending_tasks' => 'IV fluid change at 16:00, check evening CBC',
        'outstanding_doses' => ['Amoxicillin 500mg due at 14:00'],
    ]);

    $response->assertSessionHasNoErrors();

    $handover = NursingHandover::where('admission_id', $admission->id)->latest()->first();
    expect($handover)->not->toBeNull();
    expect($handover->shift)->toBe('morning');
    expect($handover->recorded_by)->toBe($user->id);
    expect($handover->received_by)->toBe($incomingNurse->id);
    expect($handover->outstanding_doses)->toHaveCount(1);
});

test('nurse can record wound care events with observations', function () {
    $ctx = nursingTestContext();
    $user = $ctx['user'];
    $admission = $ctx['admission'];

    $response = $this->actingAs($user)->post(route('inpatient.nursing.wound-care.store', $admission->id), [
        'performed_at' => now()->toDateTimeString(),
        'site' => 'Midline abdominal incision',
        'indication' => 'Routine post-operative surgical wound dressing',
        'assessment' => 'Slight erythema along lower 2cm of incision, serosanguinous discharge noted.',
        'dressing_performed' => 'Cleaned with normal saline, sterile gauze applied',
        'materials_used' => 'Normal saline 100ml, sterile gauze x 2',
        'pain_score' => 3,
        'concerning_findings' => true,
        'follow_up_instructions' => 'Inform MO if discharge becomes purulent or temperature spikes',
    ]);

    $response->assertSessionHasNoErrors();

    $woundCare = WoundCareEvent::where('admission_id', $admission->id)->first();
    expect($woundCare)->not->toBeNull();
    expect($woundCare->recorded_by)->toBe($user->id);
    expect($woundCare->concerning_findings)->toBeTrue();
    expect($woundCare->site)->toBe('Midline abdominal incision');
});

test('patient allergy blocks MAR administration unless clinical override reason is provided', function () {
    $ctx = nursingTestContext();
    $user = $ctx['user'];
    $admission = $ctx['admission'];
    $item = $ctx['item'];
    $encounter = $ctx['encounter'];

    // 1. Record an allergy to Amoxicillin
    $allergy = PatientAllergy::create([
        'patient_id' => $admission->patient_id,
        'allergen' => 'Amoxicillin',
        'severity' => PatientAllergy::SEVERITY_SEVERE,
        'reaction' => 'Anaphylaxis and generalized urticaria',
        'status' => PatientAllergy::STATUS_ACTIVE,
        'recorded_by' => $user->id,
    ]);

    // 2. Try to administer Amoxicillin without override reason -> expect ValidationException
    $adminDataWithoutOverride = [
        'prescription_order_item_id' => $item->id,
        'clinical_encounter_id' => $encounter->id,
        'status' => MedicationAdministration::STATUS_ADMINISTERED,
        'administered_at' => now()->toDateTimeString(),
        'dose' => '500',
        'dose_unit' => 'mg',
        'route' => 'oral',
        'is_prn' => false,
        'slot_key' => 'dose-slot-1',
    ];

    expect(fn () => app(MedicationAdministrationService::class)->record(
        $admission,
        $adminDataWithoutOverride,
        requestAs($user),
    ))->toThrow(ValidationException::class);

    // 3. Administer with audited override reason -> succeeds
    $adminDataWithOverride = array_merge($adminDataWithoutOverride, [
        'allergy_override_reason' => 'Consultant confirmed tolerance test negative under monitoring.',
    ]);

    $mar = app(MedicationAdministrationService::class)->record(
        $admission,
        $adminDataWithOverride,
        requestAs($user),
    );

    expect($mar->status)->toBe(MedicationAdministration::STATUS_ADMINISTERED);
    expect($mar->allergy_override_reason)->toBe('Consultant confirmed tolerance test negative under monitoring.');

    // 4. Duplicate administration with identical slot_key is rejected
    expect(fn () => app(MedicationAdministrationService::class)->record(
        $admission,
        $adminDataWithOverride,
        requestAs($user),
    ))->toThrow(ValidationException::class);
});

test('MAR administration against cancelled or discontinued prescription item is rejected', function () {
    $ctx = nursingTestContext();
    $user = $ctx['user'];
    $admission = $ctx['admission'];
    $item = $ctx['item'];
    $encounter = $ctx['encounter'];

    $item->update(['status' => 'discontinued']);

    $adminData = [
        'prescription_order_item_id' => $item->id,
        'clinical_encounter_id' => $encounter->id,
        'status' => MedicationAdministration::STATUS_ADMINISTERED,
        'administered_at' => now()->toDateTimeString(),
        'dose' => '500',
        'dose_unit' => 'mg',
        'route' => 'oral',
        'is_prn' => false,
    ];

    expect(fn () => app(MedicationAdministrationService::class)->record(
        $admission,
        $adminData,
        requestAs($user),
    ))->toThrow(ValidationException::class);
});

test('MAR administration for delayed or refused status requires reason', function () {
    $ctx = nursingTestContext();
    $user = $ctx['user'];
    $admission = $ctx['admission'];
    $item = $ctx['item'];
    $encounter = $ctx['encounter'];

    $adminData = [
        'prescription_order_item_id' => $item->id,
        'clinical_encounter_id' => $encounter->id,
        'status' => MedicationAdministration::STATUS_DELAYED,
        'administered_at' => now()->toDateTimeString(),
        'dose' => '500',
        'dose_unit' => 'mg',
        'route' => 'oral',
        'is_prn' => false,
        // reason omitted
    ];

    expect(fn () => app(MedicationAdministrationService::class)->record(
        $admission,
        $adminData,
        requestAs($user),
    ))->toThrow(ValidationException::class);

    // Provide reason
    $adminData['reason'] = 'Patient currently in radiology undergoing CT scan';
    $mar = app(MedicationAdministrationService::class)->record(
        $admission,
        $adminData,
        requestAs($user),
    );

    expect($mar->status)->toBe(MedicationAdministration::STATUS_DELAYED);
    expect($mar->reason)->toBe('Patient currently in radiology undergoing CT scan');
});
