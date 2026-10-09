<?php

use App\Models\Hospital;
use App\Models\NhifClaim;
use App\Models\NhifMonthlySubmission;
use App\Models\User;
use App\Services\NHIF\NhifHttpClient;
use App\Services\NHIF\NhifMonthlyClaimService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->hospital = Hospital::factory()->create([
        'name' => 'St. Joseph Hospital',
        'code' => '01141',
    ]);

    $this->otherHospital = Hospital::factory()->create([
        'name' => 'Northern Referral Hospital',
        'code' => '02299',
    ]);

    $this->user = User::factory()->create();
});

function requestWithUser(User $user): Request
{
    $req = Request::create('/nhif/claims/monthly/submit', 'POST');
    $req->setUserResolver(fn () => $user);

    return $req;
}

test('calculateMonthlyClaim correctly counts submitted folios and calculates sum total', function () {
    $year = 2026;
    $month = 10;

    // 3 eligible submitted claims for October 2026
    NhifClaim::factory()->create([
        'hospital_id' => $this->hospital->id,
        'facility_code' => '01141',
        'folio_year' => $year,
        'folio_month' => $month,
        'status' => NhifClaim::STATUS_SUBMITTED,
        'nhif_allowable_total' => 15465.00,
    ]);
    NhifClaim::factory()->create([
        'hospital_id' => $this->hospital->id,
        'facility_code' => '01141',
        'folio_year' => $year,
        'folio_month' => $month,
        'status' => NhifClaim::STATUS_CONFIRMED,
        'nhif_allowable_total' => 8000.00,
    ]);
    NhifClaim::factory()->create([
        'hospital_id' => $this->hospital->id,
        'facility_code' => '01141',
        'folio_year' => $year,
        'folio_month' => $month,
        'status' => NhifClaim::STATUS_FINALIZED,
        'nhif_allowable_total' => 12500.00,
    ]);

    // Excluded claims: Draft, Failed, Rejected, Cancelled
    NhifClaim::factory()->create([
        'hospital_id' => $this->hospital->id,
        'folio_year' => $year,
        'folio_month' => $month,
        'status' => NhifClaim::STATUS_DRAFT,
        'nhif_allowable_total' => 5000.00,
    ]);
    NhifClaim::factory()->create([
        'hospital_id' => $this->hospital->id,
        'folio_year' => $year,
        'folio_month' => $month,
        'status' => NhifClaim::STATUS_SUBMISSION_FAILED,
        'nhif_allowable_total' => 7000.00,
    ]);
    NhifClaim::factory()->create([
        'hospital_id' => $this->hospital->id,
        'folio_year' => $year,
        'folio_month' => $month,
        'status' => NhifClaim::STATUS_REJECTED,
        'nhif_allowable_total' => 9000.00,
    ]);
    NhifClaim::factory()->create([
        'hospital_id' => $this->hospital->id,
        'folio_year' => $year,
        'folio_month' => $month,
        'status' => NhifClaim::STATUS_CANCELLED,
        'nhif_allowable_total' => 4000.00,
    ]);

    // Cross-month exclusion (November 2026)
    NhifClaim::factory()->create([
        'hospital_id' => $this->hospital->id,
        'folio_year' => $year,
        'folio_month' => 11,
        'status' => NhifClaim::STATUS_SUBMITTED,
        'nhif_allowable_total' => 30000.00,
    ]);

    // Cross-facility exclusion
    NhifClaim::factory()->create([
        'hospital_id' => $this->otherHospital->id,
        'folio_year' => $year,
        'folio_month' => $month,
        'status' => NhifClaim::STATUS_SUBMITTED,
        'nhif_allowable_total' => 50000.00,
    ]);

    $service = app(NhifMonthlyClaimService::class);
    $result = $service->calculateMonthlyClaim($this->hospital, $year, $month);

    expect($result['facility_code'])->toBe('01141')
        ->and($result['claim_year'])->toBe(2026)
        ->and($result['claim_month'])->toBe(10)
        ->and($result['folios_submitted'])->toBe(3)
        ->and($result['total_amount_claimed'])->toBe(35965.0)
        ->and($result['can_submit'])->toBeTrue();
});

test('submitMonthlyClaim builds dynamic payload, submits to NHIF, and records audit trail', function () {
    $year = 2026;
    $month = 10;

    NhifClaim::factory()->create([
        'hospital_id' => $this->hospital->id,
        'folio_year' => $year,
        'folio_month' => $month,
        'status' => NhifClaim::STATUS_SUBMITTED,
        'nhif_allowable_total' => 35965.00,
    ]);

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('submitMonthlyClaim')
        ->once()
        ->with(Mockery::on(function ($payload) use ($year, $month) {
            return $payload['FacilityCode'] === '01141'
                && $payload['ClaimYear'] === $year
                && $payload['ClaimMonth'] === $month
                && $payload['FoliosSubmitted'] === 1
                && $payload['TotalAmountClaimed'] == 35965.0
                && $payload['SubmissionRemarks'] === 'Monthly submission test';
        }))
        ->andReturn([
            'success' => true,
            'http_status' => 200,
            'data' => [
                'StatusCode' => 200,
                'Message' => 'Monthly claim received successfully',
            ],
            'error' => null,
            'correlation_id' => 'CORR-M10-001',
        ]);

    app()->instance(NhifHttpClient::class, $mockClient);

    $service = app(NhifMonthlyClaimService::class);
    $submission = $service->submitMonthlyClaim(
        $this->hospital,
        $year,
        $month,
        'Monthly submission test',
        requestWithUser($this->user)
    );

    expect($submission)->toBeInstanceOf(NhifMonthlySubmission::class)
        ->and($submission->status)->toBe(NhifMonthlySubmission::STATUS_SUBMITTED)
        ->and($submission->facility_code)->toBe('01141')
        ->and($submission->folios_submitted)->toBe(1)
        ->and((float) $submission->total_amount_claimed)->toBe(35965.0)
        ->and($submission->nhif_request_payload['FoliosSubmitted'])->toBe(1)
        ->and($submission->submitted_by)->toBe($this->user->id);
});

test('submitMonthlyClaim prevents duplicate submission for the same facility, year, and month', function () {
    $year = 2026;
    $month = 10;

    NhifMonthlySubmission::factory()->create([
        'hospital_id' => $this->hospital->id,
        'facility_code' => '01141',
        'claim_year' => $year,
        'claim_month' => $month,
        'status' => NhifMonthlySubmission::STATUS_SUBMITTED,
        'submitted_by' => $this->user->id,
    ]);

    $service = app(NhifMonthlyClaimService::class);

    expect(fn () => $service->submitMonthlyClaim(
        $this->hospital,
        $year,
        $month,
        null,
        requestWithUser($this->user)
    ))->toThrow(ValidationException::class);
});

test('submitMonthlyClaim rejects when no eligible submitted folios exist', function () {
    $service = app(NhifMonthlyClaimService::class);

    expect(fn () => $service->submitMonthlyClaim(
        $this->hospital,
        2029,
        12,
        null,
        requestWithUser($this->user)
    ))->toThrow(ValidationException::class);
});

test('submitMonthlyClaim handles NHIF API failure and allows retry', function () {
    $year = 2026;
    $month = 10;

    NhifClaim::factory()->create([
        'hospital_id' => $this->hospital->id,
        'folio_year' => $year,
        'folio_month' => $month,
        'status' => NhifClaim::STATUS_SUBMITTED,
        'nhif_allowable_total' => 20000.00,
    ]);

    $mockClient = Mockery::mock(NhifHttpClient::class);
    // 1st attempt: fails
    $mockClient->shouldReceive('submitMonthlyClaim')
        ->once()
        ->andReturn([
            'success' => false,
            'http_status' => 500,
            'data' => [],
            'error' => 'NHIF Service Unavailable',
            'correlation_id' => 'CORR-FAIL-1',
        ]);

    app()->instance(NhifHttpClient::class, $mockClient);

    $service = app(NhifMonthlyClaimService::class);
    $failedSubmission = $service->submitMonthlyClaim(
        $this->hospital,
        $year,
        $month,
        null,
        requestWithUser($this->user)
    );

    expect($failedSubmission->status)->toBe(NhifMonthlySubmission::STATUS_FAILED)
        ->and($failedSubmission->error_message)->toBe('NHIF Service Unavailable')
        ->and($failedSubmission->retry_count)->toBe(1);

    // 2nd attempt: succeeds on retry
    $mockClient2 = Mockery::mock(NhifHttpClient::class);
    $mockClient2->shouldReceive('submitMonthlyClaim')
        ->once()
        ->andReturn([
            'success' => true,
            'http_status' => 200,
            'data' => ['Status' => 'Received'],
            'error' => null,
            'correlation_id' => 'CORR-OK-2',
        ]);

    app()->instance(NhifHttpClient::class, $mockClient2);

    $service2 = app(NhifMonthlyClaimService::class);
    $retrySubmission = $service2->submitMonthlyClaim(
        $this->hospital,
        $year,
        $month,
        'Retry after outage',
        requestWithUser($this->user)
    );

    expect($retrySubmission->status)->toBe(NhifMonthlySubmission::STATUS_SUBMITTED)
        ->and($retrySubmission->retry_count)->toBe(2)
        ->and($retrySubmission->error_message)->toBeNull();
});

test('HTTP controller endpoints calculate and submit monthly claim successfully', function () {
    $year = 2026;
    $month = 10;

    NhifClaim::factory()->create([
        'hospital_id' => $this->hospital->id,
        'folio_year' => $year,
        'folio_month' => $month,
        'status' => NhifClaim::STATUS_SUBMITTED,
        'nhif_allowable_total' => 50000.00,
    ]);

    $mockClient = Mockery::mock(NhifHttpClient::class);
    $mockClient->shouldReceive('submitMonthlyClaim')
        ->andReturn([
            'success' => true,
            'http_status' => 200,
            'data' => ['Message' => 'Success'],
            'error' => null,
            'correlation_id' => 'CORR-HTTP-1',
        ]);
    app()->instance(NhifHttpClient::class, $mockClient);

    // 1. GET /nhif/claims/monthly/calculate
    $calcResponse = $this->actingAs($this->user)
        ->getJson(route('nhif.claims.monthly.calculate', [
            'hospital_id' => $this->hospital->id,
            'claim_year' => $year,
            'claim_month' => $month,
        ]));

    $calcResponse->assertOk()
        ->assertJsonPath('data.folios_submitted', 1)
        ->assertJsonPath('data.total_amount_claimed', 50000);

    // 2. POST /nhif/claims/monthly/submit
    $submitResponse = $this->actingAs($this->user)
        ->postJson(route('nhif.claims.monthly.submit'), [
            'hospital_id' => $this->hospital->id,
            'claim_year' => $year,
            'claim_month' => $month,
            'submission_remarks' => 'Monthly controller test',
        ]);

    $submitResponse->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('nhif_monthly_submissions', [
        'hospital_id' => $this->hospital->id,
        'claim_year' => $year,
        'claim_month' => $month,
        'status' => NhifMonthlySubmission::STATUS_SUBMITTED,
        'folios_submitted' => 1,
    ]);
});
