<?php

use App\Models\Hospital;
use App\Models\LaboratoryTest;
use App\Models\RadiologyProcedure;
use App\Models\Service;
use App\Services\ServiceCatalogService;
use App\Services\ServicePriceResolver;
use Illuminate\Database\QueryException;

/**
 * Module mapping verification (Phase 7, Step 53, spec §23-25/§54): a
 * LaboratoryTest/RadiologyProcedure maps to a billing Service via the
 * polymorphic `serviceable_type`/`serviceable_id` pointer, without
 * duplicating the clinical catalog's own identity — and the mapped
 * service can be priced and resolved exactly like a standalone one.
 */
test('a laboratory test maps to a billing service without duplicating the laboratory catalog', function () {
    $test = activeLaboratoryTest(['code' => 'LAB-HB', 'name' => 'Hemoglobin']);
    $actor = userWithRole('accountant');

    $service = app(ServiceCatalogService::class)->create([
        'code' => $test->code,
        'name' => $test->name,
        'category' => 'laboratory',
        'serviceable_type' => LaboratoryTest::class,
        'serviceable_id' => $test->id,
    ], requestAs($actor));

    expect($service->serviceable_type)->toBe(LaboratoryTest::class);
    expect($service->serviceable_id)->toBe($test->id);
    $service->load('serviceable');
    expect($service->serviceable->id)->toBe($test->id);
    expect(LaboratoryTest::query()->count())->toBe(1);
});

test('a radiology procedure maps to a billing service and can be fully priced and resolved', function () {
    $procedure = activeRadiologyProcedure(['code' => 'XR-CHEST', 'name' => 'Chest X-Ray']);
    $hospital = Hospital::factory()->create();
    $actor = userWithRole('accountant');

    $service = app(ServiceCatalogService::class)->create([
        'code' => $procedure->code,
        'name' => $procedure->name,
        'category' => 'radiology',
        'serviceable_type' => RadiologyProcedure::class,
        'serviceable_id' => $procedure->id,
    ], requestAs($actor));

    $price = priceFor($service, $hospital, ['amount' => 20000]);

    $resolved = app(ServicePriceResolver::class)->resolvePrice($service->fresh(), $hospital->id);

    expect($resolved->id)->toBe($price->id);
    expect((float) $resolved->amount)->toBe(20000.0);
    expect(RadiologyProcedure::query()->count())->toBe(1);
});

test('a clinical consultation service has no serviceable mapping — no consultation-type catalog exists to map from', function () {
    $service = serviceFor(['code' => 'CONSULT-GP', 'name' => 'General Consultation', 'category' => 'consultation']);

    expect($service->serviceable_type)->toBeNull();
    expect($service->serviceable_id)->toBeNull();
});

test('at most one service may map to a given laboratory test', function () {
    $test = activeLaboratoryTest();
    $actor = userWithRole('accountant');

    app(ServiceCatalogService::class)->create([
        'code' => 'MAP-1', 'name' => 'x', 'category' => 'laboratory',
        'serviceable_type' => LaboratoryTest::class, 'serviceable_id' => $test->id,
    ], requestAs($actor));

    expect(fn () => app(ServiceCatalogService::class)->create([
        'code' => 'MAP-2', 'name' => 'y', 'category' => 'laboratory',
        'serviceable_type' => LaboratoryTest::class, 'serviceable_id' => $test->id,
    ], requestAs($actor)))->toThrow(QueryException::class);

    expect(Service::query()->count())->toBe(1);
});
