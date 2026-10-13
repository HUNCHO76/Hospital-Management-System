<?php

use App\Models\ClinicalEncounter;
use App\Models\Hospital;
use App\Models\NhifClaim;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\User;
use App\Services\NHIF\NhifHttpClient;

/**
 * A ready claim and an authorized user for the manual-signature endpoints.
 *
 * @return array{claim: NhifClaim, user: User}
 */
function manualSignatureContext(): array
{
    $hospital = Hospital::factory()->create(['code' => 'FAC001']);
    $patient = Patient::factory()->create();
    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'nhif_authorization_no' => 'AUTH-2024-SIG',
        'card_number' => 'CARD987654',
    ]);
    $user = userWithRole('super-admin');

    $claim = NhifClaim::factory()->ready()->create([
        'hospital_id' => $hospital->id,
        'patient_id' => $patient->id,
        'patient_insurance_id' => $insurance->id,
        'facility_code' => $hospital->code,
        'card_no' => $insurance->card_number,
        'authorization_no' => $insurance->nhif_authorization_no,
        'folio_year' => (int) now()->format('Y'),
        'folio_month' => (int) now()->format('n'),
        'folio_no' => '0001',
        'created_by' => $user->id,
    ]);

    return compact('claim', 'user');
}

function fakeSignatureBase64(): string
{
    return base64_encode(str_repeat('signature-bytes', 20));
}

/**
 * @return array<string, mixed>
 */
function manualSignatureNhifOk(): array
{
    return [
        'success' => true,
        'data' => ['Status' => 'Signed'],
        'http_status' => 200,
        'error' => null,
        'correlation_id' => 'test-'.uniqid(),
        'operation' => 'SignFolio',
    ];
}

test('a manual signature without signature data is rejected', function () {
    $ctx = manualSignatureContext();

    $this->actingAs($ctx['user'])
        ->postJson(route('nhif.claims.sign', $ctx['claim']), [
            'signature_method' => 'SIGNATURE',
            'signed_by' => 'PATIENT',
            'signed_by_name' => 'Jane Patient',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('signature_data');

    expect($ctx['claim']->fresh()->status)->toBe(NhifClaim::STATUS_READY);
});

test('a manual signature with malformed signature data is rejected', function () {
    $ctx = manualSignatureContext();

    $this->actingAs($ctx['user'])
        ->postJson(route('nhif.claims.sign', $ctx['claim']), [
            'signature_method' => 'SIGNATURE',
            'signature_data' => 'not valid base64 !!!',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('signature_data');
});

test('a manual signature is forwarded to NHIF SignFolio as stripped base64', function () {
    $ctx = manualSignatureContext();
    $base64 = fakeSignatureBase64();

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('signFolio')
        ->once()
        ->withArgs(fn (array $payload) => $payload['SignatureData'] === $base64
            && $payload['SignatureMethod'] === 'SIGNATURE'
            && $payload['SignedBy'] === 'SPOUSE'
            && $payload['SignedByName'] === 'John Spouse')
        ->andReturn(manualSignatureNhifOk());

    app()->instance(NhifHttpClient::class, $mockClient);

    $this->actingAs($ctx['user'])
        ->postJson(route('nhif.claims.sign', $ctx['claim']), [
            'signature_method' => 'SIGNATURE',
            'signature_data' => 'data:image/jpeg;base64,'.$base64,
            'signed_by' => 'SPOUSE',
            'signed_by_name' => 'John Spouse',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($ctx['claim']->fresh()->status)->toBe(NhifClaim::STATUS_SIGNED);
});

test('the fingerprint flow does not require a manual signature', function () {
    $ctx = manualSignatureContext();

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('signFolio')
        ->once()
        ->withArgs(fn (array $payload) => $payload['SignatureMethod'] === 'FINGERPRINT' && $payload['FpCode'] === 'R1')
        ->andReturn(manualSignatureNhifOk());

    app()->instance(NhifHttpClient::class, $mockClient);

    $this->actingAs($ctx['user'])
        ->postJson(route('nhif.claims.sign', $ctx['claim']), [
            'signature_method' => 'FINGERPRINT',
            'signature_data' => fakeSignatureBase64(),
            'fp_code' => 'R1',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);
});

test('finalizing from context also requires manual signature data', function () {
    $ctx = manualSignatureContext();

    $this->actingAs($ctx['user'])
        ->postJson(route('nhif.claims.finalize-from-context'), [
            'clinical_encounter_id' => ClinicalEncounter::factory()->create()->id,
            'signature_method' => 'SIGNATURE',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('signature_data');
});
