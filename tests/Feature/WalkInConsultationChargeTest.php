<?php

use App\Models\ChargeInsuranceAllocation;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\NhifServiceMapping;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\Practitioner;
use App\Models\ReceptionVisit;
use App\Models\Service;
use App\Models\Staff;
use App\Services\NHIF\NhifHttpClient;

// ---------------------------------------------------------------------------
// consultation-price endpoint
// ---------------------------------------------------------------------------

test('consultation-price returns the configured price for a priced practitioner', function () {
    $user = userWithRole('receptionist');
    $ctx = appointmentBookingContext();
    $service = billableConsultationService($ctx['hospital'], ['amount' => 15000]);
    $ctx['practitioner']->update(['consultation_service_id' => $service->id]);

    $response = $this->actingAs($user)->getJson(
        route('reception.consultation-price', [
            'department_id' => $ctx['department']->id,
            'practitioner_id' => $ctx['practitioner']->id,
        ])
    );

    $response->assertOk()
        ->assertJsonPath('price', 15000);
});

test('consultation-price returns null when no service is configured for the practitioner', function () {
    $user = userWithRole('receptionist');
    $ctx = appointmentBookingContext();
    // No consultation_service_id, no default service in catalog.

    $response = $this->actingAs($user)->getJson(
        route('reception.consultation-price', [
            'department_id' => $ctx['department']->id,
            'practitioner_id' => $ctx['practitioner']->id,
        ])
    );

    $response->assertOk()
        ->assertJsonPath('price', null);
});

// ---------------------------------------------------------------------------
// Patient search — has_active_nhif flag
// ---------------------------------------------------------------------------

test('patient searchJson includes has_active_nhif=true for a patient with active verified NHIF insurance', function () {
    $user = userWithRole('receptionist');
    $patient = Patient::factory()->create(['first_name' => 'NhifPatient', 'last_name' => 'Covered']);

    PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_authorization_no' => 'AUTH-TEST-001',
    ]);

    $response = $this->actingAs($user)->getJson(
        route('patients.search-json', ['search' => 'NhifPatient'])
    );

    $response->assertOk();
    $results = $response->json('data');
    expect($results)->toHaveCount(1);
    expect($results[0]['has_active_nhif'])->toBeTrue();
});

test('patient searchJson includes has_active_nhif=false for a patient without NHIF coverage', function () {
    $user = userWithRole('receptionist');
    Patient::factory()->create(['first_name' => 'CashOnly', 'last_name' => 'Patient']);

    $response = $this->actingAs($user)->getJson(
        route('patients.search-json', ['search' => 'CashOnly'])
    );

    $response->assertOk();
    $results = $response->json('data');
    expect($results)->toHaveCount(1);
    expect($results[0]['has_active_nhif'])->toBeFalse();
});

test('patient searchJson returns has_active_nhif=false when insurance is pending verification', function () {
    $user = userWithRole('receptionist');
    $patient = Patient::factory()->create(['first_name' => 'PendingNhif', 'last_name' => 'Patient']);

    PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_PENDING,
        'nhif_authorization_no' => null,
    ]);

    $response = $this->actingAs($user)->getJson(
        route('patients.search-json', ['search' => 'PendingNhif'])
    );

    $response->assertOk();
    $results = $response->json('data');
    expect($results)->toHaveCount(1);
    expect($results[0]['has_active_nhif'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// NHIF walk-in — accepted → gate passes
// ---------------------------------------------------------------------------

test('NHIF walk-in: when NHIF returns Accepted, a ChargeInsuranceAllocation is created and sendToService passes', function () {
    $user = userWithRole('receptionist');
    $hospital = Hospital::factory()->create(['code' => 'NHIFTEST']);
    $department = Department::factory()->create(['hospital_id' => $hospital->id]);
    Staff::factory()->create(['user_id' => $user->id, 'hospital_id' => $hospital->id]);

    $service = billableConsultationService($hospital, ['amount' => 5000]);

    $staffMember = Staff::factory()->create(['hospital_id' => $hospital->id]);
    $practitioner = Practitioner::factory()->create([
        'staff_id' => $staffMember->id,
        'consultation_service_id' => $service->id,
    ]);

    NhifServiceMapping::create([
        'service_id' => $service->id,
        'hospital_id' => $hospital->id,
        'nhif_item_code' => '5024',
        'nhif_item_name' => 'General Consultation',
        'coverage_status' => NhifServiceMapping::COVERAGE_COVERED,
        'is_active' => true,
        'mapping_source' => NhifServiceMapping::SOURCE_MANUAL,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $patient = Patient::factory()->create();
    PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_authorization_no' => '860627861758',
        'is_primary' => true,
    ]);

    // NHIF returns Accepted (requiresApproval = false → fully accepted).
    $acceptedResponse = [
        'success' => true,
        'data' => [
            'requestID' => 'req-accepted-001',
            'requestNo' => '390620082451',
            'cardNo' => 'CARD-001',
            'schemeID' => 1001,
            'productCode' => null,
            'firstName' => 'Test',
            'lastName' => 'Patient',
            'dateOfBirth' => '1990-01-01',
            'gender' => 'Male',
            'practitionerNo' => 'MCT001',
            'authorizationNo' => '860627861758',
            'facilityCode' => 'NHIFTEST',
            'clinicalNotes' => null,
            'createdBy' => 'test',
            'dateCreated' => now()->toIso8601String(),
            'services' => [[
                'requestedServiceID' => 'svc-001',
                'requestID' => 'req-accepted-001',
                'itemCode' => '5024',
                'usage' => 'Consultation',
                'quantityRequested' => 1,
                'effectiveDate' => now()->toDateString(),
                'endDate' => now()->addDays(4)->toDateString(),
                'availableAfterDate' => now()->addDays(4)->toDateString(),
                'status' => 'Accepted',
                'requiresApproval' => false,
                'remarks' => null,
                'rejectionDetails' => null,
                'rejectionReasonCode' => null,
                'authorizationNo' => '860627861758',
                'createdBy' => 'test',
                'dateCreated' => now()->toIso8601String(),
                'itemName' => 'General Consultation',
                'percentCovered' => null,
            ]],
        ],
        'http_status' => 200,
        'error' => null,
        'correlation_id' => 'corr-001',
        'operation' => 'RequestServices',
    ];

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('requestPreauthorizedServices')->once()->andReturn($acceptedResponse);
    app()->instance(NhifHttpClient::class, $mockClient);

    $response = $this->actingAs($user)->post(route('reception.walk-in.store'), [
        'patient_id' => $patient->id,
        'department_id' => $department->id,
        'visit_type' => 'walk_in',
        'practitioner_id' => $practitioner->id,
    ]);

    $visit = ReceptionVisit::first();
    $response->assertRedirect(route('reception.visits.show', $visit));

    // Charge was captured.
    $this->assertDatabaseCount('charges', 1);

    // A ChargeInsuranceAllocation was created with patient_responsibility = 0.
    $this->assertDatabaseHas('charge_insurance_allocations', [
        'patient_id' => $patient->id,
        'patient_responsibility' => 0,
        'status' => ChargeInsuranceAllocation::STATUS_FULLY_COVERED,
    ]);

    // Financial gate passes — sendToService must succeed.
    $visit->update(['status' => ReceptionVisit::STATUS_READY_FOR_SERVICE]);
    $this->actingAs($user)->post(route('reception.visits.send-to-service', $visit))
        ->assertRedirect();

    expect($visit->fresh()->status)->toBe(ReceptionVisit::STATUS_SENT_TO_SERVICE);
});

// ---------------------------------------------------------------------------
// NHIF walk-in — pending approval → gate blocks
// ---------------------------------------------------------------------------

test('NHIF walk-in: when NHIF returns pending_approval, no allocation is created and sendToService is blocked', function () {
    $user = userWithRole('receptionist');
    $hospital = Hospital::factory()->create(['code' => 'NHIFTST2']);
    $department = Department::factory()->create(['hospital_id' => $hospital->id]);
    Staff::factory()->create(['user_id' => $user->id, 'hospital_id' => $hospital->id]);

    $service = billableConsultationService($hospital, ['amount' => 5000]);

    $staffMember = Staff::factory()->create(['hospital_id' => $hospital->id]);
    $practitioner = Practitioner::factory()->create([
        'staff_id' => $staffMember->id,
        'consultation_service_id' => $service->id,
    ]);

    NhifServiceMapping::create([
        'service_id' => $service->id,
        'hospital_id' => $hospital->id,
        'nhif_item_code' => '5024',
        'nhif_item_name' => 'General Consultation',
        'coverage_status' => NhifServiceMapping::COVERAGE_COVERED,
        'is_active' => true,
        'mapping_source' => NhifServiceMapping::SOURCE_MANUAL,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $patient = Patient::factory()->create();
    PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_authorization_no' => '860627861758',
        'is_primary' => true,
    ]);

    // requiresApproval=true → session item is PENDING_APPROVAL, no allocation.
    $pendingResponse = [
        'success' => true,
        'data' => [
            'requestID' => 'req-pending-001',
            'requestNo' => '390620082452',
            'cardNo' => 'CARD-001',
            'schemeID' => 1001,
            'productCode' => null,
            'firstName' => 'Test',
            'lastName' => 'Patient',
            'dateOfBirth' => '1990-01-01',
            'gender' => 'Male',
            'practitionerNo' => 'MCT001',
            'authorizationNo' => '860627861758',
            'facilityCode' => 'NHIFTST2',
            'clinicalNotes' => null,
            'createdBy' => 'test',
            'dateCreated' => now()->toIso8601String(),
            'services' => [[
                'requestedServiceID' => 'svc-002',
                'requestID' => 'req-pending-001',
                'itemCode' => '5024',
                'usage' => 'Consultation',
                'quantityRequested' => 1,
                'effectiveDate' => now()->toDateString(),
                'endDate' => now()->addDays(4)->toDateString(),
                'availableAfterDate' => now()->addDays(4)->toDateString(),
                'status' => 'Accepted',
                'requiresApproval' => true,
                'remarks' => null,
                'rejectionDetails' => null,
                'rejectionReasonCode' => null,
                'authorizationNo' => '860627861758',
                'createdBy' => 'test',
                'dateCreated' => now()->toIso8601String(),
                'itemName' => 'General Consultation',
                'percentCovered' => null,
            ]],
        ],
        'http_status' => 200,
        'error' => null,
        'correlation_id' => 'corr-002',
        'operation' => 'RequestServices',
    ];

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('requestPreauthorizedServices')->once()->andReturn($pendingResponse);
    app()->instance(NhifHttpClient::class, $mockClient);

    $response = $this->actingAs($user)->post(route('reception.walk-in.store'), [
        'patient_id' => $patient->id,
        'department_id' => $department->id,
        'visit_type' => 'walk_in',
        'practitioner_id' => $practitioner->id,
    ]);

    $visit = ReceptionVisit::first();
    $response->assertRedirect(route('reception.visits.show', $visit));

    // Charge was captured.
    $this->assertDatabaseCount('charges', 1);

    // No ChargeInsuranceAllocation — NHIF has not yet approved.
    $this->assertDatabaseCount('charge_insurance_allocations', 0);

    // Financial gate blocks — sendToService must fail.
    $visit->update(['status' => ReceptionVisit::STATUS_READY_FOR_SERVICE]);
    $this->actingAs($user)->post(route('reception.visits.send-to-service', $visit))
        ->assertSessionHasErrors('financial_authorization');

    expect($visit->fresh()->status)->toBe(ReceptionVisit::STATUS_READY_FOR_SERVICE);
});
