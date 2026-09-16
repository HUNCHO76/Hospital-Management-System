<?php

use App\Models\Hospital;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\User;

/**
 * Security-focused validation for the service catalog + pricing module
 * (Phase 7, Step 53, spec §46): mass assignment and price manipulation are
 * rejected server-side, never relying on the frontend's own constraints.
 */
test('a client-supplied status, is_referenced, or created_by is never mass-assignable through the store-service request', function () {
    $accountant = userWithRole('accountant');
    $otherUser = userWithRole('accountant');

    $this->actingAs($accountant)->post(route('billing-services.store'), [
        'code' => 'SEC-TEST',
        'name' => 'Security Test Service',
        'category' => 'consultation',
        // Attempted injection — none of these are accepted fields.
        'status' => 'inactive',
        'created_by' => $otherUser->id,
        'id' => 999999,
    ]);

    $service = Service::query()->where('code', 'SEC-TEST')->sole();
    expect($service->status)->toBe(Service::STATUS_ACTIVE);
    expect($service->createdBy->id)->toBe($accountant->id);
    expect($service->id)->not->toBe(999999);
});

test('a client-supplied hospital_id, status, or created_by is never mass-assignable through the store-price request', function () {
    $hospital = Hospital::factory()->create();
    $otherHospital = Hospital::factory()->create();
    $service = serviceFor();
    $accountant = userWithRole('accountant');
    $otherUser = userWithRole('accountant');

    $this->actingAs($accountant)->post(route('billing-pricing.store'), [
        'service_id' => $service->id,
        'hospital_id' => $hospital->id,
        'amount' => 10000,
        'effective_from' => now()->toDateString(),
        // Attempted injection.
        'status' => 'cancelled',
        'created_by' => $otherUser->id,
        'currency' => 'USD',
    ]);

    $price = ServicePrice::query()->where('service_id', $service->id)->sole();
    expect($price->status)->toBe(ServicePrice::STATUS_ACTIVE);
    expect($price->createdBy->id)->toBe($accountant->id);
    expect($price->currency)->toBe(config('billing.currency'));
    expect(ServicePrice::query()->where('hospital_id', $otherHospital->id)->exists())->toBeFalse();
});

test('a client-supplied cancelled_by is never mass-assignable through the cancel-price request', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();
    $price = priceFor($service, $hospital);
    $accountant = userWithRole('accountant');
    $otherUser = userWithRole('accountant');

    $this->actingAs($accountant)->post(route('billing-pricing.cancel', $price), [
        'reason' => 'Correction',
        'cancelled_by' => $otherUser->id,
        'status' => 'active',
    ]);

    $cancelled = $price->fresh();
    expect($cancelled->status)->toBe(ServicePrice::STATUS_CANCELLED);
    expect($cancelled->cancelledBy->id)->toBe($accountant->id);
});

test('negative, zero-required, and non-numeric prices are rejected on the store-price request', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();
    $accountant = userWithRole('accountant');

    $this->actingAs($accountant)->post(route('billing-pricing.store'), [
        'service_id' => $service->id, 'hospital_id' => $hospital->id, 'amount' => -50, 'effective_from' => now()->toDateString(),
    ])->assertSessionHasErrors('amount');

    $this->actingAs($accountant)->post(route('billing-pricing.store'), [
        'service_id' => $service->id, 'hospital_id' => $hospital->id, 'amount' => 'abc', 'effective_from' => now()->toDateString(),
    ])->assertSessionHasErrors('amount');

    $this->assertDatabaseCount('service_prices', 0);
});

test('an unauthorized user cannot manage the service catalog or pricing by guessing sequential ids', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();
    $price = priceFor($service, $hospital);
    seedRolesAndPermissions();
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('billing-services.update', $service), ['name' => 'Hacked'])->assertForbidden();
    $this->actingAs($user)->post(route('billing-pricing.cancel', $price), ['reason' => 'x'])->assertForbidden();
});

test('reasons and descriptions containing markup are stored as plain text, never interpreted as HTML server-side', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();
    $price = priceFor($service, $hospital);
    $accountant = userWithRole('accountant');
    $payload = '<script>alert(1)</script>';

    $this->actingAs($accountant)->post(route('billing-pricing.cancel', $price), ['reason' => $payload]);

    $this->assertDatabaseHas('service_prices', ['id' => $price->id, 'cancellation_reason' => $payload]);
});
