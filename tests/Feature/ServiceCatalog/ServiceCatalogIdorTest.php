<?php

use App\Models\Hospital;
use App\Models\ServicePrice;
use App\Models\Staff;
use App\Models\User;

/**
 * IDOR protection for pricing (Phase 7, Step 53, spec §44-45): a
 * hospital-admin cannot view or cancel another hospital's price by
 * changing the id in the URL — mirrors StockIdorTest/BillingIdorTest's own
 * precedent exactly. `Service` itself carries no `hospital_id` (a global
 * catalog, like Medication/LaboratoryTest/RadiologyProcedure) so there is
 * no facility-IDOR surface on the service definition itself — only on its
 * per-facility pricing.
 */
test('a hospital-admin cannot view or cancel another hospital\'s price by changing the id in the URL', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $service = serviceFor();

    $priceA = priceFor($service, $hospitalA);
    $priceB = priceFor($service, $hospitalB);

    seedRolesAndPermissions();
    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->post(route('billing-pricing.cancel', $priceB), ['reason' => 'x'])->assertForbidden();
    expect($priceB->fresh()->status)->toBe(ServicePrice::STATUS_ACTIVE);

    // priceA belongs to their own hospital — legitimately reachable.
    $this->actingAs($adminA)->post(route('billing-pricing.cancel', $priceA), ['reason' => 'x'])->assertRedirect();
    expect($priceA->fresh()->status)->toBe(ServicePrice::STATUS_CANCELLED);
});

test('a hospital-admin cannot create a price into another hospital by submitting its id directly', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $service = serviceFor();

    seedRolesAndPermissions();
    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);
    $adminA->givePermissionTo('billing-pricing.create');

    $this->actingAs($adminA)->post(route('billing-pricing.store'), [
        'service_id' => $service->id,
        'hospital_id' => $hospitalB->id,
        'amount' => 10000,
        'effective_from' => now()->toDateString(),
    ])->assertForbidden();

    expect(ServicePrice::query()->where('hospital_id', $hospitalB->id)->exists())->toBeFalse();
});

test('the pricing list never leaks another hospital\'s prices to a hospital-admin', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $service = serviceFor();

    $priceA = priceFor($service, $hospitalA);
    priceFor(serviceFor(), $hospitalB);

    seedRolesAndPermissions();
    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->get(route('billing-pricing.index'))->assertInertia(fn ($page) => $page
        ->has('prices.data', 1)
        ->where('prices.data.0.id', $priceA->id));
});
