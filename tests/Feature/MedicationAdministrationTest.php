<?php

use App\Models\Admission;
use App\Models\ClinicalEncounter;
use App\Models\Consultation;
use App\Models\Department;
use App\Models\DispensingItem;
use App\Models\Hospital;
use App\Models\MedicationAdministration;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\PrescriptionOrderItem;
use App\Models\Staff;
use App\Models\User;
use App\Services\AdmissionService;
use App\Services\DispensingService;
use App\Services\InpatientClinicalService;
use App\Services\MedicationAdministrationService;
use App\Services\PrescriptionOrderService;
use App\Services\PrescriptionVerificationService;
use Illuminate\Validation\ValidationException;

// ---------------------------------------------------------------------------
// Shared helper — builds active admission + inpatient encounter + prescription
// ---------------------------------------------------------------------------

/**
 * Builds an active admission, opens an inpatient encounter, writes a
 * prescription order against it (no consultation required — Step 75's
 * critical bug fix), verifies + dispenses it. Returns everything needed
 * for MAR tests.
 *
 * @return array{
 *     hospital: Hospital,
 *     patient: Patient,
 *     admission: Admission,
 *     encounter: ClinicalEncounter,
 *     item: PrescriptionOrderItem,
 *     dispensingItem: DispensingItem,
 *     nurse: User,
 * }
 */
function inpatientReadyForMAR(): array
{
    $hospital = Hospital::factory()->create();
    $department = Department::factory()->create(['hospital_id' => $hospital->id]);
    $staff = Staff::factory()->create(['hospital_id' => $hospital->id]);
    $practitioner = Practitioner::factory()->create(['staff_id' => $staff->id]);
    $patient = Patient::factory()->create();

    $nurse = userWithRole('nurse');

    // Create + advance admission to active
    $admission = app(AdmissionService::class)->create([
        'hospital_id' => $hospital->id,
        'patient_id' => $patient->id,
        'department_id' => $department->id,
        'practitioner_id' => $practitioner->id,
        'admission_type' => 'emergency',
        'ward_type' => 'General',
        'room_type' => 'Shared',
        'diagnosis_at_admission' => 'Fever',
        'notes' => null,
    ], requestAs($nurse));

    $admin = userWithRole('hospital-admin');
    $admission = app(AdmissionService::class)->startReview($admission, requestAs($admin));
    $admission = app(AdmissionService::class)->approve($admission, null, requestAs($admin));
    $admission = app(AdmissionService::class)->confirm($admission, requestAs($admin));

    // Open inpatient encounter
    $doctor = userWithRole('doctor');
    $encounter = app(InpatientClinicalService::class)->openEncounter(
        $admission,
        $practitioner->id,
        requestAs($doctor),
    );

    // Prescription on inpatient encounter — no consultation required
    $medication = activeMedication();
    receiveStock($medication, $hospital);

    $pharmacist = userWithRole('pharmacist');
    $order = app(PrescriptionOrderService::class)->create($encounter, [
        'items' => [[
            'medication_id' => $medication->id,
            'dose' => '1 tablet',
            'dose_unit' => 'tab',
            'frequency' => 'twice_daily',
            'route' => 'oral',
            'duration' => 5,
            'duration_unit' => 'days',
            'quantity' => 10,
        ]],
    ], requestAs($doctor));

    $item = $order->items->sole();

    // Verify + dispense
    app(PrescriptionVerificationService::class)->verify($order, [], requestAs($pharmacist));
    $dispensing = app(DispensingService::class)->start($order->fresh(), requestAs($pharmacist));
    app(DispensingService::class)->complete($dispensing, [
        'items' => [['prescription_order_item_id' => $item->id, 'dispensed_quantity' => 10]],
    ], requestAs($pharmacist));

    $dispensingItem = $dispensing->fresh()->items->sole();

    return compact('hospital', 'patient', 'admission', 'encounter', 'item', 'dispensingItem', 'nurse');
}

// ---------------------------------------------------------------------------
// 1. PrescriptionOrderService bug fix: inpatient encounters skip consultation
// ---------------------------------------------------------------------------

test('a prescription can be written on an inpatient encounter without a consultation', function () {
    $hospital = Hospital::factory()->create();
    $department = Department::factory()->create(['hospital_id' => $hospital->id]);
    $staff = Staff::factory()->create(['hospital_id' => $hospital->id]);
    $practitioner = Practitioner::factory()->create(['staff_id' => $staff->id]);
    $patient = Patient::factory()->create();

    $nurse = userWithRole('nurse');
    $admission = app(AdmissionService::class)->create([
        'hospital_id' => $hospital->id,
        'patient_id' => $patient->id,
        'department_id' => $department->id,
        'practitioner_id' => $practitioner->id,
        'admission_type' => 'emergency',
        'ward_type' => 'General',
        'room_type' => 'Shared',
        'diagnosis_at_admission' => 'Fever',
        'notes' => null,
    ], requestAs($nurse));

    $admin = userWithRole('hospital-admin');
    $admission = app(AdmissionService::class)->startReview($admission, requestAs($admin));
    $admission = app(AdmissionService::class)->approve($admission, null, requestAs($admin));
    $admission = app(AdmissionService::class)->confirm($admission, requestAs($admin));

    $doctor = userWithRole('doctor');
    $encounter = app(InpatientClinicalService::class)->openEncounter(
        $admission,
        $practitioner->id,
        requestAs($doctor),
    );

    // This MUST NOT throw — before the bug fix it always threw:
    // "A consultation must be started before a prescription can be written for this encounter."
    $medication = activeMedication();
    $order = app(PrescriptionOrderService::class)->create($encounter, [
        'items' => [[
            'medication_id' => $medication->id,
            'dose' => '500 mg',
            'dose_unit' => 'mg',
            'frequency' => 'once_daily',
            'route' => 'oral',
            'quantity' => 5,
        ]],
    ], requestAs($doctor));

    expect($order->id)->toBeInt();
    expect($order->items)->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// 2. Recording an administration
// ---------------------------------------------------------------------------

test('a nurse can record a medication as administered', function () {
    $ctx = inpatientReadyForMAR();

    $marBefore = MedicationAdministration::count();

    $mar = app(MedicationAdministrationService::class)->record($ctx['admission'], [
        'prescription_order_item_id' => $ctx['item']->id,
        'clinical_encounter_id' => $ctx['encounter']->id,
        'dispensing_item_id' => $ctx['dispensingItem']->id,
        'status' => MedicationAdministration::STATUS_ADMINISTERED,
        'administered_at' => now()->toDateTimeString(),
        'dose' => '1 tablet',
        'dose_unit' => 'tab',
        'route' => 'oral',
        'is_prn' => false,
    ], requestAs($ctx['nurse']));

    expect(MedicationAdministration::count())->toBe($marBefore + 1);
    expect($mar->status)->toBe(MedicationAdministration::STATUS_ADMINISTERED);
    expect($mar->admission_id)->toBe($ctx['admission']->id);
});

test('holding a dose records the hold with a reason', function () {
    $ctx = inpatientReadyForMAR();

    $mar = app(MedicationAdministrationService::class)->record($ctx['admission'], [
        'prescription_order_item_id' => $ctx['item']->id,
        'clinical_encounter_id' => $ctx['encounter']->id,
        'status' => MedicationAdministration::STATUS_HELD,
        'administered_at' => now()->toDateTimeString(),
        'dose' => '1 tablet',
        'dose_unit' => 'tab',
        'route' => 'oral',
        'reason' => 'patient_npo',
    ], requestAs($ctx['nurse']));

    expect($mar->status)->toBe(MedicationAdministration::STATUS_HELD);
    expect($mar->reason)->toBe('patient_npo');
});

test('patient refusal is recorded correctly', function () {
    $ctx = inpatientReadyForMAR();

    $mar = app(MedicationAdministrationService::class)->record($ctx['admission'], [
        'prescription_order_item_id' => $ctx['item']->id,
        'clinical_encounter_id' => $ctx['encounter']->id,
        'status' => MedicationAdministration::STATUS_REFUSED,
        'administered_at' => now()->toDateTimeString(),
        'dose' => '1 tablet',
        'dose_unit' => 'tab',
        'route' => 'oral',
        'reason' => 'Patient declined dose.',
    ], requestAs($ctx['nurse']));

    expect($mar->status)->toBe(MedicationAdministration::STATUS_REFUSED);
});

test('a missed dose is recorded', function () {
    $ctx = inpatientReadyForMAR();

    $mar = app(MedicationAdministrationService::class)->record($ctx['admission'], [
        'prescription_order_item_id' => $ctx['item']->id,
        'clinical_encounter_id' => $ctx['encounter']->id,
        'status' => MedicationAdministration::STATUS_MISSED,
        'administered_at' => now()->toDateTimeString(),
        'dose' => '1 tablet',
        'dose_unit' => 'tab',
        'route' => 'oral',
        'reason' => 'Patient off ward.',
    ], requestAs($ctx['nurse']));

    expect($mar->status)->toBe(MedicationAdministration::STATUS_MISSED);
});

// ---------------------------------------------------------------------------
// 3. IDOR: item must belong to the admission
// ---------------------------------------------------------------------------

test('recording administration for an item from a different admission is rejected', function () {
    $ctx1 = inpatientReadyForMAR();
    $ctx2 = inpatientReadyForMAR();

    // ctx1 admission + ctx2 item → should fail
    app(MedicationAdministrationService::class)->record($ctx1['admission'], [
        'prescription_order_item_id' => $ctx2['item']->id,
        'clinical_encounter_id' => $ctx1['encounter']->id,
        'status' => MedicationAdministration::STATUS_ADMINISTERED,
        'administered_at' => now()->toDateTimeString(),
        'dose' => '1 tablet',
        'dose_unit' => 'tab',
        'route' => 'oral',
    ], requestAs($ctx1['nurse']));
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// 4. Discharged admission blocks MAR
// ---------------------------------------------------------------------------

test('recording a MAR on a discharged admission is rejected', function () {
    $ctx = inpatientReadyForMAR();
    $admin = userWithRole('hospital-admin');

    app(AdmissionService::class)->discharge($ctx['admission'], [
        'discharge_type' => 'routine',
        'discharge_reason' => 'Recovered',
    ], requestAs($admin));

    app(MedicationAdministrationService::class)->record($ctx['admission']->fresh(), [
        'prescription_order_item_id' => $ctx['item']->id,
        'clinical_encounter_id' => $ctx['encounter']->id,
        'status' => MedicationAdministration::STATUS_ADMINISTERED,
        'administered_at' => now()->toDateTimeString(),
        'dose' => '1 tablet',
        'dose_unit' => 'tab',
        'route' => 'oral',
    ], requestAs($ctx['nurse']));
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// 5. Amendment chain
// ---------------------------------------------------------------------------

test('a MAR record can be corrected — a new row is created and points to the original', function () {
    $ctx = inpatientReadyForMAR();

    $original = app(MedicationAdministrationService::class)->record($ctx['admission'], [
        'prescription_order_item_id' => $ctx['item']->id,
        'clinical_encounter_id' => $ctx['encounter']->id,
        'status' => MedicationAdministration::STATUS_ADMINISTERED,
        'administered_at' => now()->toDateTimeString(),
        'dose' => '1 tablet',
        'dose_unit' => 'tab',
        'route' => 'oral',
    ], requestAs($ctx['nurse']));

    $correction = app(MedicationAdministrationService::class)->correct($original, [
        'correction_reason' => 'Wrong dose recorded — correcting to 2 tablets.',
        'status' => MedicationAdministration::STATUS_ADMINISTERED,
        'administered_at' => $original->administered_at->toDateTimeString(),
        'dose' => '2 tablets',
        'dose_unit' => 'tab',
        'route' => 'oral',
    ], requestAs($ctx['nurse']));

    expect($correction->corrects_id)->toBe($original->id);
    expect($correction->dose)->toBe('2 tablets');
    expect($original->fresh()->dose)->toBe('1 tablet'); // original untouched
});

test('correcting a correction row is rejected', function () {
    $ctx = inpatientReadyForMAR();

    $original = app(MedicationAdministrationService::class)->record($ctx['admission'], [
        'prescription_order_item_id' => $ctx['item']->id,
        'clinical_encounter_id' => $ctx['encounter']->id,
        'status' => MedicationAdministration::STATUS_ADMINISTERED,
        'administered_at' => now()->toDateTimeString(),
        'dose' => '1 tablet',
        'dose_unit' => 'tab',
        'route' => 'oral',
    ], requestAs($ctx['nurse']));

    $correction = app(MedicationAdministrationService::class)->correct($original, [
        'correction_reason' => 'First fix.',
        'status' => MedicationAdministration::STATUS_ADMINISTERED,
        'administered_at' => $original->administered_at->toDateTimeString(),
        'dose' => '2 tablets',
        'dose_unit' => 'tab',
        'route' => 'oral',
    ], requestAs($ctx['nurse']));

    // Attempting to correct the correction itself must fail
    app(MedicationAdministrationService::class)->correct($correction, [
        'correction_reason' => 'Second fix — should be rejected.',
        'status' => MedicationAdministration::STATUS_ADMINISTERED,
        'administered_at' => now()->toDateTimeString(),
        'dose' => '3 tablets',
        'dose_unit' => 'tab',
        'route' => 'oral',
    ], requestAs($ctx['nurse']));
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// 6. Audit log entry is created
// ---------------------------------------------------------------------------

test('recording a MAR administration creates an audit log entry', function () {
    $ctx = inpatientReadyForMAR();

    app(MedicationAdministrationService::class)->record($ctx['admission'], [
        'prescription_order_item_id' => $ctx['item']->id,
        'clinical_encounter_id' => $ctx['encounter']->id,
        'status' => MedicationAdministration::STATUS_ADMINISTERED,
        'administered_at' => now()->toDateTimeString(),
        'dose' => '1 tablet',
        'dose_unit' => 'tab',
        'route' => 'oral',
    ], requestAs($ctx['nurse']));

    $this->assertDatabaseHas('audit_logs', ['action' => 'medication_administration.recorded']);
});

// ---------------------------------------------------------------------------
// 7. HTTP: worklist returns 200 for authorized user
// ---------------------------------------------------------------------------

test('the MAR worklist page returns 200 for an authorized nurse', function () {
    $ctx = inpatientReadyForMAR();

    $response = $this->actingAs($ctx['nurse'])
        ->get(route('inpatient.medications.worklist', $ctx['admission']));

    $response->assertOk();
});

test('the MAR worklist is forbidden for unauthenticated users', function () {
    $hospital = Hospital::factory()->create();
    $department = Department::factory()->create(['hospital_id' => $hospital->id]);
    $staff = Staff::factory()->create(['hospital_id' => $hospital->id]);
    $practitioner = Practitioner::factory()->create(['staff_id' => $staff->id]);
    $patient = Patient::factory()->create();
    $nurse = userWithRole('nurse');

    $admission = app(AdmissionService::class)->create([
        'hospital_id' => $hospital->id,
        'patient_id' => $patient->id,
        'department_id' => $department->id,
        'practitioner_id' => $practitioner->id,
        'admission_type' => 'emergency',
        'ward_type' => 'General',
        'room_type' => 'Shared',
        'diagnosis_at_admission' => 'Test',
        'notes' => null,
    ], requestAs($nurse));

    $response = $this->get(route('inpatient.medications.worklist', $admission));

    $response->assertRedirectToRoute('login');
});

// ---------------------------------------------------------------------------
// 8. IDOR: hospital-admin cannot see MAR for another hospital's admission
// ---------------------------------------------------------------------------

test('hospital-admin cannot access the MAR worklist for another hospital admission', function () {
    $ctx = inpatientReadyForMAR();

    // Admin from a different hospital
    $otherHospital = Hospital::factory()->create();
    $adminUser = User::factory()->create();
    Staff::factory()->create(['hospital_id' => $otherHospital->id, 'user_id' => $adminUser->id]);
    $adminUser->assignRole('hospital-admin');

    $response = $this->actingAs($adminUser)
        ->get(route('inpatient.medications.worklist', $ctx['admission']));

    $response->assertForbidden();
});

test('the MAR worklist and clinical profile display medications prescribed during walk-in admitting encounters', function () {
    $hospital = Hospital::factory()->create();
    $department = Department::factory()->create(['hospital_id' => $hospital->id]);
    $staff = Staff::factory()->create(['hospital_id' => $hospital->id]);
    $practitioner = Practitioner::factory()->create(['staff_id' => $staff->id]);
    $patient = Patient::factory()->create();

    $nurse = userWithRole('nurse');
    $doctor = userWithRole('doctor');
    $pharmacist = userWithRole('pharmacist');

    // Create admission
    $admission = app(AdmissionService::class)->create([
        'hospital_id' => $hospital->id,
        'patient_id' => $patient->id,
        'department_id' => $department->id,
        'practitioner_id' => $practitioner->id,
        'admission_type' => 'walk_in',
        'ward_type' => 'General',
        'room_type' => 'Shared',
        'diagnosis_at_admission' => 'Fever',
        'notes' => null,
    ], requestAs($nurse));

    $admin = userWithRole('hospital-admin');
    $admission = app(AdmissionService::class)->startReview($admission, requestAs($admin));
    $admission = app(AdmissionService::class)->approve($admission, null, requestAs($admin));
    $admission = app(AdmissionService::class)->confirm($admission, requestAs($admin));

    // Create a walk-in encounter that originated the admission
    $walkInEncounter = ClinicalEncounter::factory()->create([
        'department_id' => $department->id,
        'patient_id' => $patient->id,
        'practitioner_id' => $practitioner->id,
        'encounter_type' => 'walk_in',
        'status' => ClinicalEncounter::STATUS_IN_PROGRESS,
        'admission_id' => $admission->id,
    ]);
    $admission->update(['clinical_encounter_id' => $walkInEncounter->id]);
    Consultation::create([
        'clinical_encounter_id' => $walkInEncounter->id,
        'practitioner_id' => $practitioner->id,
        'status' => Consultation::STATUS_IN_PROGRESS,
        'started_at' => now(),
        'created_by' => $doctor->id,
    ]);

    $medication = activeMedication();
    receiveStock($medication, $hospital);

    $order = app(PrescriptionOrderService::class)->create($walkInEncounter, [
        'items' => [[
            'medication_id' => $medication->id,
            'dose' => '500mg',
            'dose_unit' => 'mg',
            'frequency' => 'twice_daily',
            'route' => 'oral',
            'duration' => 3,
            'duration_unit' => 'days',
            'quantity' => 6,
        ]],
    ], requestAs($doctor));

    app(PrescriptionVerificationService::class)->verify($order, [], requestAs($pharmacist));
    $dispensing = app(DispensingService::class)->start($order->fresh(), requestAs($pharmacist));
    app(DispensingService::class)->complete($dispensing->fresh(), [
        'items' => [[
            'prescription_order_item_id' => $order->items->sole()->id,
            'dispensed_quantity' => 6,
        ]],
    ], requestAs($pharmacist));

    // Test MAR worklist includes prescription from walk-in admitting encounter
    $response = $this->actingAs($nurse)
        ->get(route('inpatient.medications.worklist', $admission));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('inpatient/medications')
        ->has('prescriptionOrders', 1)
        ->where('prescriptionOrders.0.items.0.medication_id', $medication->id)
    );

    // Test clinical profile passes activeMedications
    $profileResponse = $this->actingAs($doctor)
        ->get(route('inpatient.clinical-profile', $admission));

    $profileResponse->assertOk();
    $profileResponse->assertInertia(fn ($page) => $page
        ->component('inpatient/clinical-profile')
        ->has('activeMedications', 1)
        ->where('activeMedications.0.dose', '500mg')
    );

    // Test recording administration succeeds for walk-in admitting encounter prescription
    $item = $order->items->sole();
    $mar = app(MedicationAdministrationService::class)->record($admission, [
        'prescription_order_item_id' => $item->id,
        'clinical_encounter_id' => $walkInEncounter->id,
        'status' => MedicationAdministration::STATUS_ADMINISTERED,
        'administered_at' => now()->toDateTimeString(),
        'dose' => '500mg',
        'dose_unit' => 'mg',
        'route' => 'oral',
    ], requestAs($nurse));

    expect($mar)->not->toBeNull();
    expect($mar->admission_id)->toBe($admission->id);
    expect($mar->status)->toBe(MedicationAdministration::STATUS_ADMINISTERED);
});
