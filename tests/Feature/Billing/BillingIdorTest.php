<?php

use App\Models\Charge;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\Staff;
use App\Models\User;

/**
 * IDOR protection for the billing surface (Phase 7, Step 52, spec §31/§42):
 * changing the account/charge id in the URL must never expose or allow
 * acting on another hospital's financial records for a hospital-admin — the
 * one role this application actually fences by hospital (mirrors
 * StockIdorTest's own precedent exactly).
 */
test('a hospital-admin cannot view or act on another hospital\'s account or charges by changing the id in the URL', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $chargeA = chargeFor($hospitalA, Patient::factory()->create());
    $chargeB = chargeFor($hospitalB, Patient::factory()->create());

    seedRolesAndPermissions();
    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->get(route('billing.accounts.show', $chargeA->patient_account_id))->assertOk();
    $this->actingAs($adminA)->get(route('billing.accounts.show', $chargeB->patient_account_id))->assertForbidden();

    $this->actingAs($adminA)->post(route('billing.charges.post', $chargeB))->assertForbidden();
    $this->actingAs($adminA)->post(route('billing.charges.void', $chargeB), ['reason' => 'x'])->assertForbidden();

    expect($chargeB->fresh()->status)->toBe(Charge::STATUS_PENDING);
});

test('a hospital-admin cannot record a charge into another hospital by submitting its id directly', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $patient = Patient::factory()->create();

    seedRolesAndPermissions();
    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);
    $adminA->givePermissionTo('billing-charges.create');
    $service = serviceFor();
    priceFor($service, $hospitalB);

    $this->actingAs($adminA)->post(route('billing.charges.store'), [
        'hospital_id' => $hospitalB->id,
        'patient_id' => $patient->id,
        'service_id' => $service->id,
        'notes' => 'x',
    ])->assertForbidden();

    expect(PatientAccount::query()->where('hospital_id', $hospitalB->id)->exists())->toBeFalse();
    expect(Charge::query()->where('hospital_id', $hospitalB->id)->exists())->toBeFalse();
});

test('the patient account list never leaks another hospital\'s accounts to a hospital-admin', function () {
    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $chargeA = chargeFor($hospitalA, Patient::factory()->create());
    chargeFor($hospitalB, Patient::factory()->create());

    seedRolesAndPermissions();
    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->get(route('billing.accounts.index'))->assertInertia(fn ($page) => $page
        ->has('accounts.data', 1)
        ->where('accounts.data.0.id', $chargeA->patient_account_id));
});

test('an accountant (not hospital-admin) is unrestricted across hospitals, matching this app\'s existing systemic precedent', function () {
    $hospital = Hospital::factory()->create();
    $charge = chargeFor($hospital, Patient::factory()->create());
    $accountant = userWithRole('accountant');

    // Not a gap introduced by Step 52 — mirrors StockItemPolicy/
    // PrescriptionOrderPolicy's own documented precedent that only
    // hospital-admin is hospital-scoped by policy.
    $this->actingAs($accountant)->get(route('billing.accounts.show', $charge->patient_account_id))->assertOk();
});
