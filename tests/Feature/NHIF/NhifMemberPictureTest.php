<?php

use App\Models\InsuranceProvider;
use App\Models\NhifMemberVerification;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Services\NHIF\NhifHttpClient;
use App\Services\NHIF\NhifMemberVerificationService;
use Illuminate\Support\Facades\Http;

function memberPictureSetup(): array
{
    $provider = InsuranceProvider::factory()->create(['api_enabled' => true, 'status' => 'active']);
    $patient = Patient::factory()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'national_id' => 'NIN-12345',
    ]);
    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $patient->id,
        'insurance_provider_id' => $provider->id,
        'card_number' => 'CARD-001',
        'verification_status' => 'pending',
    ]);

    return compact('provider', 'patient', 'insurance');
}

test('NhifHttpClient::getMemberPicture handles base64 JSON payload', function () {
    Http::fake([
        '*/connect/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
        '*/api/Verification/GetMemberPicture*' => Http::response([
            'picture' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        ], 200),
    ]);

    $client = app(NhifHttpClient::class);
    $result = $client->getMemberPicture('CARD-001', 'NHIF');

    expect($result['success'])->toBeTrue()
        ->and($result['data']['picture'])->toStartWith('data:image/jpeg;base64,iVBORw');
});

test('NhifHttpClient::getMemberPicture handles raw binary image stream', function () {
    $fakeJpegBytes = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xDB";

    Http::fake([
        '*/connect/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
        '*/api/Verification/GetMemberPicture*' => Http::response(
            $fakeJpegBytes,
            200,
            ['Content-Type' => 'image/jpeg']
        ),
    ]);

    $client = app(NhifHttpClient::class);
    $result = $client->getMemberPicture('CARD-001', 'NHIF');

    expect($result['success'])->toBeTrue()
        ->and($result['data']['picture'])->toBe('data:image/jpeg;base64,'.base64_encode($fakeJpegBytes));
});

test('NhifHttpClient::getMemberPicture gracefully returns null on 400 or 404 response', function () {
    Http::fake([
        '*/connect/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600]),
        '*/api/Verification/GetMemberPicture*' => Http::response([
            'type' => 'https://tools.ietf.org/html/rfc9110#section-15.5.1',
            'title' => 'Bad Request',
            'status' => 400,
            'detail' => 'Could not find employer number for the specified card.',
        ], 400),
    ]);

    $client = app(NhifHttpClient::class);
    $result = $client->getMemberPicture('CARD-001', 'NHIF');

    expect($result['success'])->toBeFalse()
        ->and($result['data']['picture'])->toBeNull()
        ->and($result['error'])->toContain('Could not find employer number');
});

test('verify card saves member_picture on verification and insurance when picture is available', function () {
    $ctx = memberPictureSetup();
    $expectedPicture = 'data:image/jpeg;base64,fakephotodata123';

    $this->mock(NhifHttpClient::class, function ($mock) use ($expectedPicture) {
        $mock->shouldReceive('getMemberByCard')->once()->andReturn([
            'success' => true,
            'operation' => 'GetCardDetails',
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'corr-1',
            'data' => [
                'FullName' => 'John Doe',
                'CardNo' => 'CARD-001',
                'MembershipNo' => 'MEM-001',
                'CHNationalID' => 'NIN-12345',
                'IsActive' => true,
                'IsValidCard' => true,
            ],
        ]);
        $mock->shouldReceive('getVisitTypes')->andReturn(['success' => true, 'data' => []]);
        $mock->shouldReceive('verifyCard')->once()->andReturn([
            'success' => true,
            'data' => [
                'AuthorizationNo' => 'AUTH-999',
                'SchemeID' => 1001,
                'FacilityCode' => 'MAIN',
            ],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'corr-2',
        ]);
        $mock->shouldReceive('getMemberPicture')->once()->with('CARD-001', 'NHIF')->andReturn([
            'success' => true,
            'data' => ['picture' => $expectedPicture],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'corr-3',
            'operation' => 'GetMemberPicture',
        ]);
    });

    $service = app(NhifMemberVerificationService::class);
    $request = Request::create('/test', 'POST');
    $request->setUserResolver(fn () => userWithRole('receptionist'));

    $record = $service->verify(
        $ctx['insurance'],
        NhifMemberVerification::TYPE_CARD_NUMBER,
        'CARD-001',
        $request,
        1,
        'NHIF',
    );

    expect($record->isSuccessful())->toBeTrue()
        ->and($record->member_picture)->toBe($expectedPicture);

    $ctx['insurance']->refresh();
    expect($ctx['insurance']->nhif_member_picture)->toBe($expectedPicture);
});

test('verify card succeeds even when getMemberPicture fails or returns null', function () {
    $ctx = memberPictureSetup();

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getMemberByCard')->once()->andReturn([
            'success' => true,
            'operation' => 'GetCardDetails',
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'corr-1',
            'data' => [
                'FullName' => 'John Doe',
                'CardNo' => 'CARD-001',
                'MembershipNo' => 'MEM-001',
                'CHNationalID' => 'NIN-12345',
                'IsActive' => true,
                'IsValidCard' => true,
            ],
        ]);
        $mock->shouldReceive('getVisitTypes')->andReturn(['success' => true, 'data' => []]);
        $mock->shouldReceive('verifyCard')->once()->andReturn([
            'success' => true,
            'data' => [
                'AuthorizationNo' => 'AUTH-999',
                'SchemeID' => 1001,
                'FacilityCode' => 'MAIN',
            ],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'corr-2',
        ]);
        $mock->shouldReceive('getMemberPicture')->once()->with('CARD-001', 'NHIF')->andReturn([
            'success' => false,
            'data' => ['picture' => null],
            'http_status' => 404,
            'error' => 'No picture found',
            'correlation_id' => 'corr-3',
            'operation' => 'GetMemberPicture',
        ]);
    });

    $service = app(NhifMemberVerificationService::class);
    $request = Request::create('/test', 'POST');
    $request->setUserResolver(fn () => userWithRole('receptionist'));

    $record = $service->verify(
        $ctx['insurance'],
        NhifMemberVerification::TYPE_CARD_NUMBER,
        'CARD-001',
        $request,
        1,
        'NHIF',
    );

    expect($record->isSuccessful())->toBeTrue()
        ->and($record->member_picture)->toBeNull();

    $ctx['insurance']->refresh();
    expect($ctx['insurance']->nhif_member_picture)->toBeNull();
});

test('POST patients/nhif-member-picture returns picture or default avatar', function () {
    $actor = userWithRole('receptionist');

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getMemberPicture')->once()->with('CARD-001', 'NHIF')->andReturn([
            'success' => true,
            'data' => ['picture' => 'data:image/jpeg;base64,abcd1234'],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'corr-1',
            'operation' => 'GetMemberPicture',
        ]);
    });

    $response = $this->actingAs($actor)->postJson(route('patients.nhif-member-picture'), [
        'card_no' => 'CARD-001',
        'verifier_id' => 'NHIF',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('picture', 'data:image/jpeg;base64,abcd1234')
        ->assertJsonStructure(['success', 'picture', 'default_picture']);
});

test('POST patients/{patient}/insurance/{patientInsurance}/nhif-refresh-picture updates photo', function () {
    $ctx = memberPictureSetup();
    $actor = userWithRole('receptionist');

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getMemberPicture')->once()->with('CARD-001', 'NHIF')->andReturn([
            'success' => true,
            'data' => ['picture' => 'data:image/jpeg;base64,refreshed999'],
            'http_status' => 200,
            'error' => null,
            'correlation_id' => 'corr-1',
            'operation' => 'GetMemberPicture',
        ]);
    });

    $response = $this->actingAs($actor)->post(route('patients.insurance.nhif-refresh-picture', [
        $ctx['patient']->id,
        $ctx['insurance']->id,
    ]));

    $response->assertRedirect();
    $ctx['insurance']->refresh();
    expect($ctx['insurance']->nhif_member_picture)->toBe('data:image/jpeg;base64,refreshed999');
});
