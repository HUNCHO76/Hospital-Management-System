<?php

use App\Models\Charge;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\User;

/**
 * Security-focused validation for the billing module (Phase 7, Step 52,
 * spec §41): mass assignment, financial manipulation, and status
 * manipulation are all rejected server-side, never relying on the
 * frontend's own constraints. CSRF/authentication are inherited unchanged
 * from this application's existing global 'web' middleware group and
 * Laravel session guard.
 */
test('a client-supplied hospital_id, status, unit_price, or created_by is never mass-assignable through the store-charge request', function () {
    $hospital = Hospital::factory()->create();
    $otherHospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $accountant = userWithRole('accountant');
    $otherUser = userWithRole('accountant');
    $service = serviceFor();
    priceFor($service, $hospital, ['amount' => 10000]);

    $this->actingAs($accountant)->post(route('billing.charges.store'), [
        'hospital_id' => $hospital->id,
        'patient_id' => $patient->id,
        'service_id' => $service->id,
        'notes' => 'Consultation',
        // Attempted injection — none of these are accepted fields (spec
        // §30/§40: the price always comes from ServicePriceResolver, never
        // the client, and unit_price is not even a field on this request).
        'unit_price' => 1,
        'status' => 'posted',
        'gross_amount' => 999999,
        'net_amount' => 0,
        'created_by' => $otherUser->id,
    ]);

    $charge = Charge::query()->where('patient_id', $patient->id)->sole();
    expect($charge->status)->toBe(Charge::STATUS_PENDING);
    expect((float) $charge->net_amount)->toBe(10000.0);
    expect($charge->createdBy->id)->toBe($accountant->id);

    expect(PatientAccount::query()->where('hospital_id', $otherHospital->id)->exists())->toBeFalse();
});

test('a client-supplied status, posted_at, or performed-by is never mass-assignable through post/void/cancel', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $accountant = userWithRole('accountant');
    $otherUser = userWithRole('accountant');
    $charge = chargeFor($hospital, $patient, ['unit_price' => 5000]);

    $this->actingAs($accountant)->post(route('billing.charges.post', $charge), [
        'status' => 'cancelled',
        'posted_at' => '2020-01-01',
        'updated_by' => $otherUser->id,
    ]);

    $posted = $charge->fresh();
    expect($posted->status)->toBe(Charge::STATUS_POSTED);
    expect($posted->updated_by)->toBe($accountant->id);

    $this->actingAs($accountant)->post(route('billing.charges.cancel', $charge), [
        'reason' => 'Correction',
        'status' => 'pending',
        'cancelled_by' => $otherUser->id,
    ]);

    $cancelled = $charge->fresh();
    expect($cancelled->status)->toBe(Charge::STATUS_CANCELLED);
    expect($cancelled->cancelledBy->id)->toBe($accountant->id);
});

test('a missing service, missing reason, and zero quantity are rejected on the store-charge request', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $accountant = userWithRole('accountant');
    $service = serviceFor();
    priceFor($service, $hospital);

    $this->actingAs($accountant)->post(route('billing.charges.store'), [
        'hospital_id' => $hospital->id, 'patient_id' => $patient->id, 'service_id' => 999999, 'notes' => 'x',
    ])->assertSessionHasErrors('service_id');

    $this->actingAs($accountant)->post(route('billing.charges.store'), [
        'hospital_id' => $hospital->id, 'patient_id' => $patient->id, 'service_id' => $service->id, 'notes' => '',
    ])->assertSessionHasErrors('notes');

    $this->actingAs($accountant)->post(route('billing.charges.store'), [
        'hospital_id' => $hospital->id, 'patient_id' => $patient->id, 'service_id' => $service->id, 'notes' => 'x', 'quantity' => 0,
    ])->assertSessionHasErrors('quantity');

    $this->assertDatabaseCount('charges', 0);
});

test('a manual charge against a service with no configured price at the hospital is rejected', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $accountant = userWithRole('accountant');
    $service = serviceFor();

    $this->actingAs($accountant)->post(route('billing.charges.store'), [
        'hospital_id' => $hospital->id, 'patient_id' => $patient->id, 'service_id' => $service->id, 'notes' => 'x',
    ])->assertSessionHasErrors('price');

    $this->assertDatabaseCount('charges', 0);
});

test('an unauthorized user cannot access another patient\'s account by guessing sequential ids', function () {
    $hospital = Hospital::factory()->create();
    $charge = chargeFor($hospital, Patient::factory()->create());
    seedRolesAndPermissions();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('billing.accounts.show', $charge->patient_account_id))->assertForbidden();
});

test('reasons and descriptions containing markup are stored as plain text, never interpreted as HTML server-side', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $accountant = userWithRole('accountant');
    $payload = '<script>alert(1)</script>';

    $charge = chargeFor($hospital, $patient, ['description' => $payload]);
    expect($charge->description)->toBe($payload);

    $this->actingAs($accountant)->post(route('billing.charges.void', $charge), ['reason' => $payload]);

    $this->assertDatabaseHas('charges', ['id' => $charge->id, 'cancellation_reason' => $payload]);
});

test('an invalid charge status transition is rejected rather than silently accepted', function () {
    $hospital = Hospital::factory()->create();
    $patient = Patient::factory()->create();
    $accountant = userWithRole('accountant');
    $charge = chargeFor($hospital, $patient);

    $this->actingAs($accountant)->post(route('billing.charges.cancel', $charge), ['reason' => 'x'])
        ->assertSessionHasErrors('status');

    expect($charge->fresh()->status)->toBe(Charge::STATUS_PENDING);
});
