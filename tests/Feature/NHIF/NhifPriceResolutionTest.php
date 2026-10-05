<?php

use App\Models\NhifPackagePrice;
use App\Models\PatientInsurance;
use App\Services\NHIF\NhifHttpClient;
use App\Services\NHIF\NhifPriceResolutionService;

test('resolves price from OCS API when facility code is available', function () {
    $insurance = PatientInsurance::factory()->create([
        'nhif_facility_code' => 'FAC-001',
        'nhif_scheme_id' => 100,
    ]);

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getPricePackageByItemCode')
            ->with('FAC-001', 'ITEM-100')
            ->once()
            ->andReturn([
                'success' => true,
                'data' => [['UnitPrice' => 7500.00, 'MaximumQuantity' => 30]],
                'http_status' => 200,
                'error' => null,
            ]);
    });

    $result = app(NhifPriceResolutionService::class)->resolve($insurance, 'ITEM-100', 5000.0);

    expect($result['unit_price'])->toBe(7500.0)
        ->and($result['maximum_quantity'])->toBe(30)
        ->and($result['source'])->toBe('api');
});

test('falls back to local catalog when API fails', function () {
    $insurance = PatientInsurance::factory()->create([
        'nhif_facility_code' => 'FAC-001',
        'nhif_scheme_id' => 200,
    ]);

    NhifPackagePrice::create([
        'item_code' => 'ITEM-200',
        'scheme_id' => 200,
        'price_code' => 'PC-200',
        'item_type_id' => 2,
        'unit_price' => 6000.00,
        'maximum_quantity' => 10,
        'is_restricted' => false,
    ]);

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getPricePackageByItemCode')
            ->once()
            ->andReturn(['success' => false, 'data' => [], 'http_status' => 500, 'error' => 'Timeout']);
    });

    $result = app(NhifPriceResolutionService::class)->resolve($insurance, 'ITEM-200', 5000.0);

    expect($result['unit_price'])->toBe(6000.0)
        ->and($result['maximum_quantity'])->toBe(10)
        ->and($result['source'])->toBe('local_catalog');
});

test('falls back to charge price when no NHIF price available', function () {
    $insurance = PatientInsurance::factory()->create([
        'nhif_facility_code' => null,
        'nhif_scheme_id' => null,
    ]);

    $result = app(NhifPriceResolutionService::class)->resolve($insurance, 'ITEM-UNKNOWN', 3500.0);

    expect($result['unit_price'])->toBe(3500.0)
        ->and($result['maximum_quantity'])->toBeNull()
        ->and($result['source'])->toBe('charge_fallback');
});

test('returns null maximum_quantity when API response omits it', function () {
    $insurance = PatientInsurance::factory()->create([
        'nhif_facility_code' => 'FAC-002',
        'nhif_scheme_id' => null,
    ]);

    $this->mock(NhifHttpClient::class, function ($mock) {
        $mock->shouldReceive('getPricePackageByItemCode')
            ->once()
            ->andReturn([
                'success' => true,
                'data' => ['UnitPrice' => 4000.00],
                'http_status' => 200,
                'error' => null,
            ]);
    });

    $result = app(NhifPriceResolutionService::class)->resolve($insurance, 'ITEM-300', 3000.0);

    expect($result['unit_price'])->toBe(4000.0)
        ->and($result['maximum_quantity'])->toBeNull()
        ->and($result['source'])->toBe('api');
});
