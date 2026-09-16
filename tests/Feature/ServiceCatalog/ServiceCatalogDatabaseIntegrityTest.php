<?php

use App\Models\Hospital;
use App\Models\Service;
use App\Models\ServicePrice;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * Database integrity for the service catalog + pricing module (Phase 7,
 * Step 53, spec §50), mirroring StockDatabaseIntegrityTest's own style.
 */
test('the service catalog and pricing tables exist with their expected columns', function () {
    expect(Schema::hasTable('services'))->toBeTrue();
    expect(Schema::hasTable('service_prices'))->toBeTrue();

    expect(Schema::hasColumns('services', ['code', 'name', 'category', 'serviceable_type', 'serviceable_id', 'is_billable', 'status']))->toBeTrue();
    expect(Schema::hasColumns('service_prices', [
        'service_id', 'hospital_id', 'amount', 'currency', 'effective_from', 'effective_to', 'status',
    ]))->toBeTrue();
});

test('service codes are globally unique', function () {
    serviceFor(['code' => 'UNIQUE-CODE']);

    expect(fn () => Service::factory()->create(['code' => 'UNIQUE-CODE']))->toThrow(QueryException::class);
});

test('services carry no hospital_id column — the catalog is global, matching Medication/LaboratoryTest/RadiologyProcedure', function () {
    expect(Schema::hasColumn('services', 'hospital_id'))->toBeFalse();
    expect(Schema::hasColumn('services', 'department_id'))->toBeFalse();
});

test('a service price requires its parent service and hospital to exist', function () {
    $hospital = Hospital::factory()->create();

    expect(fn () => ServicePrice::factory()->create(['service_id' => 999999, 'hospital_id' => $hospital->id]))
        ->toThrow(QueryException::class);
});
