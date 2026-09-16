<?php

use App\Models\Charge;
use App\Models\Consultation;
use App\Models\PatientAccount;
use App\Services\ChargeService;

/**
 * The realistic initial workflow (Phase 7, Step 52, spec §47): Patient ->
 * Clinical Encounter -> Doctor Consultation -> Billable Service -> Charge ->
 * Patient Account, then traced back: Charge -> Source -> Encounter ->
 * Patient -> Facility. This step's own foundation only (no automated charge
 * capture — the charge is recorded exactly the way Step 54's future
 * integration would call ChargeService::create() directly, with real
 * source_type/source_id, never through the manual HTTP form).
 */
test('a charge sourced from a consultation traces back through the encounter to the patient and facility', function () {
    $ctx = diagnosisReadyEncounter();
    $consultation = Consultation::query()->where('clinical_encounter_id', $ctx['encounter']->id)->sole();
    $actor = userWithRole('accountant');

    $charge = app(ChargeService::class)->create([
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
        'encounter_id' => $ctx['encounter']->id,
        'source_type' => 'consultation',
        'source_id' => $consultation->id,
        'description' => 'Doctor Consultation',
        'unit_price' => 15000,
    ], requestAs($actor));

    // Charge -> source.
    expect($charge->source_type)->toBe('consultation');
    expect($charge->source_id)->toBe($consultation->id);

    // Charge -> encounter -> patient -> facility (department -> hospital).
    $charge->load(['encounter.department', 'patient']);
    expect($charge->encounter->id)->toBe($ctx['encounter']->id);
    expect($charge->patient->id)->toBe($ctx['patient']->id);
    expect($charge->encounter->department->hospital_id)->toBe($ctx['hospital']->id);
    expect($charge->hospital_id)->toBe($ctx['hospital']->id);

    // Charge -> account -> patient.
    $account = PatientAccount::query()->whereKey($charge->patient_account_id)->sole();
    expect($account->patient_id)->toBe($ctx['patient']->id);
    expect($account->hospital_id)->toBe($ctx['hospital']->id);
});

test('recording, posting, and viewing a charge works end to end over HTTP and updates the account balance', function () {
    $ctx = diagnosisReadyEncounter();
    $accountant = userWithRole('accountant');
    $service = serviceFor(['name' => 'Doctor Consultation']);
    priceFor($service, $ctx['hospital'], ['amount' => 15000]);

    $this->actingAs($accountant)->post(route('billing.charges.store'), [
        'hospital_id' => $ctx['hospital']->id,
        'patient_id' => $ctx['patient']->id,
        'service_id' => $service->id,
        'notes' => 'Walk-in',
    ])->assertRedirect();

    $charge = Charge::query()->where('patient_id', $ctx['patient']->id)->sole();
    expect($charge->status)->toBe(Charge::STATUS_PENDING);

    $this->actingAs($accountant)->get(route('billing.accounts.show', $charge->patient_account_id))
        ->assertInertia(fn ($page) => $page
            ->where('balance', 0)
            ->where('pendingTotal', 15000)
            ->has('charges', 1));

    $this->actingAs($accountant)->post(route('billing.charges.post', $charge))->assertRedirect();

    $this->actingAs($accountant)->get(route('billing.accounts.show', $charge->patient_account_id))
        ->assertInertia(fn ($page) => $page
            ->where('balance', 15000)
            ->where('pendingTotal', 0));

    $this->assertDatabaseHas('audit_logs', ['action' => 'charge_created']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'charge_posted']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'billing_account_created']);
});
