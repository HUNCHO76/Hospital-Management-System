<?php

/**
 * NhifDiseaseReferenceTest — Phase 8
 *
 * Scenarios:
 *  1.  syncDiseases() creates nhif_diseases rows + sync log with type='diseases'
 *  2.  Re-sync is idempotent (updateOrCreate — row count stays the same)
 *  3.  NhifHttpClient failure marks sync log 'failed'
 *  4.  Search endpoint returns matches by disease_code
 *  5.  Search endpoint returns matches by disease_name
 *  6.  Search rejects queries shorter than 2 chars (returns empty)
 *  7.  Unauthenticated request → 302 redirect
 *  8.  Authenticated without diagnosis.create → 403
 */

use App\Models\NhifDisease;
use App\Services\NHIF\NhifCatalogSyncService;
use App\Services\NHIF\NhifHttpClient;
use Mockery;

// ---------------------------------------------------------------------------
// Scenario 1 — syncDiseases creates rows + log
// ---------------------------------------------------------------------------

it('syncDiseases creates nhif_diseases rows and a sync log with type diseases', function (): void {
    $this->actingAs(userWithRole('hospital-admin'));

    $mock = Mockery::mock(NhifHttpClient::class);
    $mock->shouldReceive('getOcsDiseases')->once()->andReturn([
        'success' => true,
        'data' => [
            'Diseases' => [
                ['DiseaseCode' => 'A00', 'DiseaseName' => 'Cholera', 'ICDVersionCode' => 'ICD10'],
                ['DiseaseCode' => 'A01', 'DiseaseName' => 'Typhoid fever', 'ICDVersionCode' => 'ICD10'],
            ],
        ],
        'http_status' => 200,
        'error' => null,
        'correlation_id' => 'test-1',
    ]);

    $service = new NhifCatalogSyncService($mock);
    $log = $service->syncDiseases();

    expect($log->sync_type)->toBe('diseases');
    expect($log->status)->toBe('completed');
    expect($log->new_records)->toBe(2);
    expect($log->updated_records)->toBe(0);
    expect(NhifDisease::count())->toBe(2);
    expect(NhifDisease::where('disease_code', 'A00')->first()?->disease_name)->toBe('Cholera');
});

// ---------------------------------------------------------------------------
// Scenario 2 — re-sync is idempotent
// ---------------------------------------------------------------------------

it('re-syncing the same diseases does not create duplicates', function (): void {
    $this->actingAs(userWithRole('hospital-admin'));

    $payload = [
        'success' => true,
        'data' => ['Diseases' => [['DiseaseCode' => 'B00', 'DiseaseName' => 'Fever', 'ICDVersionCode' => 'ICD10']]],
        'http_status' => 200,
        'error' => null,
        'correlation_id' => 'test-2',
    ];

    $mock = Mockery::mock(NhifHttpClient::class);
    $mock->shouldReceive('getOcsDiseases')->once()->andReturn($payload);

    $service = new NhifCatalogSyncService($mock);
    $service->syncDiseases();

    // Second sync with same data
    $mock2 = Mockery::mock(NhifHttpClient::class);
    $mock2->shouldReceive('getOcsDiseases')->once()->andReturn($payload);
    (new NhifCatalogSyncService($mock2))->syncDiseases();

    expect(NhifDisease::count())->toBe(1);
    expect(NhifDisease::where('disease_code', 'B00')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Scenario 3 — client failure marks log failed
// ---------------------------------------------------------------------------

it('syncDiseases marks the log failed when NhifHttpClient returns an error', function (): void {
    $this->actingAs(userWithRole('hospital-admin'));

    $mock = Mockery::mock(NhifHttpClient::class);
    $mock->shouldReceive('getOcsDiseases')->once()->andReturn([
        'success' => false,
        'data' => [],
        'http_status' => 500,
        'error' => 'OCS service unavailable',
        'correlation_id' => 'test-3',
    ]);

    $service = new NhifCatalogSyncService($mock);
    $log = $service->syncDiseases();

    expect($log->status)->toBe('failed');
    expect($log->error_message)->toContain('OCS service unavailable');
    expect(NhifDisease::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Scenario 4 — search by disease_code
// ---------------------------------------------------------------------------

it('disease search endpoint returns results matched by disease_code', function (): void {
    NhifDisease::factory()->create(['disease_code' => 'A00', 'disease_name' => 'Cholera', 'icd_version_code' => 'ICD10']);
    NhifDisease::factory()->create(['disease_code' => 'B05', 'disease_name' => 'Measles', 'icd_version_code' => 'ICD10']);

    $actor = userWithRole('doctor');

    $response = $this->actingAs($actor)
        ->getJson(route('nhif.diseases.search-json').'?q=A00');

    $response->assertOk();
    $data = $response->json();
    expect($data)->toHaveCount(1);
    expect($data[0]['disease_code'])->toBe('A00');
});

// ---------------------------------------------------------------------------
// Scenario 5 — search by disease_name
// ---------------------------------------------------------------------------

it('disease search endpoint returns results matched by disease_name', function (): void {
    NhifDisease::factory()->create(['disease_code' => 'A00', 'disease_name' => 'Cholera', 'icd_version_code' => 'ICD10']);
    NhifDisease::factory()->create(['disease_code' => 'B05', 'disease_name' => 'Measles', 'icd_version_code' => 'ICD10']);

    $actor = userWithRole('doctor');

    $response = $this->actingAs($actor)
        ->getJson(route('nhif.diseases.search-json').'?q=chol');

    $response->assertOk();
    $data = $response->json();
    expect($data)->toHaveCount(1);
    expect($data[0]['disease_name'])->toBe('Cholera');
});

// ---------------------------------------------------------------------------
// Scenario 6 — queries shorter than 2 chars return empty
// ---------------------------------------------------------------------------

it('disease search returns empty for queries shorter than 2 characters', function (): void {
    NhifDisease::factory()->create(['disease_code' => 'A00', 'disease_name' => 'Cholera', 'icd_version_code' => 'ICD10']);

    $actor = userWithRole('doctor');

    $response = $this->actingAs($actor)
        ->getJson(route('nhif.diseases.search-json').'?q=A');

    $response->assertOk();
    expect($response->json())->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// Scenario 7 — unauthenticated → redirect
// ---------------------------------------------------------------------------

it('disease search redirects unauthenticated requests', function (): void {
    $this->get(route('nhif.diseases.search-json').'?q=chol')
        ->assertRedirect();
});

// ---------------------------------------------------------------------------
// Scenario 8 — authenticated but missing diagnosis.create → 403
// ---------------------------------------------------------------------------

it('disease search returns 403 for users without diagnosis.create permission', function (): void {
    $actor = userWithRole('receptionist');

    $this->actingAs($actor)
        ->getJson(route('nhif.diseases.search-json').'?q=chol')
        ->assertStatus(403);
});
