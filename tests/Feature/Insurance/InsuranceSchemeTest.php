<?php

use App\Models\InsuranceProvider;
use App\Models\InsuranceScheme;
use App\Models\PatientInsurance;
use App\Models\User;

test('an authorized user can view the schemes list', function () {
    $user = userWithRole('super-admin');
    InsuranceScheme::factory()->count(2)->create();

    $this->actingAs($user)
        ->get(route('insurance.schemes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('insurance/schemes/index'));
});

test('an authorized user can create a scheme belonging to a provider', function () {
    $user = userWithRole('super-admin');
    $provider = InsuranceProvider::factory()->create();

    $response = $this->actingAs($user)->post(route('insurance.schemes.store'), [
        'insurance_provider_id' => $provider->id,
        'name' => 'Standard Scheme',
        'code' => 'STD',
        'status' => 'active',
    ]);

    $response->assertRedirect(route('insurance.schemes.index'));

    $scheme = InsuranceScheme::query()->where('code', 'STD')->first();
    expect($scheme)->not->toBeNull()
        ->and($scheme->insurance_provider_id)->toBe($provider->id);
});

test('an unauthorized user cannot create a scheme', function () {
    $user = userWithRole('doctor');
    $provider = InsuranceProvider::factory()->create();

    $response = $this->actingAs($user)->post(route('insurance.schemes.store'), [
        'insurance_provider_id' => $provider->id,
        'name' => 'Should Not Exist',
        'code' => 'NOPE',
        'status' => 'active',
    ]);

    $response->assertForbidden();
    $this->assertDatabaseMissing('insurance_schemes', ['code' => 'NOPE']);
});

test('an invalid provider is rejected when creating a scheme', function () {
    $user = userWithRole('super-admin');

    $response = $this->actingAs($user)->post(route('insurance.schemes.store'), [
        'insurance_provider_id' => 999999,
        'name' => 'Ghost Scheme',
        'code' => 'GHOST',
        'status' => 'active',
    ]);

    $response->assertSessionHasErrors('insurance_provider_id');
});

test('duplicate scheme code within the same provider is rejected', function () {
    $user = userWithRole('super-admin');
    $provider = InsuranceProvider::factory()->create();
    InsuranceScheme::factory()->create(['insurance_provider_id' => $provider->id, 'code' => 'STD']);

    $response = $this->actingAs($user)->post(route('insurance.schemes.store'), [
        'insurance_provider_id' => $provider->id,
        'name' => 'Duplicate Standard',
        'code' => 'STD',
        'status' => 'active',
    ]);

    $response->assertSessionHasErrors('code');
});

test('the same scheme code is allowed under a different provider', function () {
    $user = userWithRole('super-admin');
    $providerA = InsuranceProvider::factory()->create();
    $providerB = InsuranceProvider::factory()->create();
    InsuranceScheme::factory()->create(['insurance_provider_id' => $providerA->id, 'code' => 'STD']);

    $response = $this->actingAs($user)->post(route('insurance.schemes.store'), [
        'insurance_provider_id' => $providerB->id,
        'name' => 'Standard',
        'code' => 'STD',
        'status' => 'active',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertDatabaseHas('insurance_schemes', ['insurance_provider_id' => $providerB->id, 'code' => 'STD']);
});

test('an authorized user can update a scheme', function () {
    $user = userWithRole('super-admin');
    $scheme = InsuranceScheme::factory()->create(['name' => 'Old Name']);

    $response = $this->actingAs($user)->put(route('insurance.schemes.update', $scheme), [
        'insurance_provider_id' => $scheme->insurance_provider_id,
        'name' => 'New Name',
        'code' => $scheme->code,
        'status' => 'active',
    ]);

    $response->assertRedirect(route('insurance.schemes.index'));
    expect($scheme->fresh()->name)->toBe('New Name');
});

test('an authorized user can deactivate a scheme', function () {
    $user = userWithRole('super-admin');
    $scheme = InsuranceScheme::factory()->create();

    $response = $this->actingAs($user)->delete(route('insurance.schemes.destroy', $scheme));

    $response->assertRedirect(route('insurance.schemes.index'));
    $this->assertSoftDeleted($scheme);
});

test('a scheme with patient insurance records cannot be deleted', function () {
    $user = userWithRole('super-admin');
    $scheme = InsuranceScheme::factory()->create();
    PatientInsurance::factory()->create([
        'insurance_provider_id' => $scheme->insurance_provider_id,
        'insurance_scheme_id' => $scheme->id,
    ]);

    $this->actingAs($user)->delete(route('insurance.schemes.destroy', $scheme));

    $this->assertDatabaseHas('insurance_schemes', ['id' => $scheme->id, 'deleted_at' => null]);
});

test('schemes can be filtered by provider', function () {
    $user = userWithRole('super-admin');
    $providerA = InsuranceProvider::factory()->create();
    $providerB = InsuranceProvider::factory()->create();
    InsuranceScheme::factory()->create(['insurance_provider_id' => $providerA->id]);
    InsuranceScheme::factory()->create(['insurance_provider_id' => $providerB->id]);

    $response = $this->actingAs($user)->get(route('insurance.schemes.index', ['insurance_provider_id' => $providerA->id]));

    $response->assertInertia(fn ($page) => $page->where('schemes.total', 1));
});

test('a guest is redirected to login for scheme routes', function () {
    $this->get(route('insurance.schemes.index'))->assertRedirect(route('login'));
});

test('a user without insurance-schemes.view receives a 403', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('insurance.schemes.index'))->assertForbidden();
});
