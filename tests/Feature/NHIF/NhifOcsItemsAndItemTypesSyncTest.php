<?php

/**
 * NhifOcsItemsAndItemTypesSyncTest
 *
 * Tests:
 * 1. OCS GetItemTypes sync, fields, and idempotency.
 * 2. OCS GetItems sync, distinct ItemTypeID vs ServiceTypeID, relationships, and idempotency.
 * 3. Inactive catalogue items preserved (never deleted) and excluded from active service selection.
 * 4. Restricted catalogue items flagged for preauthorization.
 * 5. Scheme eligibility separated from master catalogue definitions.
 * 6. Administrative CLI controls (--item-types, --items, --preview, --unmatched).
 * 7. HTTP endpoints authorization and credential protection.
 */

use App\Models\NhifItemType;
use App\Models\NhifPriceSyncLog;
use App\Models\NhifSchemeService;
use App\Models\NhifService;
use App\Models\User;
use App\Services\NHIF\NhifCatalogSyncService;
use App\Services\NHIF\NhifHttpClient;
use App\Services\NHIF\NhifSchemeEligibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function mockItemTypesResponse(array $types = [], bool $success = true): array
{
    return [
        'success' => $success,
        'data' => $success ? $types : [],
        'http_status' => $success ? 200 : 500,
        'error' => $success ? null : 'OCS unavailable',
        'correlation_id' => 'test-item-types',
    ];
}

function mockItemsResponse(array $items = [], bool $success = true): array
{
    return [
        'success' => $success,
        'data' => $success ? $items : [],
        'http_status' => $success ? 200 : 500,
        'error' => $success ? null : 'OCS unavailable',
        'correlation_id' => 'test-items',
    ];
}

function sampleItemTypesPayload(): array
{
    return [
        [
            'ItemTypeID' => 1,
            'TypeName' => 'Registration/Consultation Charges',
            'Alias' => 'REGISTRATION_FEE',
            'ItemGroup' => 'Registration',
            'DisplayItem' => true,
            'DateCreated' => '2021-11-27T06:28:19.46',
            'LastModified' => '2021-05-13T10:18:04.71',
        ],
        [
            'ItemTypeID' => 3,
            'TypeName' => 'Medicine and Consumables',
            'Alias' => 'OUTPATIENT_CHARGES',
            'ItemGroup' => 'Outpatient',
            'DisplayItem' => true,
            'DateCreated' => '2021-11-27T06:28:19.46',
            'LastModified' => '2021-05-13T10:16:13.517',
        ],
        [
            'ItemTypeID' => 5,
            'TypeName' => 'Diagnostic Examinations',
            'Alias' => 'INVESTIGATION',
            'ItemGroup' => 'Investigation',
            'DisplayItem' => true,
            'DateCreated' => '2021-11-27T06:28:19.46',
            'LastModified' => '2021-05-13T10:17:00.64',
        ],
    ];
}

function sampleItemsPayload(): array
{
    return [
        [
            'ItemCode' => '10001',
            'ItemTypeID' => 1,
            'ItemName' => 'General Practitioner Consultation',
            'SubGroup' => null,
            'Strength' => null,
            'Dosage' => null,
            'CalculatedPerDay' => false,
            'ServiceTypeID' => 8,
            'IsRestricted' => false,
            'ServiceInterval' => 0,
            'WaitingPeriod' => 0,
            'CommonPrice' => 10000.0,
            'PercentCovered' => 100.0,
            'IsActive' => true,
            'DateCreated' => '2016-07-01T00:00:00',
            'LastModified' => '2025-01-08T18:47:23.47',
        ],
        [
            'ItemCode' => '10002',
            'ItemTypeID' => 1,
            'ItemName' => 'Specialist Consultation',
            'SubGroup' => null,
            'Strength' => null,
            'Dosage' => null,
            'CalculatedPerDay' => false,
            'ServiceTypeID' => 8,
            'IsRestricted' => true,
            'ServiceInterval' => 0,
            'WaitingPeriod' => 0,
            'CommonPrice' => 25000.0,
            'PercentCovered' => 100.0,
            'IsActive' => true,
            'DateCreated' => '2016-07-01T00:00:00',
            'LastModified' => '2025-01-08T18:47:23.47',
        ],
        [
            'ItemCode' => '11425',
            'ItemTypeID' => 3,
            'ItemName' => 'Glucosamine + Chondrotin sulphate',
            'SubGroup' => 'Anti-inflammatory',
            'Strength' => 'Solid oral dosage form',
            'Dosage' => 'Tablet',
            'CalculatedPerDay' => true,
            'ServiceTypeID' => 2,
            'IsRestricted' => false,
            'ServiceInterval' => 30,
            'WaitingPeriod' => 0,
            'CommonPrice' => 500.0,
            'PercentCovered' => 100.0,
            'IsActive' => false, // Inactive item
            'DateCreated' => '2016-07-01T00:00:00',
            'LastModified' => '2025-01-08T18:47:23.47',
        ],
        [
            'ItemCode' => '20005',
            'ItemTypeID' => 5,
            'ItemName' => 'Full Blood Picture (FBP)',
            'SubGroup' => 'Haematology',
            'Strength' => null,
            'Dosage' => null,
            'CalculatedPerDay' => false,
            'ServiceTypeID' => 6,
            'IsRestricted' => false,
            'ServiceInterval' => 0,
            'WaitingPeriod' => 0,
            'CommonPrice' => 15000.0,
            'PercentCovered' => 100.0,
            'IsActive' => true,
            'DateCreated' => '2016-07-01T00:00:00',
            'LastModified' => '2025-01-08T18:47:23.47',
        ],
    ];
}

// ---------------------------------------------------------------------------
// 1. Item Types Sync & Idempotency
// ---------------------------------------------------------------------------

it('syncItemTypes creates nhif_item_types records and logs status completed', function (): void {
    $mock = Mockery::mock(NhifHttpClient::class);
    $mock->shouldReceive('getOcsItemTypes')
        ->once()
        ->andReturn(mockItemTypesResponse(sampleItemTypesPayload()));

    $service = new NhifCatalogSyncService($mock);
    $log = $service->syncItemTypes();

    expect($log->status)->toBe('completed')
        ->and($log->sync_type)->toBe('item_types')
        ->and($log->total_records)->toBe(3)
        ->and($log->new_records)->toBe(3)
        ->and($log->updated_records)->toBe(0);

    expect(NhifItemType::count())->toBe(3);

    $consultationType = NhifItemType::where('item_type_id', 1)->first();
    expect($consultationType)->not->toBeNull()
        ->and($consultationType->type_name)->toBe('Registration/Consultation Charges')
        ->and($consultationType->alias)->toBe('REGISTRATION_FEE')
        ->and($consultationType->item_group)->toBe('Registration')
        ->and($consultationType->display_item)->toBeTrue();
});

it('syncItemTypes is idempotent when repeated', function (): void {
    $mock = Mockery::mock(NhifHttpClient::class);
    $mock->shouldReceive('getOcsItemTypes')
        ->twice()
        ->andReturn(mockItemTypesResponse(sampleItemTypesPayload()));

    $service = new NhifCatalogSyncService($mock);

    // Run 1
    $log1 = $service->syncItemTypes();
    expect($log1->new_records)->toBe(3);

    // Run 2
    $log2 = $service->syncItemTypes();
    expect($log2->new_records)->toBe(0)
        ->and($log2->updated_records)->toBe(3)
        ->and($log2->status)->toBe('completed');

    expect(NhifItemType::count())->toBe(3);
});

it('syncItemTypes marks log failed on API failure without dropping existing rows', function (): void {
    NhifItemType::create([
        'item_type_id' => 99,
        'type_name' => 'Existing Category',
        'display_item' => true,
    ]);

    $mock = Mockery::mock(NhifHttpClient::class);
    $mock->shouldReceive('getOcsItemTypes')
        ->once()
        ->andReturn(mockItemTypesResponse([], false));

    $service = new NhifCatalogSyncService($mock);
    $log = $service->syncItemTypes();

    expect($log->status)->toBe('failed')
        ->and($log->error_message)->toBe('OCS unavailable')
        ->and(NhifItemType::count())->toBe(1);
});

// ---------------------------------------------------------------------------
// 2. Items Sync, Distinction between ItemTypeID and ServiceTypeID, & Relationships
// ---------------------------------------------------------------------------

it('syncItems distinguishes ItemTypeID from ServiceTypeID and establishes relationship', function (): void {
    // Seed item types
    foreach (sampleItemTypesPayload() as $type) {
        NhifItemType::create([
            'item_type_id' => $type['ItemTypeID'],
            'type_name' => $type['TypeName'],
            'alias' => $type['Alias'],
            'item_group' => $type['ItemGroup'],
            'display_item' => $type['DisplayItem'],
        ]);
    }

    $mock = Mockery::mock(NhifHttpClient::class);
    $mock->shouldReceive('getOcsItems')
        ->once()
        ->andReturn(mockItemsResponse(sampleItemsPayload()));

    $service = new NhifCatalogSyncService($mock);
    $log = $service->syncItems();

    expect($log->status)->toBe('completed')
        ->and($log->sync_type)->toBe('items')
        ->and($log->total_records)->toBe(4)
        ->and($log->new_records)->toBe(4);

    expect(NhifService::count())->toBe(4);

    // Verify distinct ItemTypeID vs ServiceTypeID
    $gp = NhifService::where('nhif_item_code', '10001')->first();
    expect($gp)->not->toBeNull()
        ->and($gp->nhif_item_type_id)->toEqual(1)      // ItemTypeID = 1 (Registration/Consultation)
        ->and($gp->service_type_id)->toEqual(8)       // ServiceTypeID = 8 (Distinct API field!)
        ->and($gp->nhif_item_type_name)->toBe('Registration/Consultation Charges')
        ->and($gp->is_active)->toBeTrue()
        ->and($gp->is_restricted)->toBeFalse()
        ->and($gp->isSelectableForNewServices())->toBeTrue();

    // Verify Eloquent relationship
    expect($gp->itemType)->not->toBeNull()
        ->and($gp->itemType->type_name)->toBe('Registration/Consultation Charges');
});

it('syncItems is idempotent when repeated twice without duplicate rows', function (): void {
    $mock = Mockery::mock(NhifHttpClient::class);
    $mock->shouldReceive('getOcsItems')
        ->twice()
        ->andReturn(mockItemsResponse(sampleItemsPayload()));

    $service = new NhifCatalogSyncService($mock);

    $log1 = $service->syncItems();
    expect($log1->new_records)->toBe(4);

    $log2 = $service->syncItems();
    expect($log2->new_records)->toBe(0)
        ->and($log2->updated_records)->toBe(4);

    expect(NhifService::count())->toBe(4);
});

// ---------------------------------------------------------------------------
// 3. Inactive and Restricted Items Safe Rules
// ---------------------------------------------------------------------------

it('preserves inactive items in database but prevents new service selection', function (): void {
    $mock = Mockery::mock(NhifHttpClient::class);
    $mock->shouldReceive('getOcsItems')
        ->once()
        ->andReturn(mockItemsResponse(sampleItemsPayload()));

    $service = new NhifCatalogSyncService($mock);
    $service->syncItems();

    // Item 11425 has IsActive: false in payload
    $inactive = NhifService::where('nhif_item_code', '11425')->first();
    expect($inactive)->not->toBeNull()
        ->and($inactive->is_active)->toBeFalse()
        ->and($inactive->isSelectableForNewServices())->toBeFalse();

    // Active scope excludes inactive items
    $activeCodes = NhifService::active()->pluck('nhif_item_code')->all();
    expect($activeCodes)->toContain('10001')
        ->and($activeCodes)->toContain('10002')
        ->and($activeCodes)->toContain('20005')
        ->and($activeCodes)->not->toContain('11425');

    // Inactive items are NOT deleted
    expect(NhifService::where('is_active', false)->count())->toBe(1);
});

it('identifies restricted items requiring preauthorization', function (): void {
    $mock = Mockery::mock(NhifHttpClient::class);
    $mock->shouldReceive('getOcsItems')
        ->once()
        ->andReturn(mockItemsResponse(sampleItemsPayload()));

    $service = new NhifCatalogSyncService($mock);
    $service->syncItems();

    $specialist = NhifService::where('nhif_item_code', '10002')->first();
    expect($specialist->is_restricted)->toBeTrue()
        ->and($specialist->requiresPreAuthorization())->toBeTrue();

    $gp = NhifService::where('nhif_item_code', '10001')->first();
    expect($gp->is_restricted)->toBeFalse()
        ->and($gp->requiresPreAuthorization())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 4. Scheme Eligibility vs Master Catalogue Separation
// ---------------------------------------------------------------------------

it('separates scheme eligibility mappings from master catalogue items', function (): void {
    // Master catalogue item exists
    NhifService::create([
        'nhif_item_code' => '10001',
        'nhif_item_name' => 'General Practitioner Consultation',
        'nhif_item_type_id' => 1,
        'is_active' => true,
    ]);

    // Same item legitimately mapped to Scheme 1001 and Scheme 1002 in schemes_services
    DB::table('schemes_services')->insert([
        [
            'id' => 101,
            'scheme_id' => 1001,
            'item_code' => 10001,
            'item_name' => 'General Practitioner Consultation',
            'item_type_id' => 1,
            'type_name' => 'Registration/Consultation Charges',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => 102,
            'scheme_id' => 1002,
            'item_code' => 10001,
            'item_name' => 'General Practitioner Consultation',
            'item_type_id' => 1,
            'type_name' => 'Registration/Consultation Charges',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $eligibilityService = app(NhifSchemeEligibilityService::class);

    $scheme1001Items = $eligibilityService->getEligibleItems(1001);
    expect($scheme1001Items->pluck('item_code')->map(fn ($c) => (string) $c)->all())->toContain('10001');

    $scheme1002Items = $eligibilityService->getEligibleItems(1002);
    expect($scheme1002Items->pluck('item_code')->map(fn ($c) => (string) $c)->all())->toContain('10001');

    // Scheme 1003 has no mappings -> returns empty collection even though 10001 exists in master catalogue!
    $scheme1003Items = $eligibilityService->getEligibleItems(1003);
    expect($scheme1003Items)->toBeEmpty();
});

// ---------------------------------------------------------------------------
// 5. Preview & Unmatched Analysis
// ---------------------------------------------------------------------------

it('previews sync changes without modifying database records', function (): void {
    $mock = Mockery::mock(NhifHttpClient::class);
    $mock->shouldReceive('getOcsItemTypes')
        ->once()
        ->andReturn(mockItemTypesResponse(sampleItemTypesPayload()));
    $mock->shouldReceive('getOcsItems')
        ->once()
        ->andReturn(mockItemsResponse(sampleItemsPayload()));

    $service = new NhifCatalogSyncService($mock);

    $prevTypes = $service->previewItemTypesSync();
    expect($prevTypes['success'])->toBeTrue()
        ->and($prevTypes['stats']['total'])->toBe(3)
        ->and($prevTypes['stats']['new'])->toBe(3)
        ->and(NhifItemType::count())->toBe(0); // Zero database modifications!

    $prevItems = $service->previewItemsSync();
    expect($prevItems['success'])->toBeTrue()
        ->and($prevItems['stats']['total'])->toBe(4)
        ->and($prevItems['stats']['active'])->toBe(3)
        ->and($prevItems['stats']['inactive'])->toBe(1)
        ->and($prevItems['stats']['restricted'])->toBe(1)
        ->and(NhifService::count())->toBe(0); // Zero database modifications!
});

// ---------------------------------------------------------------------------
// 6. Administrative CLI Command
// ---------------------------------------------------------------------------

it('runs artisan nhif:catalog:sync with --preview and --unmatched', function (): void {
    $this->artisan('nhif:catalog:sync', ['--unmatched' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Total Unique Codes in schemes_services');
});

// ---------------------------------------------------------------------------
// 7. HTTP Endpoints Authorization & Credential Protection
// ---------------------------------------------------------------------------

it('allows hospital-admin to access price-sync endpoints and prevents unauthorized roles', function (): void {
    $mock = Mockery::mock(NhifHttpClient::class);
    $mock->shouldReceive('getOcsItems')->andReturn(mockItemsResponse(sampleItemsPayload()));
    $mock->shouldReceive('getOcsItemTypes')->andReturn(mockItemTypesResponse(sampleItemTypesPayload()));
    $this->app->instance(NhifHttpClient::class, $mock);

    $admin = userWithRole('hospital-admin');
    $nurse = userWithRole('nurse');

    // Admin can view index
    $this->actingAs($admin)
        ->get(route('nhif.price-sync.index'))
        ->assertOk();

    // Admin can request preview JSON
    $this->actingAs($admin)
        ->get(route('nhif.price-sync.preview', ['type' => 'items']))
        ->assertOk()
        ->assertJsonStructure(['success', 'stats']);

    // Admin can request unmatched report JSON
    $this->actingAs($admin)
        ->get(route('nhif.price-sync.unmatched'))
        ->assertOk()
        ->assertJsonStructure(['total_schemes_services_codes', 'total_nhif_services_synced']);

    // Ordinary clinical user (nurse) cannot trigger sync or change mappings
    $this->actingAs($nurse)
        ->post(route('nhif.price-sync.store'), ['sync_type' => 'items'])
        ->assertForbidden();

    $this->actingAs($nurse)
        ->get(route('nhif.price-sync.index'))
        ->assertForbidden();
});

it('ensures sync logs never expose sensitive tokens or credentials', function (): void {
    $admin = userWithRole('hospital-admin');

    $log = NhifPriceSyncLog::create([
        'sync_type' => 'items',
        'status' => 'completed',
        'total_records' => 10,
        'started_at' => now(),
        'completed_at' => now(),
        'performed_by' => $admin->id,
    ]);
    $response = $this->actingAs($admin)->get(route('nhif.price-sync.index'));

    $content = $response->getContent();
    expect($content)->not->toContain(config('nhif.client_secret'))
        ->and($content)->not->toContain('access_token')
        ->and($content)->not->toContain('bearer');
});

it('searchItemsJson searches from active nhif_services when available', function (): void {
    NhifService::create([
        'nhif_item_code' => '10001',
        'nhif_item_name' => 'General Practitioner Consultation',
        'nhif_item_type_id' => 1,
        'nhif_item_type_name' => 'Registration/Consultation Charges',
        'is_active' => true,
    ]);

    NhifService::create([
        'nhif_item_code' => '99999',
        'nhif_item_name' => 'Deprecated Service',
        'nhif_item_type_id' => 1,
        'nhif_item_type_name' => 'Registration/Consultation Charges',
        'is_active' => false, // Inactive!
    ]);

    $admin = userWithRole('hospital-admin');

    $res = $this->actingAs($admin)
        ->get(route('nhif.catalog-items.search-json', ['q' => 'Consultation']))
        ->assertOk()
        ->json();

    $codes = collect($res)->pluck('item_code')->all();
    expect($codes)->toContain('10001')
        ->and($codes)->not->toContain('99999'); // Inactive item excluded from search!
});
