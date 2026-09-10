<?php

use App\Models\InsuranceProvider;
use App\Models\InsuranceScheme;
use App\Models\User;

test('an authorized user can view the providers list', function () {
    $user = userWithRole('super-admin');
    InsuranceProvider::factory()->count(2)->create();

    $this->actingAs($user)
        ->get(route('insurance.providers.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('insurance/providers/index'));
});

test('an authorized user can create a provider', function () {
    $user = userWithRole('super-admin');

    $response = $this->actingAs($user)->post(route('insurance.providers.store'), [
        'name' => 'National Health Fund',
        'code' => 'NHIF',
        'registration_number' => 'REG-100',
        'type' => 'government',
        'phone' => '+255700000000',
        'email' => 'info@nhif.test',
        'status' => 'active',
    ]);

    $response->assertRedirect(route('insurance.providers.index'));
    $response->assertSessionHas('success');
    $this->assertDatabaseHas('insurance_providers', ['code' => 'NHIF', 'name' => 'National Health Fund']);
});

test('an unauthorized user cannot create a provider', function () {
    $user = userWithRole('doctor');

    $response = $this->actingAs($user)->post(route('insurance.providers.store'), [
        'name' => 'Should Not Exist',
        'code' => 'NOPE',
        'type' => 'private',
        'status' => 'active',
    ]);

    $response->assertForbidden();
    $this->assertDatabaseMissing('insurance_providers', ['code' => 'NOPE']);
});

test('a duplicate provider code is rejected', function () {
    $user = userWithRole('super-admin');
    InsuranceProvider::factory()->create(['code' => 'DUPE']);

    $response = $this->actingAs($user)->post(route('insurance.providers.store'), [
        'name' => 'Another Provider',
        'code' => 'DUPE',
        'type' => 'private',
        'status' => 'active',
    ]);

    $response->assertSessionHasErrors('code');
});

test('an authorized user can update a provider', function () {
    $user = userWithRole('super-admin');
    $provider = InsuranceProvider::factory()->create(['name' => 'Old Name']);

    $response = $this->actingAs($user)->put(route('insurance.providers.update', $provider), [
        'name' => 'New Name',
        'code' => $provider->code,
        'type' => $provider->type,
        'status' => 'active',
    ]);

    $response->assertRedirect(route('insurance.providers.index'));
    expect($provider->fresh()->name)->toBe('New Name');
});

test('an authorized user can deactivate a provider', function () {
    $user = userWithRole('super-admin');
    $provider = InsuranceProvider::factory()->create();

    $response = $this->actingAs($user)->delete(route('insurance.providers.destroy', $provider));

    $response->assertRedirect(route('insurance.providers.index'));
    $this->assertSoftDeleted($provider);
});

test('a provider with schemes cannot be deleted', function () {
    $user = userWithRole('super-admin');
    $provider = InsuranceProvider::factory()->create();
    InsuranceScheme::factory()->create(['insurance_provider_id' => $provider->id]);

    $this->actingAs($user)->delete(route('insurance.providers.destroy', $provider));

    $this->assertDatabaseHas('insurance_providers', ['id' => $provider->id, 'deleted_at' => null]);
});

test('providers can be searched by name and code', function () {
    $user = userWithRole('super-admin');
    InsuranceProvider::factory()->create(['name' => 'Findable Provider', 'code' => 'FIND']);
    InsuranceProvider::factory()->create(['name' => 'Other Provider', 'code' => 'OTHR']);

    $response = $this->actingAs($user)->get(route('insurance.providers.index', ['search' => 'Findable']));

    $response->assertInertia(fn ($page) => $page->where('providers.total', 1));
});

test('a guest is redirected to login for provider routes', function () {
    $this->get(route('insurance.providers.index'))->assertRedirect(route('login'));
});

test('a user without insurance-providers.view receives a 403', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('insurance.providers.index'))->assertForbidden();
});
