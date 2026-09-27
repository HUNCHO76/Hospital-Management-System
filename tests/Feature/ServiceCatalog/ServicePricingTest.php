<?php

use App\Models\Hospital;
use App\Models\Practitioner;
use App\Models\ServicePrice;
use App\Services\ServicePricingService;
use Illuminate\Validation\ValidationException;

/**
 * Effective-dated, facility-specific pricing (Phase 7, Step 53, spec §15-19/
 * §31/§40/§53): a price is never edited, overlaps are rejected, and a new
 * price correctly supersedes an existing open-ended one.
 */
test('creating a price for a service+facility that has none yet succeeds', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();

    $price = priceFor($service, $hospital, ['amount' => 20000, 'effective_from' => '2026-01-01']);

    expect((float) $price->amount)->toBe(20000.0);
    expect($price->status)->toBe(ServicePrice::STATUS_ACTIVE);
    expect($price->currency)->toBe(config('billing.currency'));
    $this->assertDatabaseHas('audit_logs', ['action' => 'price_created']);
});

test('submitting a practitioner_id via the pricing form creates a practitioner-specific price', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();
    $practitioner = Practitioner::factory()->create();
    $accountant = userWithRole('accountant');

    $this->actingAs($accountant)->post(route('billing-pricing.store'), [
        'service_id' => $service->id,
        'hospital_id' => $hospital->id,
        'practitioner_id' => $practitioner->id,
        'amount' => 18000,
        'effective_from' => now()->toDateString(),
    ])->assertRedirect();

    $price = ServicePrice::query()->where('service_id', $service->id)->sole();

    expect($price->practitioner_id)->toBe($practitioner->id);
    expect((float) $price->amount)->toBe(18000.0);
});

test('a new open-ended price automatically closes out the previous open-ended price the day before it starts', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();

    $old = priceFor($service, $hospital, ['amount' => 20000, 'effective_from' => '2026-01-01']);
    expect($old->effective_to)->toBeNull();

    $new = priceFor($service, $hospital, ['amount' => 25000, 'effective_from' => '2026-07-01']);

    expect($old->fresh()->effective_to->toDateString())->toBe('2026-06-30');
    expect((float) $new->amount)->toBe(25000.0);
    expect($new->effective_to)->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'price_superseded']);
});

test('two genuinely overlapping bounded price periods are rejected', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();

    priceFor($service, $hospital, ['amount' => 20000, 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);

    expect(fn () => priceFor($service, $hospital, ['amount' => 22000, 'effective_from' => '2026-06-01', 'effective_to' => '2026-12-31']))
        ->toThrow(ValidationException::class);

    expect(ServicePrice::query()->count())->toBe(1);
});

test('a new price starting before the current open-ended price is rejected, not silently superseded', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();

    priceFor($service, $hospital, ['amount' => 20000, 'effective_from' => '2026-06-01']);

    expect(fn () => priceFor($service, $hospital, ['amount' => 18000, 'effective_from' => '2026-01-01']))
        ->toThrow(ValidationException::class);
});

test('the same service can have independent, non-conflicting prices at two different facilities', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $service = serviceFor();

    $priceA = priceFor($service, $hospitalA, ['amount' => 20000]);
    $priceB = priceFor($service, $hospitalB, ['amount' => 25000]);

    expect((float) $priceA->amount)->toBe(20000.0);
    expect((float) $priceB->amount)->toBe(25000.0);
});

test('a negative, non-numeric, or infinite amount is rejected; zero is explicitly allowed for sponsored services', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();

    expect(fn () => priceFor($service, $hospital, ['amount' => -100]))->toThrow(ValidationException::class);
    expect(fn () => priceFor($service, $hospital, ['amount' => 'abc']))->toThrow(ValidationException::class);
    expect(fn () => priceFor($service, $hospital, ['amount' => INF]))->toThrow(ValidationException::class);

    $free = priceFor($service, $hospital, ['amount' => 0]);
    expect((float) $free->amount)->toBe(0.0);
});

test('an effective_to before effective_from is rejected', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();

    expect(fn () => priceFor($service, $hospital, ['effective_from' => '2026-06-01', 'effective_to' => '2026-01-01']))
        ->toThrow(ValidationException::class);
});

test('cancelling a price requires a reason and never deletes the row', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();
    $price = priceFor($service, $hospital);
    $actor = userWithRole('accountant');

    expect(fn () => app(ServicePricingService::class)->cancel($price, '', requestAs($actor)))->toThrow(ValidationException::class);

    $cancelled = app(ServicePricingService::class)->cancel($price->fresh(), 'Data entry mistake', requestAs($actor));

    expect($cancelled->status)->toBe(ServicePrice::STATUS_CANCELLED);
    expect(ServicePrice::query()->whereKey($price->id)->exists())->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'price_deactivated']);
});

test('a price cannot be cancelled twice', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();
    $price = priceFor($service, $hospital);
    $actor = userWithRole('accountant');
    $price = app(ServicePricingService::class)->cancel($price, 'reason', requestAs($actor));

    expect(fn () => app(ServicePricingService::class)->cancel($price->fresh(), 'reason again', requestAs($actor)))
        ->toThrow(ValidationException::class);
});

test('the pricing index eager-loads and serializes the practitioner on a practitioner-specific price', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();
    $practitioner = Practitioner::factory()->create();
    priceFor($service, $hospital, ['practitioner_id' => $practitioner->id]);
    $accountant = userWithRole('accountant');

    $this->actingAs($accountant)->get(route('billing-pricing.index'))
        ->assertInertia(fn ($page) => $page
            ->where('prices.data.0.practitioner.id', $practitioner->id)
            ->where('prices.data.0.practitioner.practitioner_number', $practitioner->practitioner_number));
});

test('a facility-wide price serializes a null practitioner on both the pricing index and the service show page', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();
    priceFor($service, $hospital);
    $accountant = userWithRole('accountant');

    $this->actingAs($accountant)->get(route('billing-pricing.index'))
        ->assertInertia(fn ($page) => $page->where('prices.data.0.practitioner', null));

    $this->actingAs($accountant)->get(route('billing-services.show', $service))
        ->assertInertia(fn ($page) => $page->where('prices.data.0.practitioner', null));
});
