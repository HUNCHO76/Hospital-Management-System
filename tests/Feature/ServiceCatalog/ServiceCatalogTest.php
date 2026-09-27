<?php

use App\Models\Hospital;
use App\Models\Service;
use App\Services\ServiceCatalogService;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * The central service catalog's own lifecycle (Phase 7, Step 53, spec
 * §36-38): create, update, code stability once priced, activate/deactivate.
 */
test('creating a service defaults it to active', function () {
    $service = serviceFor(['code' => 'CONSULT-GP', 'name' => 'General Consultation', 'category' => 'consultation']);

    expect($service->status)->toBe(Service::STATUS_ACTIVE);
    expect($service->is_billable)->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'service_created']);
});

test('an invalid category is rejected', function () {
    expect(fn () => serviceFor(['category' => 'not-a-real-category']))->toThrow(ValidationException::class);
});

test('duplicate service codes are rejected at the database level', function () {
    serviceFor(['code' => 'DUPLICATE-CODE']);

    expect(fn () => serviceFor(['code' => 'DUPLICATE-CODE']))->toThrow(QueryException::class);
});

test('a service\'s code can be changed freely before it has ever been priced', function () {
    $service = serviceFor(['code' => 'OLD-CODE']);
    $actor = userWithRole('accountant');

    $updated = app(ServiceCatalogService::class)->update($service, ['code' => 'NEW-CODE'], requestAs($actor));

    expect($updated->code)->toBe('NEW-CODE');
    $this->assertDatabaseHas('audit_logs', ['action' => 'service_updated']);
});

test('a service\'s code is silently protected once it has been priced', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor(['code' => 'LOCKED-CODE']);
    priceFor($service, $hospital);
    $actor = userWithRole('accountant');

    $updated = app(ServiceCatalogService::class)->update($service->fresh(), ['code' => 'ATTEMPTED-CHANGE', 'name' => 'New Name'], requestAs($actor));

    expect($updated->code)->toBe('LOCKED-CODE');
    expect($updated->name)->toBe('New Name');
});

test('deactivating a service prevents no historical data — it is never deleted', function () {
    $service = serviceFor();
    $actor = userWithRole('accountant');

    $deactivated = app(ServiceCatalogService::class)->deactivate($service, requestAs($actor));

    expect($deactivated->status)->toBe(Service::STATUS_INACTIVE);
    expect(Service::query()->whereKey($service->id)->exists())->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'service_deactivated']);
});

test('a service cannot be deactivated twice in a row', function () {
    $service = serviceFor();
    $actor = userWithRole('accountant');
    $service = app(ServiceCatalogService::class)->deactivate($service, requestAs($actor));

    expect(fn () => app(ServiceCatalogService::class)->deactivate($service->fresh(), requestAs($actor)))->toThrow(ValidationException::class);
});

test('a deactivated service can be reactivated', function () {
    $service = serviceFor();
    $actor = userWithRole('accountant');
    $service = app(ServiceCatalogService::class)->deactivate($service, requestAs($actor));

    $reactivated = app(ServiceCatalogService::class)->activate($service->fresh(), requestAs($actor));

    expect($reactivated->status)->toBe(Service::STATUS_ACTIVE);
    $this->assertDatabaseHas('audit_logs', ['action' => 'service_activated']);
});

test('a service with more than one page of price history is paginated on its show page', function () {
    $service = serviceFor();
    $hospitals = Hospital::factory()->count(21)->create();
    $hospitals->each(fn ($hospital) => priceFor($service, $hospital));
    $accountant = userWithRole('accountant');

    $this->actingAs($accountant)->get(route('billing-services.show', $service))
        ->assertInertia(fn ($page) => $page
            ->has('prices.data', 20)
            ->where('prices.total', 21)
            ->has('prices.links'));
});
