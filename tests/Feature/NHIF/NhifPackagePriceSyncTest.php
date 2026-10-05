<?php

/**
 * NhifPackagePriceSyncTest — Phase 8 Walk-in Preapproval (Package Prices)
 *
 * Scenarios:
 *  1.  syncPackagePrices() creates nhif_package_prices rows + sync log with type 'package_prices'
 *  2.  Re-sync is idempotent (updateOrCreate — row count stays the same)
 *  3.  NhifHttpClient failure marks sync log 'failed' without deleting existing rows
 *  4.  syncPackagePrices() scopes request to scheme/package when provided
 *  5.  NhifPackagePrice::resolveUnitPrice() returns correct price for item+scheme
 *  6.  resolveUnitPrice() returns null when no matching row
 *  7.  Consultation preapproval uses package price (not charge price) when available
 *  8.  Consultation preapproval falls back to charge price when no package price found
 *  9.  Walk-in NHIF flow rejects when practitioner has no nhif_practitioner_no
 *  10. Walk-in CASH flow ignores practitioner NHIF number check
 *  11. NhifPreApprovalSession records practitioner_no when session is created
 *  12. Duplicate preapproval for same source returns existing item (idempotency guard)
 */

use App\Models\Charge;
use App\Models\NhifPackagePrice;
use App\Models\NhifPreApprovalSessionItem;
use App\Models\NhifPriceSyncLog;
use App\Models\NhifSchemeService;
use App\Models\NhifServiceMapping;
use App\Models\PatientInsurance;
use App\Models\ReceptionVisit;
use App\Models\Service;
use App\Services\NHIF\NhifCatalogSyncService;
use App\Services\NHIF\NhifConsultationAuthorizationService;
use App\Services\NHIF\NhifHttpClient;
use App\Services\NHIF\NhifPreApprovalSessionService;
use App\Services\ReceptionVisitService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

// ---------------------------------------------------------------------------
// Helper: build a raw NHIF GetPricePackage API response
// ---------------------------------------------------------------------------

function packagePriceResponse(array $items, bool $success = true): array
{
    return [
        'success' => $success,
        'data' => $success ? ['PricePackages' => $items] : [],
        'http_status' => $success ? 200 : 500,
        'error' => $success ? null : 'OCS unavailable',
        'correlation_id' => 'test-pkg',
    ];
}

// ---------------------------------------------------------------------------
// Scenario 1 — sync creates rows + log
// ---------------------------------------------------------------------------

it('syncPackagePrices creates nhif_package_prices rows and a sync log with type package_prices', function (): void {
    $this->actingAs(userWithRole('hospital-admin'));

    $mock = Mockery::mock(NhifHttpClient::class);
    $mock->shouldReceive('getPricePackage')->once()->andReturn(packagePriceResponse([
        ['ItemCode' => 'CONSULT-GP', 'ItemName' => 'GP Consultation', 'SchemeID' => 5001, 'PackageID' => 10, 'UnitPrice' => 2500.00],
        ['ItemCode' => 'CONSULT-DT', 'ItemName' => 'Dental Consultation', 'SchemeID' => 5001, 'PackageID' => 10, 'UnitPrice' => 3500.00],
    ]));

    $service = new NhifCatalogSyncService($mock);
    $log = $service->syncPackagePrices();

    expect($log->sync_type)->toBe('package_prices');
    expect($log->status)->toBe('completed');
    expect($log->new_records)->toBe(2);
    expect($log->updated_records)->toBe(0);
    expect(NhifPackagePrice::count())->toBe(2);
    expect(NhifPackagePrice::where('item_code', 'CONSULT-GP')->first()?->unit_price)->toBe(2500.0);
});

// ---------------------------------------------------------------------------
// Scenario 2 — re-sync is idempotent
// ---------------------------------------------------------------------------

it('re-syncing the same package prices does not create duplicates', function (): void {
    $this->actingAs(userWithRole('hospital-admin'));

    $payload = packagePriceResponse([
        ['ItemCode' => 'LAB-BLOOD', 'SchemeID' => 5002, 'PackageID' => 20, 'UnitPrice' => 1500.00],
    ]);

    $mock = Mockery::mock(NhifHttpClient::class);
    $mock->shouldReceive('getPricePackage')->once()->andReturn($payload);
    (new NhifCatalogSyncService($mock))->syncPackagePrices();

    $mock2 = Mockery::mock(NhifHttpClient::class);
    $mock2->shouldReceive('getPricePackage')->once()->andReturn($payload);
    (new NhifCatalogSyncService($mock2))->syncPackagePrices();

    expect(NhifPackagePrice::count())->toBe(1);
    expect(NhifPackagePrice::where('item_code', 'LAB-BLOOD')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Scenario 3 — failure marks log failed without deleting existing rows
// ---------------------------------------------------------------------------

it('syncPackagePrices marks log failed and preserves existing rows when API fails', function (): void {
    $this->actingAs(userWithRole('hospital-admin'));

    NhifPackagePrice::factory()->create(['item_code' => 'EXISTING-001', 'scheme_id' => 5003, 'unit_price' => 1000.00]);

    $mock = Mockery::mock(NhifHttpClient::class);
    $mock->shouldReceive('getPricePackage')->once()->andReturn(packagePriceResponse([], false));

    $log = (new NhifCatalogSyncService($mock))->syncPackagePrices();

    expect($log->status)->toBe('failed');
    expect(NhifPackagePrice::where('item_code', 'EXISTING-001')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Scenario 4 — sync scopes request to scheme/package when provided
// ---------------------------------------------------------------------------

it('syncPackagePrices passes scheme and package IDs to the HTTP client', function (): void {
    $this->actingAs(userWithRole('hospital-admin'));

    $mock = Mockery::mock(NhifHttpClient::class);
    $mock->shouldReceive('getPricePackage')
        ->once()
        ->with(8001, 200)
        ->andReturn(packagePriceResponse([]));

    (new NhifCatalogSyncService($mock))->syncPackagePrices(8001, 200);

    expect(NhifPriceSyncLog::where('sync_type', 'package_prices')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Scenario 5 — resolveUnitPrice returns price for item + scheme
// ---------------------------------------------------------------------------

it('resolveUnitPrice returns the correct unit price for a matching item_code and scheme_id', function (): void {
    NhifPackagePrice::factory()->create(['item_code' => 'CONSULT-GP', 'scheme_id' => 5001, 'unit_price' => 2500.00]);

    $price = NhifPackagePrice::resolveUnitPrice('CONSULT-GP', 5001);

    expect($price)->toBe(2500.0);
});

// ---------------------------------------------------------------------------
// Scenario 6 — resolveUnitPrice returns null when no match
// ---------------------------------------------------------------------------

it('resolveUnitPrice returns null when no matching row exists', function (): void {
    $price = NhifPackagePrice::resolveUnitPrice('NONEXISTENT-CODE', 9999);

    expect($price)->toBeNull();
});

// ---------------------------------------------------------------------------
// Helper: set up NHIF consultation preapproval context
// ---------------------------------------------------------------------------

function preapprovalCtx(string $itemCode, int $schemeId, string $authNo): array
{
    $ctx = diagnosisReadyEncounter();
    $practitioner = $ctx['doctor']['practitioner'];
    $practitioner->nhif_practitioner_no = 'MCT20240628';
    $practitioner->save();

    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_authorization_no' => $authNo,
        'nhif_scheme_id' => $schemeId,
    ]);

    $department = $ctx['encounter']->department;
    $hospital = $department->hospital;
    $service = Service::factory()->create();

    NhifServiceMapping::create([
        'hospital_id' => $hospital->id,
        'service_id' => $service->id,
        'nhif_item_code' => $itemCode,
        'nhif_item_name' => 'Test Consultation',
        'is_active' => true,
        'coverage_status' => NhifServiceMapping::COVERAGE_COVERED,
        'created_by' => $ctx['doctor']['user']->id,
    ]);

    NhifSchemeService::factory()->create([
        'scheme_id' => $schemeId,
        'item_code' => $itemCode,
        'is_active' => true,
    ]);

    $visit = ReceptionVisit::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'practitioner_id' => $practitioner->id,
        'department_id' => $department->id,
    ]);

    $charge = Charge::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'hospital_id' => $hospital->id,
        'service_id' => $service->id,
        'source_type' => ReceptionVisit::class,
        'source_id' => $visit->id,
        'unit_price' => 1000.00,
        'net_amount' => 1000.00,
        'status' => Charge::STATUS_POSTED,
    ]);

    return compact('ctx', 'practitioner', 'insurance', 'visit', 'charge');
}

// ---------------------------------------------------------------------------
// Scenario 7 — preapproval uses package price when available
// ---------------------------------------------------------------------------

it('consultation preapproval uses NHIF catalog unit_price when a package price record exists', function (): void {
    $setup = preapprovalCtx('CONSULT-GP', 5001, 'AUTH-TEST-007');

    NhifPackagePrice::factory()->create(['item_code' => 'CONSULT-GP', 'scheme_id' => 5001, 'unit_price' => 2500.00]);

    $request = Request::create('/test');
    $request->setUserResolver(fn () => $setup['ctx']['doctor']['user']);

    app(NhifConsultationAuthorizationService::class)
        ->authorizeConsultation($setup['visit'], $setup['charge'], $request);

    $item = NhifPreApprovalSessionItem::query()
        ->where('source_type', ReceptionVisit::class)
        ->where('source_id', $setup['visit']->id)
        ->first();

    expect($item)->not->toBeNull();
    expect((float) $item->unit_price)->toBe(2500.0);
});

// ---------------------------------------------------------------------------
// Scenario 8 — preapproval falls back to charge price when no catalog price
// ---------------------------------------------------------------------------

it('consultation preapproval falls back to charge unit_price when no package price exists', function (): void {
    $setup = preapprovalCtx('CONSULT-FU', 5002, 'AUTH-TEST-008');
    $setup['charge']->update(['unit_price' => 800.00, 'net_amount' => 800.00]);

    $request = Request::create('/test');
    $request->setUserResolver(fn () => $setup['ctx']['doctor']['user']);

    app(NhifConsultationAuthorizationService::class)
        ->authorizeConsultation($setup['visit'], $setup['charge']->fresh(), $request);

    $item = NhifPreApprovalSessionItem::query()
        ->where('source_type', ReceptionVisit::class)
        ->where('source_id', $setup['visit']->id)
        ->first();

    expect($item)->not->toBeNull();
    expect((float) $item->unit_price)->toBe(800.0);
});

// ---------------------------------------------------------------------------
// Scenario 9 — walk-in NHIF rejects practitioner without nhif_practitioner_no
// ---------------------------------------------------------------------------

it('walk-in creation throws ValidationException when NHIF flow but practitioner has no nhif_practitioner_no', function (): void {
    $ctx = diagnosisReadyEncounter();
    $practitioner = $ctx['doctor']['practitioner'];
    $practitioner->nhif_practitioner_no = null;
    $practitioner->save();

    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_authorization_no' => 'AUTH-TEST-009',
        'nhif_scheme_id' => 5003,
    ]);

    $department = $ctx['encounter']->department;
    $hospital = $department->hospital;
    $service = Service::factory()->create();

    NhifServiceMapping::create([
        'hospital_id' => $hospital->id,
        'service_id' => $service->id,
        'nhif_item_code' => 'CONSULT-GP',
        'nhif_item_name' => 'GP Consultation',
        'is_active' => true,
        'coverage_status' => NhifServiceMapping::COVERAGE_COVERED,
        'created_by' => $ctx['doctor']['user']->id,
    ]);

    NhifSchemeService::factory()->create([
        'scheme_id' => 5003,
        'item_code' => 'CONSULT-GP',
        'is_active' => true,
    ]);

    $request = Request::create('/test');
    $request->setUserResolver(fn () => userWithRole('receptionist'));

    expect(fn () => app(ReceptionVisitService::class)->createWalkIn([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $department->id,
        'visit_type' => 'walk_in',
        'practitioner_id' => $practitioner->id,
        'nhif_item_code' => 'CONSULT-GP',
    ], $request))->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// Scenario 10 — CASH flow ignores NHIF practitioner number check
// ---------------------------------------------------------------------------

it('walk-in cash visit succeeds even when the practitioner has no nhif_practitioner_no', function (): void {
    $ctx = diagnosisReadyEncounter();
    $practitioner = $ctx['doctor']['practitioner'];
    $practitioner->nhif_practitioner_no = null;
    $practitioner->save();

    $department = $ctx['encounter']->department;

    $request = Request::create('/test');
    $request->setUserResolver(fn () => userWithRole('receptionist'));

    $visit = app(ReceptionVisitService::class)->createWalkIn([
        'patient_id' => $ctx['patient']->id,
        'department_id' => $department->id,
        'visit_type' => 'walk_in',
        'practitioner_id' => $practitioner->id,
    ], $request);

    expect($visit->id)->toBeInt();
});

// ---------------------------------------------------------------------------
// Scenario 11 — session stores practitioner_no
// ---------------------------------------------------------------------------

it('NhifPreApprovalSession stores the practitioner_no when provided to create()', function (): void {
    $ctx = diagnosisReadyEncounter();

    $insurance = PatientInsurance::factory()->create([
        'patient_id' => $ctx['patient']->id,
        'status' => PatientInsurance::STATUS_ACTIVE,
        'verification_status' => PatientInsurance::VERIFICATION_VERIFIED,
        'nhif_authorization_no' => 'AUTH-TEST-011',
        'nhif_scheme_id' => 5001,
    ]);

    NhifSchemeService::factory()->create([
        'scheme_id' => 5001,
        'item_code' => 'CONSULT-GP',
        'is_active' => true,
    ]);

    // Use the doctor's user — they have staff with hospital_id, which the
    // session service uses when no encounter is provided.
    $request = Request::create('/test');
    $request->setUserResolver(fn () => $ctx['doctor']['user']);

    $session = app(NhifPreApprovalSessionService::class)->create(
        $insurance,
        null,
        [[
            'source_type' => null,
            'source_id' => null,
            'nhif_item_code' => 'CONSULT-GP',
            'nhif_item_name' => 'GP Consultation',
            'quantity' => 1,
            'unit_price' => 2500.00,
        ]],
        $request,
        'MCT20240628',
    );

    expect($session->practitioner_no)->toBe('MCT20240628');
});

// ---------------------------------------------------------------------------
// Scenario 12 — duplicate preapproval guard
// ---------------------------------------------------------------------------

it('authorizeConsultation reuses an existing session item for the same visit (idempotency)', function (): void {
    $setup = preapprovalCtx('CONSULT-GP', 5001, 'AUTH-TEST-012');
    $setup['charge']->update(['unit_price' => 2500.00, 'net_amount' => 2500.00]);

    $request = Request::create('/test');
    $request->setUserResolver(fn () => $setup['ctx']['doctor']['user']);

    $authService = app(NhifConsultationAuthorizationService::class);

    $authService->authorizeConsultation($setup['visit'], $setup['charge']->fresh(), $request);
    $countAfterFirst = NhifPreApprovalSessionItem::query()
        ->where('source_type', ReceptionVisit::class)
        ->where('source_id', $setup['visit']->id)
        ->count();

    $authService->authorizeConsultation($setup['visit'], $setup['charge']->fresh(), $request);
    $countAfterSecond = NhifPreApprovalSessionItem::query()
        ->where('source_type', ReceptionVisit::class)
        ->where('source_id', $setup['visit']->id)
        ->count();

    expect($countAfterFirst)->toBe(1);
    expect($countAfterSecond)->toBe(1);
});
