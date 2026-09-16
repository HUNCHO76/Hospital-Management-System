<?php

use App\Models\PrescriptionOrder;
use App\Models\Staff;
use App\Models\User;

test('a guest is redirected to login for the pharmacy dashboard and prescriptions worklist', function () {
    $this->get(route('pharmacy.index'))->assertRedirect(route('login'));
    $this->get(route('pharmacy.prescriptions.index'))->assertRedirect(route('login'));
});

test('a permission-less user cannot view the pharmacy dashboard or prescriptions worklist', function () {
    seedRolesAndPermissions();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('pharmacy.index'))->assertForbidden();
    $this->actingAs($user)->get(route('pharmacy.prescriptions.index'))->assertForbidden();
});

test('a doctor holds no pharmacy permission and cannot view the pharmacy module', function () {
    $doctor = userWithRole('doctor');

    $this->actingAs($doctor)->get(route('pharmacy.index'))->assertForbidden();
    $this->actingAs($doctor)->get(route('pharmacy.prescriptions.index'))->assertForbidden();
});

test('a pharmacist can view the pharmacy dashboard and prescriptions worklist', function () {
    $pharmacist = userWithRole('pharmacist');

    $this->actingAs($pharmacist)->get(route('pharmacy.index'))->assertOk();
    $this->actingAs($pharmacist)->get(route('pharmacy.prescriptions.index'))->assertOk();
});

test('a hospital-admin can view the pharmacy module', function () {
    $admin = userWithRole('hospital-admin');

    $this->actingAs($admin)->get(route('pharmacy.index'))->assertOk();
    $this->actingAs($admin)->get(route('pharmacy.prescriptions.index'))->assertOk();
});

test('a super-admin bypasses pharmacy permission checks', function () {
    $user = userWithRole('super-admin');

    $this->actingAs($user)->get(route('pharmacy.index'))->assertOk();
    $this->actingAs($user)->get(route('pharmacy.prescriptions.index'))->assertOk();
});

test('a hospital-admin only sees prescriptions from their own hospital', function () {
    $orderA = activePrescriptionOrderFor();
    $hospitalA = $orderA->clinicalEncounter->department->hospital_id;

    $orderB = activePrescriptionOrderFor();

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA]);

    $this->actingAs($adminA)->get(route('pharmacy.prescriptions.index'))
        ->assertInertia(fn ($page) => $page
            ->has('prescriptions.data', 1)
            ->where('prescriptions.data.0.id', $orderA->id));

    // The other hospital's prescription still exists — it just never
    // appears in this hospital-admin's own scoped worklist (IDOR-style
    // isolation at the list level).
    expect(PrescriptionOrder::query()->whereKey($orderB->id)->exists())->toBeTrue();
});
