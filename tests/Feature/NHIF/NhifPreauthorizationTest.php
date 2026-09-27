<?php

use App\Models\Hospital;
use App\Models\InsuranceProvider;
use App\Models\InsuranceScheme;
use App\Models\LaboratoryOrderItem;
use App\Models\NhifPreauthorization;
use App\Models\NhifServiceMapping;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\User;
use App\Services\NHIF\NhifHttpClient;
use App\Services\NHIF\NHIFPreauthorizationService;
use Illuminate\Validation\ValidationException;

function preauthStoreRoute(PatientInsurance $patientInsurance): string
{
    return route('patients.insurance.nhif-preauthorization.store', [
        'patient' => $patientInsurance->patient_id,
        'patientInsurance' => $patientInsurance->id,
    ]);
}

/**
 * Real NHIF preauthorization lifecycle (Phase 8, Step 69) — the "requested
 * service check": request/sync against a faked NhifHttpClient, and the
 * assertPreauthorizedForSources() enforcement gate.
 */
function nhifProviderFor(): InsuranceProvider
{
    return InsuranceProvider::factory()->create(['api_enabled' => true, 'status' => 'active']);
}

function nhifCoverageFor(Patient $patient, ?InsuranceProvider $provider = null): PatientInsurance
{
    $provider ??= nhifProviderFor();
    $scheme = InsuranceScheme::factory()->create(['insurance_provider_id' => $provider->id, 'status' => 'active']);

    return primaryCoverageFor($patient, $scheme);
}

function preauthRequiredMapping(int $serviceId, int $hospitalId): NhifServiceMapping
{
    $actor = User::factory()->create();

    return NhifServiceMapping::create([
        'service_id' => $serviceId,
        'hospital_id' => $hospitalId,
        'nhif_item_code' => 'ITEM-'.uniqid(),
        'nhif_item_name' => 'Test Item',
        'requires_preauth' => true,
        'is_active' => true,
        'created_by' => $actor->id,
    ]);
}

/**
 * A full patient + hospital + service + charge + NHIF coverage + a
 * preauth-requiring mapping, all consistent with each other.
 *
 * @return array{hospital: Hospital, patient: Patient, item: LaboratoryOrderItem, preauth: NhifPreauthorization}
 */
function preauthReadyContext(): array
{
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    nhifCoverageFor($patient);
    $service = serviceFor();
    preauthRequiredMapping($service->id, $hospital->id);

    $item = LaboratoryOrderItem::factory()->create();

    chargeFor($hospital, $patient, [
        'service_id' => $service->id,
        'source_type' => LaboratoryOrderItem::class,
        'source_id' => $item->id,
    ]);

    $preauth = NhifPreauthorization::create([
        'hospital_id' => $hospital->id,
        'patient_insurance_id' => $patient->insurances()->first()->id,
        'source_type' => LaboratoryOrderItem::class,
        'source_id' => $item->id,
        'authorization_no' => 'AUTH-123',
        'status' => NhifPreauthorization::STATUS_PENDING,
        'created_by' => User::factory()->create()->id,
    ]);

    return compact('hospital', 'patient', 'item', 'preauth');
}

test('request() builds the payload, calls NHIF, and marks the record submitted', function () {
    $ctx = preauthReadyContext();

    $this->mock(NhifHttpClient::class, function ($mock) use ($ctx) {
        $mock->shouldReceive('requestPreauthorizedServices')
            ->once()
            ->withArgs(fn (array $payload) => $payload['authorizationNo'] === 'AUTH-123'
                && $payload['facilityCode'] === $ctx['hospital']->code
                && count($payload['requestedServices']) === 1)
            ->andReturn(['success' => true, 'data' => ['requestID' => 'REQ-1'], 'http_status' => 200, 'error' => null]);
    });

    $actor = User::factory()->create();
    $updated = app(NHIFPreauthorizationService::class)->request($ctx['preauth'], requestAs($actor));

    expect($updated->status)->toBe(NhifPreauthorization::STATUS_SUBMITTED)
        ->and($updated->submitted_at)->not->toBeNull()
        ->and($updated->response_data)->toMatchArray(['requestID' => 'REQ-1']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'preauthorization_requested']);
});

test('request() never throws when NHIF is unreachable, and leaves the record pending', function () {
    $ctx = preauthReadyContext();

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('requestPreauthorizedServices')->once()->andReturn([
            'success' => false, 'data' => [], 'http_status' => 0, 'error' => 'Could not connect to NHIF service.',
        ]);
    });

    $actor = User::factory()->create();
    $updated = app(NHIFPreauthorizationService::class)->request($ctx['preauth'], requestAs($actor));

    expect($updated->status)->toBe(NhifPreauthorization::STATUS_PENDING);
    $this->assertDatabaseHas('audit_logs', ['action' => 'preauthorization_request_failed']);
});

test('syncStatus() transitions submitted to approved with an approved amount', function () {
    $ctx = preauthReadyContext();
    $ctx['preauth']->update(['status' => NhifPreauthorization::STATUS_SUBMITTED]);

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getRequestServicesByAuthorizationNo')->once()->with('AUTH-123')->andReturn([
            'success' => true,
            'data' => ['requestedServices' => [['status' => 'Approved', 'unitPrice' => 15000]]],
            'http_status' => 200,
            'error' => null,
        ]);
    });

    $actor = User::factory()->create();
    $updated = app(NHIFPreauthorizationService::class)->syncStatus($ctx['preauth'], requestAs($actor));

    expect($updated->status)->toBe(NhifPreauthorization::STATUS_APPROVED)
        ->and((float) $updated->approved_amount)->toBe(15000.0);
    $this->assertDatabaseHas('audit_logs', ['action' => 'preauthorization_status_synced']);
});

test('syncStatus() transitions submitted to rejected with a reason', function () {
    $ctx = preauthReadyContext();
    $ctx['preauth']->update(['status' => NhifPreauthorization::STATUS_SUBMITTED]);

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getRequestServicesByAuthorizationNo')->once()->andReturn([
            'success' => true,
            'data' => ['requestedServices' => [['status' => 'Rejected', 'rejectionDetails' => 'Not covered.']]],
            'http_status' => 200,
            'error' => null,
        ]);
    });

    $actor = User::factory()->create();
    $updated = app(NHIFPreauthorizationService::class)->syncStatus($ctx['preauth'], requestAs($actor));

    expect($updated->status)->toBe(NhifPreauthorization::STATUS_REJECTED)
        ->and($updated->rejection_reason)->toBe('Not covered.');
});

test('assertPreauthorizedForSources throws when a mapping requires preauth and none is approved', function () {
    $ctx = preauthReadyContext();

    expect(fn () => app(NHIFPreauthorizationService::class)->assertPreauthorizedForSources(
        LaboratoryOrderItem::class,
        [$ctx['item']->id],
    ))->toThrow(ValidationException::class, 'pre-authorization is required');
});

test('assertPreauthorizedForSources passes silently when the mapping does not require preauth', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    nhifCoverageFor($patient);
    $service = serviceFor();
    // No preauthRequiredMapping() call — no mapping exists at all.
    $item = LaboratoryOrderItem::factory()->create();
    chargeFor($hospital, $patient, [
        'service_id' => $service->id,
        'source_type' => LaboratoryOrderItem::class,
        'source_id' => $item->id,
    ]);

    app(NHIFPreauthorizationService::class)->assertPreauthorizedForSources(LaboratoryOrderItem::class, [$item->id]);

    expect(true)->toBeTrue();
});

test('assertPreauthorizedForSources passes when an approved preauthorization exists', function () {
    $ctx = preauthReadyContext();
    $ctx['preauth']->update(['status' => NhifPreauthorization::STATUS_APPROVED]);

    app(NHIFPreauthorizationService::class)->assertPreauthorizedForSources(LaboratoryOrderItem::class, [$ctx['item']->id]);

    expect(true)->toBeTrue();
});

test('assertPreauthorizedForSources ignores a patient with no NHIF coverage even when the mapping requires preauth', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create(); // no insurance at all — a cash patient
    $service = serviceFor();
    preauthRequiredMapping($service->id, $hospital->id);
    $item = LaboratoryOrderItem::factory()->create();
    chargeFor($hospital, $patient, [
        'service_id' => $service->id,
        'source_type' => LaboratoryOrderItem::class,
        'source_id' => $item->id,
    ]);

    app(NHIFPreauthorizationService::class)->assertPreauthorizedForSources(LaboratoryOrderItem::class, [$item->id]);

    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Controller: store()
// ---------------------------------------------------------------------------

test('store rejects an unknown source_type', function () {
    $user = userWithRole('accountant');
    $patientInsurance = PatientInsurance::factory()->create();
    $hospital = Hospital::factory()->create();

    $this->actingAs($user)->post(preauthStoreRoute($patientInsurance), [
        'source_type' => 'App\\Models\\SomeUnknownModel',
        'source_id' => 1,
        'hospital_id' => $hospital->id,
        'authorization_no' => 'AUTH-1',
    ])->assertSessionHasErrors('source_type');
});

test('store rejects a non-existent source_id', function () {
    $user = userWithRole('accountant');
    $patientInsurance = PatientInsurance::factory()->create();
    $hospital = Hospital::factory()->create();

    $this->actingAs($user)->post(preauthStoreRoute($patientInsurance), [
        'source_type' => LaboratoryOrderItem::class,
        'source_id' => 999999,
        'hospital_id' => $hospital->id,
        'authorization_no' => 'AUTH-1',
    ])->assertSessionHasErrors('source_id');
});

test('store does not create a duplicate preauthorization for the same source', function () {
    $user = userWithRole('accountant');
    $patientInsurance = PatientInsurance::factory()->create();
    $hospital = Hospital::factory()->create();
    $item = LaboratoryOrderItem::factory()->create();

    $payload = [
        'source_type' => LaboratoryOrderItem::class,
        'source_id' => $item->id,
        'hospital_id' => $hospital->id,
        'authorization_no' => 'AUTH-1',
    ];

    $this->actingAs($user)->post(preauthStoreRoute($patientInsurance), $payload);
    $this->actingAs($user)->post(preauthStoreRoute($patientInsurance), $payload);

    expect(NhifPreauthorization::where('source_type', LaboratoryOrderItem::class)->where('source_id', $item->id)->count())->toBe(1);
});
