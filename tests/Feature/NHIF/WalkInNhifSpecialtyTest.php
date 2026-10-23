<?php

/**
 * WalkInNhifSpecialtyTest — Phase 8
 *
 * Scenarios:
 *  1.  NHIF-verified patient: nhif-consultation-services returns scheme's items
 *  2.  Cash patient (no NHIF): endpoint returns empty array
 *  3.  Patient without verified insurance: endpoint returns empty
 *  4.  Submitting nhif_item_code not in scheme → ValidationException from assertEligible
 *  5.  CASH walk-in with no nhif_item_code goes through static path (no exception)
 *  6.  nhif-consultation-services requires authentication
 */

use App\Models\Department;
use App\Models\Hospital;
use App\Models\InsuranceProvider;
use App\Models\NhifSchemeService;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\Practitioner;
use App\Models\ReceptionVisit;
use App\Models\Service;
use App\Models\Staff;
use App\Services\NHIF\NhifSchemeEligibilityService;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

// ---------------------------------------------------------------------------
// Helper — set up an NHIF-verified patient with scheme services
// ---------------------------------------------------------------------------

function nhifWalkInSetup(): array
{
    $hospital = Hospital::factory()->create(['code' => 'FAC-WALK-TEST']);
    $department = Department::factory()->create(['hospital_id' => $hospital->id, 'status' => 'active']);
    $provider = InsuranceProvider::factory()->create(['api_enabled' => true, 'status' => 'active']);

    $patient = Patient::factory()->create();
    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'insurance_provider_id' => $provider->id,
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_authorization_no' => 'AUTH-WALK-001',
        'nhif_scheme_id' => 9001,
    ]);

    // Scheme service for this patient
    $service = NhifSchemeService::factory()->create([
        'scheme_id' => 9001,
        'item_code' => 'CONSULT-GP-NHIF',
        'item_name' => 'General Outpatient Consultation',
        'item_type_id' => 1,
        'type_name' => 'Registration/Consultation Charges',
        'is_active' => true,
        'start_date' => null,
        'end_date' => null,
    ]);

    $actor = userWithRole('receptionist');
    Staff::factory()->create(['user_id' => $actor->id, 'hospital_id' => $hospital->id]);

    return compact('hospital', 'department', 'patient', 'insurance', 'service', 'actor');
}

// ---------------------------------------------------------------------------
// Scenario 1 — NHIF-verified patient returns their scheme's consultation items
// ---------------------------------------------------------------------------

it('nhif-consultation-services returns scheme services for an NHIF-verified patient', function (): void {
    $ctx = nhifWalkInSetup();

    $response = $this->actingAs($ctx['actor'])
        ->getJson(route('reception.nhif-consultation-services').'?patient_id='.$ctx['patient']->id);

    $response->assertOk();
    $data = $response->json();
    expect($data['services'])->not->toBeEmpty();
    expect($data['services'][0]['item_code'])->toBe('CONSULT-GP-NHIF');
    expect($data['insurance'])->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Scenario 2 — Cash patient (no NHIF insurance) returns empty
// ---------------------------------------------------------------------------

it('nhif-consultation-services returns empty for a cash patient', function (): void {
    $ctx = nhifWalkInSetup();
    $cashPatient = Patient::factory()->create();

    $response = $this->actingAs($ctx['actor'])
        ->getJson(route('reception.nhif-consultation-services').'?patient_id='.$cashPatient->id);

    $response->assertOk();
    $data = $response->json();
    expect($data['services'])->toBeEmpty();
    expect($data['insurance'])->toBeNull();
});

// ---------------------------------------------------------------------------
// Scenario 3 — Patient with unverified insurance returns empty
// ---------------------------------------------------------------------------

it('nhif-consultation-services returns empty for patient with unverified insurance', function (): void {
    $ctx = nhifWalkInSetup();
    $unverifiedInsurance = PatientInsurance::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => 'pending',
        'nhif_authorization_no' => null,
        'nhif_scheme_id' => null,
    ]);
    $ctx['insurance']->update(['status' => 'inactive']);

    $response = $this->actingAs($ctx['actor'])
        ->getJson(route('reception.nhif-consultation-services').'?patient_id='.$ctx['patient']->id);

    $response->assertOk();
    $data = $response->json();
    expect($data['services'])->toBeEmpty();
    expect($data['insurance'])->toBeNull();
});

// ---------------------------------------------------------------------------
// Scenario 4 — nhif_item_code not in patient's scheme triggers assertEligible failure
// ---------------------------------------------------------------------------

it('walk-in store rejects nhif_item_code not in the patient scheme', function (): void {
    $ctx = nhifWalkInSetup();

    // Mock NhifSchemeEligibilityService to throw
    $this->mock(NhifSchemeEligibilityService::class)
        ->shouldReceive('assertEligible')
        ->once()
        ->andThrow(ValidationException::withMessages([
            'nhif_item_code' => 'Item NOT-IN-SCHEME is not covered under the patient\'s NHIF scheme.',
        ]));

    $response = $this->actingAs($ctx['actor'])
        ->post(route('reception.walk-in.store'), [
            'patient_id' => $ctx['patient']->id,
            'department_id' => $ctx['department']->id,
            'visit_type' => 'walk_in',
            'nhif_item_code' => 'NOT-IN-SCHEME',
        ]);

    $response->assertSessionHasErrors('nhif_item_code');
    expect(ReceptionVisit::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Scenario 5 — cash walk-in without nhif_item_code goes through the static path
// ---------------------------------------------------------------------------

it('cash walk-in with no nhif_item_code registers successfully via static path', function (): void {
    $ctx = nhifWalkInSetup();
    $cashPatient = Patient::factory()->create();

    $response = $this->actingAs($ctx['actor'])
        ->post(route('reception.walk-in.store'), [
            'patient_id' => $cashPatient->id,
            'department_id' => $ctx['department']->id,
            'visit_type' => 'walk_in',
        ]);

    $response->assertSessionHasNoErrors();
    expect(ReceptionVisit::where('patient_id', $cashPatient->id)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Scenario 6 — endpoint requires authentication
// ---------------------------------------------------------------------------

it('nhif-consultation-services redirects unauthenticated requests', function (): void {
    $this->get(route('reception.nhif-consultation-services').'?patient_id=1')
        ->assertRedirect();
});

// ---------------------------------------------------------------------------
// Scenario 7 — nhif-consultation-services returns resolved service_id
// ---------------------------------------------------------------------------

it('nhif-consultation-services returns resolved service_id', function (): void {
    $ctx = nhifWalkInSetup();
    $hmsService = Service::factory()->create(['code' => 'CONSULT-GP-NHIF', 'category' => 'consultation']);

    $response = $this->actingAs($ctx['actor'])
        ->getJson(route('reception.nhif-consultation-services').'?patient_id='.$ctx['patient']->id);

    $response->assertOk();
    $data = $response->json();
    expect($data['services'][0]['service_id'])->toBe($hmsService->id);
});

// ---------------------------------------------------------------------------
// Scenario 8 — createWalkIn view loads consultationService on practitioners
// ---------------------------------------------------------------------------

it('walk-in create page loads practitioners with consultation_service relation', function (): void {
    $ctx = nhifWalkInSetup();
    $hmsService = Service::factory()->create(['code' => 'CONSULT-101', 'category' => 'consultation']);
    Practitioner::factory()->create([
        'consultation_service_id' => $hmsService->id,
        'nhif_practitioner_no' => 'NHIF-DOC-001',
    ]);

    $response = $this->actingAs($ctx['actor'])
        ->get(route('reception.walk-in.create'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('reception/walk-in/create')
        ->has('practitioners')
    );
});

// ---------------------------------------------------------------------------
// Scenario 9 — walk-in store rejects practitioner with mismatched consultation service
// ---------------------------------------------------------------------------

it('walk-in store rejects practitioner when assigned consultation service does not match selected NHIF service', function (): void {
    $ctx = nhifWalkInSetup();

    Service::factory()->create(['code' => 'CONSULT-GP-NHIF', 'category' => 'consultation']);
    $serviceB = Service::factory()->create(['code' => 'OTHER-CONSULT', 'category' => 'consultation']);

    $practitioner = Practitioner::factory()->create([
        'consultation_service_id' => $serviceB->id,
        'nhif_practitioner_no' => 'PRAC-MISMATCH',
    ]);

    $this->mock(NhifSchemeEligibilityService::class)
        ->shouldReceive('assertEligible')
        ->once()
        ->andReturnNull();

    $response = $this->actingAs($ctx['actor'])
        ->post(route('reception.walk-in.store'), [
            'patient_id' => $ctx['patient']->id,
            'department_id' => $ctx['department']->id,
            'visit_type' => 'walk_in',
            'nhif_item_code' => 'CONSULT-GP-NHIF',
            'practitioner_id' => $practitioner->id,
        ]);

    $response->assertSessionHasErrors('practitioner_id');
});
