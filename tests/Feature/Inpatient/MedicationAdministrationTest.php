<?php

use App\Models\Admission;
use App\Models\ClinicalEncounter;
use App\Models\Medication;
use App\Models\MedicationAdministration;
use App\Models\PrescriptionOrder;
use App\Models\PrescriptionOrderItem;
use App\Services\AdmissionService;
use App\Services\InpatientClinicalService;
use App\Services\MedicationAdministrationService;
use App\Services\PrescriptionOrderService;
use Illuminate\Validation\ValidationException;

// ---------------------------------------------------------------------------
// Shared helper — builds an active admission with an inpatient encounter
// and a prescription order item, all linked together.
// ---------------------------------------------------------------------------

function marContext(): array
{
    $ctx = activeAdmission();
    $admission = $ctx['admission'];
    $practitioner = $ctx['practitioner'];
    $user = $ctx['user'];

    $encounter = app(InpatientClinicalService::class)->openEncounter(
        $admission,
        $practitioner->id,
        requestAs($user),
    );

    // Build the prescription chain directly via factories — the service path
    // would require a full pharmacy verification/dispensing stack; we only
    // need valid FK rows for the MAR tests.
    $order = PrescriptionOrder::factory()->create([
        'clinical_encounter_id' => $encounter->id,
        'prescriber_id' => $user->id,
    ]);

    $item = PrescriptionOrderItem::factory()->create([
        'prescription_order_id' => $order->id,
        'route' => 'oral',
        'dose' => '500',
        'dose_unit' => 'mg',
    ]);

    return array_merge($ctx, compact('encounter', 'order', 'item'));
}

function baseAdminData(int $itemId, int $encounterId): array
{
    return [
        'prescription_order_item_id' => $itemId,
        'clinical_encounter_id' => $encounterId,
        'status' => MedicationAdministration::STATUS_ADMINISTERED,
        'administered_at' => now()->toDateTimeString(),
        'dose' => '500',
        'dose_unit' => 'mg',
        'route' => 'oral',
        'is_prn' => false,
    ];
}

// ---------------------------------------------------------------------------
// 1. Administered status
// ---------------------------------------------------------------------------

test('recording a dose as administered creates a MAR row', function () {
    $ctx = marContext();
    $user = $ctx['user'];

    $marBefore = MedicationAdministration::count();

    $mar = app(MedicationAdministrationService::class)->record(
        $ctx['admission'],
        baseAdminData($ctx['item']->id, $ctx['encounter']->id),
        requestAs($user),
    );

    expect(MedicationAdministration::count())->toBe($marBefore + 1);
    expect($mar->status)->toBe(MedicationAdministration::STATUS_ADMINISTERED);
    expect($mar->admission_id)->toBe($ctx['admission']->id);
    expect($mar->administered_by)->toBe($user->id);
    expect($mar->corrects_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// 2. Hold, refused, missed — each require a reason
// ---------------------------------------------------------------------------

test('a held dose is recorded with a reason', function () {
    $ctx = marContext();

    $mar = app(MedicationAdministrationService::class)->record(
        $ctx['admission'],
        array_merge(baseAdminData($ctx['item']->id, $ctx['encounter']->id), [
            'status' => MedicationAdministration::STATUS_HELD,
            'reason' => 'patient_npo',
        ]),
        requestAs($ctx['user']),
    );

    expect($mar->status)->toBe(MedicationAdministration::STATUS_HELD);
    expect($mar->reason)->toBe('patient_npo');
});

test('a refused dose is recorded with a reason', function () {
    $ctx = marContext();

    $mar = app(MedicationAdministrationService::class)->record(
        $ctx['admission'],
        array_merge(baseAdminData($ctx['item']->id, $ctx['encounter']->id), [
            'status' => MedicationAdministration::STATUS_REFUSED,
            'reason' => 'patient_refused',
        ]),
        requestAs($ctx['user']),
    );

    expect($mar->status)->toBe(MedicationAdministration::STATUS_REFUSED);
});

test('a missed dose is recorded with a reason', function () {
    $ctx = marContext();

    $mar = app(MedicationAdministrationService::class)->record(
        $ctx['admission'],
        array_merge(baseAdminData($ctx['item']->id, $ctx['encounter']->id), [
            'status' => MedicationAdministration::STATUS_MISSED,
            'reason' => 'patient_unavailable',
        ]),
        requestAs($ctx['user']),
    );

    expect($mar->status)->toBe(MedicationAdministration::STATUS_MISSED);
});

// ---------------------------------------------------------------------------
// 3. IDOR — item from a different admission is rejected
// ---------------------------------------------------------------------------

test('a MAR record against a prescription item from a different admission is rejected', function () {
    $ctx = marContext();
    $ctx2 = marContext();   // Second, independent admission

    // Try to record a MAR on ctx['admission'] using an item from ctx2
    app(MedicationAdministrationService::class)->record(
        $ctx['admission'],
        baseAdminData($ctx2['item']->id, $ctx['encounter']->id),
        requestAs($ctx['user']),
    );
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// 4. Discharged admission blocks MAR
// ---------------------------------------------------------------------------

test('MAR recording is blocked for a discharged admission', function () {
    $ctx = marContext();
    $admin = $ctx['admin'];

    // Discharge the admission
    app(AdmissionService::class)->discharge(
        $ctx['admission'],
        ['discharge_type' => 'routine', 'discharge_reason' => 'Recovered'],
        requestAs($admin),
    );

    app(MedicationAdministrationService::class)->record(
        $ctx['admission']->fresh(),
        baseAdminData($ctx['item']->id, $ctx['encounter']->id),
        requestAs($ctx['user']),
    );
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// 5. HTTP — unauthorized user cannot record administration
// ---------------------------------------------------------------------------

test('a user without medication administer permissions cannot post to the MAR store endpoint', function () {
    $ctx = marContext();
    $patient = $ctx['patient'];

    // A plain user with no inpatient medication permissions
    $user = userWithRole('doctor');  // doctor has only medications.view, not administer

    $response = $this->actingAs($user)->post(
        route('inpatient.medications.store', $ctx['admission']),
        baseAdminData($ctx['item']->id, $ctx['encounter']->id),
    );

    $response->assertForbidden();
});

// ---------------------------------------------------------------------------
// 6. Correction creates a new amendment row, leaves the original intact
// ---------------------------------------------------------------------------

test('correcting a MAR record creates a new row with corrects_id, preserving the original', function () {
    $ctx = marContext();
    $user = $ctx['user'];

    $original = app(MedicationAdministrationService::class)->record(
        $ctx['admission'],
        baseAdminData($ctx['item']->id, $ctx['encounter']->id),
        requestAs($user),
    );

    $marBefore = MedicationAdministration::count();

    $correction = app(MedicationAdministrationService::class)->correct(
        $original,
        [
            'status' => MedicationAdministration::STATUS_ADMINISTERED,
            'administered_at' => now()->toDateTimeString(),
            'dose' => '250',
            'dose_unit' => 'mg',
            'route' => 'oral',
            'correction_reason' => 'Dose entered incorrectly — should have been 250 mg, not 500 mg.',
        ],
        requestAs($user),
    );

    expect(MedicationAdministration::count())->toBe($marBefore + 1);
    expect($correction->corrects_id)->toBe($original->id);
    expect($correction->dose)->toBe('250');
    expect($correction->correction_reason)->not->toBeNull();

    // Original row must not have changed
    $originalFresh = $original->fresh();
    expect($originalFresh->dose)->toBe('500');
    expect($originalFresh->corrects_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// 7. A correction row cannot itself be corrected
// ---------------------------------------------------------------------------

test('correcting an already-amended MAR record is rejected', function () {
    $ctx = marContext();
    $user = $ctx['user'];

    $original = app(MedicationAdministrationService::class)->record(
        $ctx['admission'],
        baseAdminData($ctx['item']->id, $ctx['encounter']->id),
        requestAs($user),
    );

    $correction = app(MedicationAdministrationService::class)->correct(
        $original,
        [
            'status' => MedicationAdministration::STATUS_ADMINISTERED,
            'administered_at' => now()->toDateTimeString(),
            'dose' => '250',
            'dose_unit' => 'mg',
            'route' => 'oral',
            'correction_reason' => 'Wrong dose recorded.',
        ],
        requestAs($user),
    );

    // Attempting to correct the correction itself must be rejected
    app(MedicationAdministrationService::class)->correct(
        $correction,
        [
            'status' => MedicationAdministration::STATUS_ADMINISTERED,
            'administered_at' => now()->toDateTimeString(),
            'dose' => '125',
            'dose_unit' => 'mg',
            'route' => 'oral',
            'correction_reason' => 'Trying to correct a correction.',
        ],
        requestAs($user),
    );
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// 8. Regression — OPD prescriptions still require a consultation
// ---------------------------------------------------------------------------

test('Phase 6 regression: creating a prescription order for an OPD encounter without a consultation is rejected', function () {
    $user = userWithRole('doctor');

    $encounter = ClinicalEncounter::factory()->create([
        'encounter_type' => 'opd',
        'status' => ClinicalEncounter::STATUS_IN_PROGRESS,
        'started_at' => now(),
    ]);

    // No consultation created — the service gate must reject
    app(PrescriptionOrderService::class)->create(
        $encounter,
        [
            'items' => [
                [
                    'medication_id' => Medication::factory()->create()->id,
                    'dose' => '500',
                    'dose_unit' => 'mg',
                    'frequency' => PrescriptionOrderItem::FREQUENCIES[0],
                    'route' => PrescriptionOrderItem::ROUTES[0],
                    'duration' => 5,
                    'duration_unit' => 'days',
                    'quantity' => 10,
                    'quantity_unit' => 'tablets',
                ],
            ],
        ],
        requestAs($user),
    );
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// 9. Inpatient prescription does NOT require a consultation (the bug fix)
// ---------------------------------------------------------------------------

test('a prescription order can be written for an inpatient encounter without a consultation', function () {
    $ctx = marContext();
    $user = $ctx['user'];

    $ordersBefore = PrescriptionOrder::count();

    // Create a new inpatient encounter (the one in $ctx already has an order)
    $encounter2 = app(InpatientClinicalService::class)->openEncounter(
        $ctx['admission'],
        $ctx['practitioner']->id,
        requestAs($user),
    );

    $medication = Medication::factory()->create(['status' => 'active']);

    // This must NOT throw even though there is no consultation
    $order = app(PrescriptionOrderService::class)->create(
        $encounter2,
        [
            'items' => [
                [
                    'medication_id' => $medication->id,
                    'dose' => '500',
                    'dose_unit' => 'mg',
                    'frequency' => PrescriptionOrderItem::FREQUENCIES[0],
                    'route' => PrescriptionOrderItem::ROUTES[0],
                    'duration' => 5,
                    'duration_unit' => 'days',
                    'quantity' => 10,
                    'quantity_unit' => 'tablets',
                ],
            ],
        ],
        requestAs($user),
    );

    expect(PrescriptionOrder::count())->toBeGreaterThan($ordersBefore);
    expect($order->clinical_encounter_id)->toBe($encounter2->id);
});
