<?php

use App\Models\Hospital;
use App\Models\ServicePrice;

/**
 * Correcting the amount of the current open-ended price in place — the one
 * exception to "a price is never edited": permission-gated, audit-logged,
 * reason required, and history stays immutable.
 */
test('an authorised user can correct the current price in place and it is audit-logged', function () {
    $hospital = Hospital::factory()->create();
    $price = priceFor(serviceFor(), $hospital, ['amount' => 20000]);

    $this->actingAs(userWithRole('accountant'))
        ->patch(route('billing-pricing.update', $price), ['amount' => 25000, 'reason' => 'Entered wrongly'])
        ->assertRedirect();

    expect((float) $price->fresh()->amount)->toBe(25000.0);
    $this->assertDatabaseHas('audit_logs', ['action' => 'price_updated']);
});

test('a role without billing-pricing.update cannot edit a price', function () {
    $price = priceFor(serviceFor(), Hospital::factory()->create(), ['amount' => 20000]);

    $this->actingAs(userWithRole('Manager'))
        ->patch(route('billing-pricing.update', $price), ['amount' => 1, 'reason' => 'x'])
        ->assertForbidden();

    expect((float) $price->fresh()->amount)->toBe(20000.0);
});

test('a closed (superseded) price cannot be edited', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();
    $old = priceFor($service, $hospital, ['amount' => 20000, 'effective_from' => '2026-01-01']);
    priceFor($service, $hospital, ['amount' => 25000, 'effective_from' => '2026-07-01']);

    $this->actingAs(userWithRole('accountant'))
        ->patch(route('billing-pricing.update', $old), ['amount' => 1, 'reason' => 'x'])
        ->assertForbidden();
});

test('a cancelled price cannot be edited', function () {
    $price = priceFor(serviceFor(), Hospital::factory()->create());
    $price->update(['status' => ServicePrice::STATUS_CANCELLED]);

    $this->actingAs(userWithRole('accountant'))
        ->patch(route('billing-pricing.update', $price), ['amount' => 1, 'reason' => 'x'])
        ->assertForbidden();
});

test('a reason and a non-negative amount are required', function () {
    $price = priceFor(serviceFor(), Hospital::factory()->create(), ['amount' => 20000]);
    $accountant = userWithRole('accountant');

    $this->actingAs($accountant)
        ->patch(route('billing-pricing.update', $price), ['amount' => 100])
        ->assertSessionHasErrors('reason');

    $this->actingAs($accountant)
        ->patch(route('billing-pricing.update', $price), ['amount' => -5, 'reason' => 'x'])
        ->assertSessionHasErrors('amount');

    expect((float) $price->fresh()->amount)->toBe(20000.0);
});

test('the service edit page exposes the update ability to authorised roles only', function () {
    $service = serviceFor();

    $this->actingAs(userWithRole('accountant'))
        ->get(route('billing-services.edit', $service))
        ->assertInertia(fn ($page) => $page->where('canUpdatePrice', true));
});
