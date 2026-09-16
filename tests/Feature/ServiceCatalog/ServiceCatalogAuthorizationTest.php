<?php

use App\Models\Hospital;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\User;

/**
 * Permission gates for the service catalog + pricing module (Phase 7, Step
 * 53, spec §42-43): clinical/pharmacy roles never get pricing permissions.
 */
test('a guest is redirected to login for every service-catalog and pricing route', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();
    $price = priceFor($service, $hospital);

    $this->get(route('billing-services.index'))->assertRedirect(route('login'));
    $this->get(route('billing-services.show', $service))->assertRedirect(route('login'));
    $this->get(route('billing-services.create'))->assertRedirect(route('login'));
    $this->post(route('billing-services.store'))->assertRedirect(route('login'));
    $this->post(route('billing-services.activate', $service))->assertRedirect(route('login'));
    $this->get(route('billing-pricing.index'))->assertRedirect(route('login'));
    $this->get(route('billing-pricing.create'))->assertRedirect(route('login'));
    $this->post(route('billing-pricing.store'))->assertRedirect(route('login'));
    $this->post(route('billing-pricing.cancel', $price))->assertRedirect(route('login'));
});

test('a permission-less user cannot view or act on the service catalog or pricing', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();
    $price = priceFor($service, $hospital);
    seedRolesAndPermissions();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('billing-services.index'))->assertForbidden();
    $this->actingAs($user)->get(route('billing-services.show', $service))->assertForbidden();
    $this->actingAs($user)->post(route('billing-services.store'), [
        'code' => 'PERMLESS-TEST', 'name' => 'x', 'category' => 'consultation',
    ])->assertForbidden();
    $this->actingAs($user)->get(route('billing-pricing.index'))->assertForbidden();
    // A fully valid payload, so this actually exercises the authorization
    // check rather than being rejected by validation first.
    $this->actingAs($user)->post(route('billing-pricing.cancel', $price), ['reason' => 'x'])->assertForbidden();
});

test('a doctor and a pharmacist hold no pricing-management permission', function () {
    $doctor = userWithRole('doctor');
    $pharmacist = userWithRole('pharmacist');
    $hospital = Hospital::factory()->create();
    $service = serviceFor();

    $this->actingAs($doctor)->get(route('billing-pricing.create'))->assertForbidden();
    $this->actingAs($doctor)->post(route('billing-pricing.store'), ['service_id' => $service->id, 'hospital_id' => $hospital->id, 'amount' => 100, 'effective_from' => now()->toDateString()])->assertForbidden();

    $this->actingAs($pharmacist)->get(route('billing-pricing.create'))->assertForbidden();
    $this->actingAs($pharmacist)->get(route('billing-services.create'))->assertForbidden();
});

test('an accountant can manage the service catalog and pricing end to end', function () {
    $hospital = Hospital::factory()->create();
    $accountant = userWithRole('accountant');

    $this->actingAs($accountant)->get(route('billing-services.index'))->assertOk();
    $this->actingAs($accountant)->post(route('billing-services.store'), [
        'code' => 'TEST-SVC', 'name' => 'Test Service', 'category' => 'consultation',
    ])->assertRedirect();

    $service = Service::query()->where('code', 'TEST-SVC')->sole();

    $this->actingAs($accountant)->post(route('billing-pricing.store'), [
        'service_id' => $service->id, 'hospital_id' => $hospital->id, 'amount' => 15000, 'effective_from' => now()->toDateString(),
    ])->assertRedirect();

    $price = ServicePrice::query()->where('service_id', $service->id)->sole();

    $this->actingAs($accountant)->post(route('billing-pricing.cancel', $price), ['reason' => 'x'])->assertRedirect();
    $this->actingAs($accountant)->post(route('billing-services.deactivate', $service))->assertRedirect();
});

test('a super-admin bypasses service-catalog and pricing permission checks', function () {
    $hospital = Hospital::factory()->create();
    $service = serviceFor();
    $user = userWithRole('super-admin');

    $this->actingAs($user)->get(route('billing-services.index'))->assertOk();
    $this->actingAs($user)->post(route('billing-pricing.store'), [
        'service_id' => $service->id, 'hospital_id' => $hospital->id, 'amount' => 1000, 'effective_from' => now()->toDateString(),
    ])->assertRedirect();
});
