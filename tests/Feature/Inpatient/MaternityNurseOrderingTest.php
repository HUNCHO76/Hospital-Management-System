<?php

use App\Models\Admission;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryTest;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\PrescriptionOrder;
use App\Models\Staff;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

function setupCashMaternityNurseContext($testCase): array
{
    // Seed roles and permissions so the nurse role has updated permissions
    $testCase->seed(PermissionSeeder::class);
    $testCase->seed(RoleSeeder::class);

    $hospital = Hospital::factory()->create();

    $maternityDept = Department::factory()->create([
        'hospital_id' => $hospital->id,
        'code' => 'MATERNITY',
        'name' => 'Maternity Department',
    ]);

    // Maternity nurse
    $nurse = userWithRole('nurse');
    Staff::factory()->create([
        'user_id' => $nurse->id,
        'hospital_id' => $hospital->id,
        'department_id' => $maternityDept->id,
        'status' => 'active',
    ]);

    // Cash maternity patient (no NHIF insurance)
    $patient = Patient::factory()->create(['gender' => 'female']);
    $admission = Admission::factory()->create([
        'hospital_id' => $hospital->id,
        'patient_id' => $patient->id,
        'department_id' => $maternityDept->id,
        'ward_type' => 'maternity',
        'status' => Admission::STATUS_ACTIVE,
    ]);

    $labTest = activeLaboratoryTest();

    $medication = activeMedication();

    return compact('hospital', 'maternityDept', 'nurse', 'patient', 'admission', 'labTest', 'medication');
}

test('nurse can view maternity workspace with ordering props and permissions', function () {
    $ctx = setupCashMaternityNurseContext($this);

    $response = $this->actingAs($ctx['nurse'])
        ->get(route('inpatient.maternity.show', $ctx['admission']->id));

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('inpatient/maternity-workspace')
        ->has('admission')
        ->has('laboratoryOrders')
        ->has('prescriptionOrders')
        ->where('canOrderLabs', true)
        ->where('canPrescribe', true)
    );
});

test('nurse can place laboratory test order for cash maternity patient and auto-resolves inpatient encounter', function () {
    $ctx = setupCashMaternityNurseContext($this);

    $payload = [
        'test_ids' => [$ctx['labTest']->id],
        'priority' => 'urgent',
        'clinical_indication' => 'Maternity admission routine screen for cash patient',
        'notes' => 'Pre-delivery maternal CBC check',
    ];

    $response = $this->actingAs($ctx['nurse'])
        ->post(route('inpatient.maternity.laboratory-orders.store', $ctx['admission']->id), $payload);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();
    $response->assertSessionHas('success');

    // Verify order was created in DB
    $order = LaboratoryOrder::whereHas('clinicalEncounter', fn ($q) => $q->where('admission_id', $ctx['admission']->id))->first();
    expect($order)->not->toBeNull();
    expect($order->priority)->toBe('urgent');
    expect($order->clinical_indication)->toBe('Maternity admission routine screen for cash patient');
    expect($order->items()->count())->toBe(1);
    expect($order->items()->first()->laboratory_test_id)->toBe($ctx['labTest']->id);

    // Verify encounter was created/linked to admission
    expect($order->clinicalEncounter)->not->toBeNull();
    expect($order->clinicalEncounter->encounter_type)->toBe('inpatient');
    expect($order->clinicalEncounter->admission_id)->toBe($ctx['admission']->id);
});

test('nurse can write medication prescription to pharmacy for cash maternity patient', function () {
    $ctx = setupCashMaternityNurseContext($this);

    $payload = [
        'clinical_notes' => 'Maternity pain management and oxytocic administration',
        'items' => [
            [
                'medication_id' => $ctx['medication']->id,
                'dose' => '10 IU',
                'route' => 'intramuscular',
                'frequency' => 'stat',
                'duration' => 1,
                'duration_unit' => 'days',
                'quantity' => 1,
                'instructions' => 'Administer immediately post-placental delivery',
            ],
        ],
    ];

    $response = $this->actingAs($ctx['nurse'])
        ->post(route('inpatient.maternity.prescriptions.store', $ctx['admission']->id), $payload);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();
    $response->assertSessionHas('success');

    // Verify prescription order in DB
    $prescriptionOrder = PrescriptionOrder::whereHas('clinicalEncounter', fn ($q) => $q->where('admission_id', $ctx['admission']->id))->first();
    expect($prescriptionOrder)->not->toBeNull();
    expect($prescriptionOrder->items()->count())->toBe(1);

    $item = $prescriptionOrder->items()->first();
    expect($item->medication_id)->toBe($ctx['medication']->id);
    expect($item->dose)->toBe('10 IU');
    expect($item->route)->toBe('intramuscular');
    expect($item->frequency)->toBe('stat');
    expect($item->quantity)->toBe(1);
    expect($item->instructions)->toBe('Administer immediately post-placental delivery');

    // Cash prescription proceeds without NHIF authorization blocking
    expect($prescriptionOrder->clinicalEncounter->admission_id)->toBe($ctx['admission']->id);
});
