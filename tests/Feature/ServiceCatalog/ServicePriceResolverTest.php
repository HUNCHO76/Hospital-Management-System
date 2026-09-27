<?php

use App\Models\Hospital;
use App\Models\Practitioner;
use App\Services\ServiceCatalogService;
use App\Services\ServicePriceResolver;
use App\Services\ServicePricingService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The single authoritative price-resolution mechanism (Phase 7, Step 53,
 * spec §30/§49) and the historical-pricing guarantee spec §53 explicitly
 * demands: a past transaction date must always resolve to the price that
 * was actually in effect then, never today's current price.
 */
test('resolvePrice returns the price whose effective period covers the given date', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();
    priceFor($service, $hospital, ['amount' => 20000, 'effective_from' => '2026-01-01']);

    $resolved = app(ServicePriceResolver::class)->resolvePrice($service, $hospital->id, Carbon::parse('2026-03-15'));

    expect((float) $resolved->amount)->toBe(20000.0);
});

test('a historical transaction date resolves to the price that was actually in effect then, even after the current price has since changed', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();

    priceFor($service, $hospital, ['amount' => 20000, 'effective_from' => '2026-01-01']);
    priceFor($service, $hospital, ['amount' => 25000, 'effective_from' => '2026-07-01']);

    $resolver = app(ServicePriceResolver::class);

    $june = $resolver->resolvePrice($service, $hospital->id, Carbon::parse('2026-06-15'));
    $july = $resolver->resolvePrice($service, $hospital->id, Carbon::parse('2026-07-15'));

    expect((float) $june->amount)->toBe(20000.0);
    expect((float) $july->amount)->toBe(25000.0);
});

test('resolving with no price configured for that date is rejected', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();
    priceFor($service, $hospital, ['effective_from' => '2026-01-01', 'effective_to' => '2026-06-30']);

    expect(fn () => app(ServicePriceResolver::class)->resolvePrice($service, $hospital->id, Carbon::parse('2026-08-01')))
        ->toThrow(ValidationException::class);
});

test('resolving a price for a different facility that has none is rejected, even though another facility has one', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $service = serviceFor();
    priceFor($service, $hospitalA);

    expect(fn () => app(ServicePriceResolver::class)->resolvePrice($service, $hospitalB->id))->toThrow(ValidationException::class);
});

test('resolving a non-billable service is rejected regardless of pricing', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor(['is_billable' => false]);
    priceFor($service, $hospital);

    expect(fn () => app(ServicePriceResolver::class)->resolvePrice($service, $hospital->id))->toThrow(ValidationException::class);
});

test('resolving an inactive service is rejected regardless of pricing', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();
    priceFor($service, $hospital);
    $actor = userWithRole('accountant');
    app(ServiceCatalogService::class)->deactivate($service, requestAs($actor));

    expect(fn () => app(ServicePriceResolver::class)->resolvePrice($service->fresh(), $hospital->id))->toThrow(ValidationException::class);
});

test('a cancelled price is never resolved, even if its dates would otherwise cover the transaction date', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();
    $price = priceFor($service, $hospital, ['effective_from' => now()->subMonth()->toDateString()]);
    $actor = userWithRole('accountant');
    app(ServicePricingService::class)->cancel($price, 'mistake', requestAs($actor));

    expect(fn () => app(ServicePriceResolver::class)->resolvePrice($service->fresh(), $hospital->id))->toThrow(ValidationException::class);
});

test('a practitioner-specific price wins over a facility-wide price covering the same date', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();
    $practitioner = Practitioner::factory()->create();

    priceFor($service, $hospital, ['amount' => 20000, 'effective_from' => '2026-01-01']);
    priceFor($service, $hospital, ['amount' => 35000, 'effective_from' => '2026-01-01', 'practitioner_id' => $practitioner->id]);

    $resolved = app(ServicePriceResolver::class)->resolvePrice($service, $hospital->id, Carbon::parse('2026-03-15'), $practitioner->id);

    expect((float) $resolved->amount)->toBe(35000.0)
        ->and($resolved->practitioner_id)->toBe($practitioner->id);
});

test('resolving without a practitioner id returns the facility-wide price even when a practitioner-specific price also exists', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();
    $practitioner = Practitioner::factory()->create();

    priceFor($service, $hospital, ['amount' => 20000, 'effective_from' => '2026-01-01']);
    priceFor($service, $hospital, ['amount' => 35000, 'effective_from' => '2026-01-01', 'practitioner_id' => $practitioner->id]);

    $resolved = app(ServicePriceResolver::class)->resolvePrice($service, $hospital->id, Carbon::parse('2026-03-15'));

    expect((float) $resolved->amount)->toBe(20000.0)
        ->and($resolved->practitioner_id)->toBeNull();
});

test('a practitioner with no practitioner-specific price falls back to the facility-wide price', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();
    $practitioner = Practitioner::factory()->create();
    priceFor($service, $hospital, ['amount' => 20000, 'effective_from' => '2026-01-01']);

    $resolved = app(ServicePriceResolver::class)->resolvePrice($service, $hospital->id, Carbon::parse('2026-03-15'), $practitioner->id);

    expect((float) $resolved->amount)->toBe(20000.0);
});
