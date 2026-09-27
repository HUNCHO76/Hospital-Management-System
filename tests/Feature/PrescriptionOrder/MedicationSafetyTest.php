<?php

use App\Models\DiagnosisMedicationRule;
use App\Models\EncounterDiagnosis;
use App\Models\PrescriptionOrder;
use App\Services\DiagnosisService;
use App\Services\MedicationSafetyService;
use App\Services\PrescriptionOrderService;
use Illuminate\Validation\ValidationException;

// ─── MedicationSafetyService unit-level tests ────────────────────────────────

test('no errors are returned when no diagnosis codes exist for the encounter', function () {
    $ctx = diagnosisReadyEncounter();
    $medication = activeMedication();

    // No diagnoses on this encounter — safety check must pass regardless of rules.
    $rule = DiagnosisMedicationRule::factory()->create([
        'medication_id' => $medication->id,
        'rule_type' => DiagnosisMedicationRule::RULE_TYPE_CONTRAINDICATION,
        'severity' => DiagnosisMedicationRule::SEVERITY_HIGH,
        'description' => 'Dangerous combination',
        'is_active' => true,
    ]);

    $service = app(MedicationSafetyService::class);
    $errors = $service->checkItems([], [
        ['medication_id' => $medication->id, 'safety_override_justification' => ''],
    ]);

    expect($errors)->toBeEmpty();
});

test('no errors are returned when no rules match the active diagnosis codes', function () {
    $ctx = diagnosisReadyEncounter();
    $diagCode = activeDiagnosisCode();
    $medication = activeMedication();
    $unrelatedMedication = activeMedication();

    DiagnosisMedicationRule::factory()->create([
        'diagnosis_code_id' => $diagCode->id,
        'medication_id' => $unrelatedMedication->id,
        'rule_type' => DiagnosisMedicationRule::RULE_TYPE_CONTRAINDICATION,
        'severity' => DiagnosisMedicationRule::SEVERITY_HIGH,
        'description' => 'Unrelated rule',
        'is_active' => true,
    ]);

    $service = app(MedicationSafetyService::class);
    $errors = $service->checkItems([$diagCode->id], [
        ['medication_id' => $medication->id, 'safety_override_justification' => ''],
    ]);

    expect($errors)->toBeEmpty();
});

test('inactive rules are ignored', function () {
    $ctx = diagnosisReadyEncounter();
    $diagCode = activeDiagnosisCode();
    $medication = activeMedication();

    DiagnosisMedicationRule::factory()->create([
        'diagnosis_code_id' => $diagCode->id,
        'medication_id' => $medication->id,
        'rule_type' => DiagnosisMedicationRule::RULE_TYPE_CONTRAINDICATION,
        'severity' => DiagnosisMedicationRule::SEVERITY_HIGH,
        'description' => 'Inactive rule',
        'is_active' => false,
    ]);

    $service = app(MedicationSafetyService::class);
    $errors = $service->checkItems([$diagCode->id], [
        ['medication_id' => $medication->id, 'safety_override_justification' => ''],
    ]);

    expect($errors)->toBeEmpty();
});

test('a matching rule without justification produces a validation error for that item', function () {
    $diagCode = activeDiagnosisCode(['code' => 'J45', 'description' => 'Asthma']);
    $medication = activeMedication(['generic_name' => 'Propranolol']);

    DiagnosisMedicationRule::factory()->create([
        'diagnosis_code_id' => $diagCode->id,
        'medication_id' => $medication->id,
        'rule_type' => DiagnosisMedicationRule::RULE_TYPE_CONTRAINDICATION,
        'severity' => DiagnosisMedicationRule::SEVERITY_HIGH,
        'description' => 'Beta-blockers are contraindicated in asthma',
        'is_active' => true,
    ]);

    $service = app(MedicationSafetyService::class);
    $errors = $service->checkItems([$diagCode->id], [
        ['medication_id' => $medication->id, 'safety_override_justification' => ''],
    ]);

    expect($errors)->toHaveKey('items.0.safety_override_justification');
    expect($errors['items.0.safety_override_justification'])->toContain('Contraindication');
    expect($errors['items.0.safety_override_justification'])->toContain('J45');
});

test('a matching rule with a non-empty justification passes', function () {
    $diagCode = activeDiagnosisCode(['code' => 'J45']);
    $medication = activeMedication();

    DiagnosisMedicationRule::factory()->create([
        'diagnosis_code_id' => $diagCode->id,
        'medication_id' => $medication->id,
        'rule_type' => DiagnosisMedicationRule::RULE_TYPE_CONTRAINDICATION,
        'severity' => DiagnosisMedicationRule::SEVERITY_HIGH,
        'description' => 'Contraindicated',
        'is_active' => true,
    ]);

    $service = app(MedicationSafetyService::class);
    $errors = $service->checkItems([$diagCode->id], [
        ['medication_id' => $medication->id, 'safety_override_justification' => 'Risk outweighed by benefit in this case'],
    ]);

    expect($errors)->toBeEmpty();
});

test('only the flagged item gets an error when a multi-item prescription is submitted', function () {
    $diagCode = activeDiagnosisCode(['code' => 'J45']);
    $flaggedMedication = activeMedication();
    $safeMedication = activeMedication();

    DiagnosisMedicationRule::factory()->create([
        'diagnosis_code_id' => $diagCode->id,
        'medication_id' => $flaggedMedication->id,
        'rule_type' => DiagnosisMedicationRule::RULE_TYPE_CONTRAINDICATION,
        'severity' => DiagnosisMedicationRule::SEVERITY_MEDIUM,
        'description' => 'Contraindicated',
        'is_active' => true,
    ]);

    $service = app(MedicationSafetyService::class);
    $errors = $service->checkItems([$diagCode->id], [
        ['medication_id' => $safeMedication->id, 'safety_override_justification' => ''],
        ['medication_id' => $flaggedMedication->id, 'safety_override_justification' => ''],
    ]);

    expect($errors)->toHaveKey('items.1.safety_override_justification');
    expect($errors)->not->toHaveKey('items.0.safety_override_justification');
});

test('the most severe matching rule is chosen for the error message when multiple rules match', function () {
    $diagCodeA = activeDiagnosisCode(['code' => 'J45']);
    $diagCodeB = activeDiagnosisCode(['code' => 'I10']);
    $medication = activeMedication();

    DiagnosisMedicationRule::factory()->create([
        'diagnosis_code_id' => $diagCodeA->id,
        'medication_id' => $medication->id,
        'rule_type' => DiagnosisMedicationRule::RULE_TYPE_CAUTION,
        'severity' => DiagnosisMedicationRule::SEVERITY_LOW,
        'description' => 'Low-severity caution',
        'is_active' => true,
    ]);

    DiagnosisMedicationRule::factory()->create([
        'diagnosis_code_id' => $diagCodeB->id,
        'medication_id' => $medication->id,
        'rule_type' => DiagnosisMedicationRule::RULE_TYPE_CONTRAINDICATION,
        'severity' => DiagnosisMedicationRule::SEVERITY_HIGH,
        'description' => 'High-severity contraindication',
        'is_active' => true,
    ]);

    $service = app(MedicationSafetyService::class);
    $errors = $service->checkItems([$diagCodeA->id, $diagCodeB->id], [
        ['medication_id' => $medication->id, 'safety_override_justification' => ''],
    ]);

    expect($errors)->toHaveKey('items.0.safety_override_justification');
    expect($errors['items.0.safety_override_justification'])->toContain('Contraindication');
});

// ─── Integration via PrescriptionOrderService ────────────────────────────────

test('creating a prescription without justification is rejected when a safety rule matches', function () {
    $ctx = diagnosisReadyEncounter();
    $diagCode = activeDiagnosisCode(['code' => 'J45']);
    $medication = activeMedication();

    // Give the encounter an active diagnosis matching the rule.
    app(DiagnosisService::class)->create($ctx['encounter'], [
        'diagnosis_code_id' => $diagCode->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
    ], requestAs($ctx['doctor']['user']));

    DiagnosisMedicationRule::factory()->create([
        'diagnosis_code_id' => $diagCode->id,
        'medication_id' => $medication->id,
        'rule_type' => DiagnosisMedicationRule::RULE_TYPE_CONTRAINDICATION,
        'severity' => DiagnosisMedicationRule::SEVERITY_HIGH,
        'description' => 'Contraindicated in asthma',
        'is_active' => true,
    ]);

    expect(fn () => app(PrescriptionOrderService::class)->create($ctx['encounter'], [
        'items' => [
            ['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5],
        ],
    ], requestAs($ctx['doctor']['user'])))
        ->toThrow(ValidationException::class);
});

test('creating a prescription with justification succeeds and stores the justification on the item', function () {
    $ctx = diagnosisReadyEncounter();
    $diagCode = activeDiagnosisCode(['code' => 'J45']);
    $medication = activeMedication();

    app(DiagnosisService::class)->create($ctx['encounter'], [
        'diagnosis_code_id' => $diagCode->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
    ], requestAs($ctx['doctor']['user']));

    DiagnosisMedicationRule::factory()->create([
        'diagnosis_code_id' => $diagCode->id,
        'medication_id' => $medication->id,
        'rule_type' => DiagnosisMedicationRule::RULE_TYPE_CONTRAINDICATION,
        'severity' => DiagnosisMedicationRule::SEVERITY_HIGH,
        'description' => 'Contraindicated in asthma',
        'is_active' => true,
    ]);

    $order = app(PrescriptionOrderService::class)->create($ctx['encounter'], [
        'items' => [
            [
                'medication_id' => $medication->id,
                'dose' => '1',
                'frequency' => 'once_daily',
                'route' => 'oral',
                'quantity' => 5,
                'safety_override_justification' => 'Benefits outweigh risks; patient monitored closely',
            ],
        ],
    ], requestAs($ctx['doctor']['user']));

    expect($order->status)->toBe(PrescriptionOrder::STATUS_ACTIVE);

    $item = $order->items->first();
    expect($item)->not->toBeNull();
    expect($item->safety_override_justification)->toBe('Benefits outweigh risks; patient monitored closely');
});

test('a prescription with no matching rule is unaffected even when other rules exist for that diagnosis', function () {
    $ctx = diagnosisReadyEncounter();
    $diagCode = activeDiagnosisCode(['code' => 'J45']);
    $flaggedMedication = activeMedication();
    $safeMedication = activeMedication();

    app(DiagnosisService::class)->create($ctx['encounter'], [
        'diagnosis_code_id' => $diagCode->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
    ], requestAs($ctx['doctor']['user']));

    DiagnosisMedicationRule::factory()->create([
        'diagnosis_code_id' => $diagCode->id,
        'medication_id' => $flaggedMedication->id,
        'rule_type' => DiagnosisMedicationRule::RULE_TYPE_CONTRAINDICATION,
        'severity' => DiagnosisMedicationRule::SEVERITY_HIGH,
        'description' => 'Flagged',
        'is_active' => true,
    ]);

    $order = app(PrescriptionOrderService::class)->create($ctx['encounter'], [
        'items' => [
            ['medication_id' => $safeMedication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5],
        ],
    ], requestAs($ctx['doctor']['user']));

    expect($order->status)->toBe(PrescriptionOrder::STATUS_ACTIVE);
    expect($order->items->first()->safety_override_justification)->toBeNull();
});

test('a secondary diagnosis also triggers a safety check', function () {
    $ctx = diagnosisReadyEncounter();
    $primaryCode = activeDiagnosisCode(['code' => 'J45']);
    $secondaryCode = activeDiagnosisCode(['code' => 'I10']);
    $medication = activeMedication();
    $request = requestAs($ctx['doctor']['user']);

    app(DiagnosisService::class)->create($ctx['encounter'], [
        'diagnosis_code_id' => $primaryCode->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
    ], $request);

    app(DiagnosisService::class)->create($ctx['encounter'], [
        'diagnosis_code_id' => $secondaryCode->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_SECONDARY,
    ], $request);

    // Rule is only on the secondary diagnosis.
    DiagnosisMedicationRule::factory()->create([
        'diagnosis_code_id' => $secondaryCode->id,
        'medication_id' => $medication->id,
        'rule_type' => DiagnosisMedicationRule::RULE_TYPE_CAUTION,
        'severity' => DiagnosisMedicationRule::SEVERITY_MEDIUM,
        'description' => 'Caution with secondary diagnosis',
        'is_active' => true,
    ]);

    expect(fn () => app(PrescriptionOrderService::class)->create($ctx['encounter'], [
        'items' => [
            ['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5],
        ],
    ], $request))
        ->toThrow(ValidationException::class);
});

test('cancelled diagnoses do not trigger a safety check', function () {
    $ctx = diagnosisReadyEncounter();
    $diagCode = activeDiagnosisCode(['code' => 'J45']);
    $medication = activeMedication();
    $request = requestAs($ctx['doctor']['user']);

    $diagnosis = app(DiagnosisService::class)->create($ctx['encounter'], [
        'diagnosis_code_id' => $diagCode->id,
        'diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY,
    ], $request);

    app(DiagnosisService::class)->cancel($diagnosis, 'Incorrect entry', $request);

    DiagnosisMedicationRule::factory()->create([
        'diagnosis_code_id' => $diagCode->id,
        'medication_id' => $medication->id,
        'rule_type' => DiagnosisMedicationRule::RULE_TYPE_CONTRAINDICATION,
        'severity' => DiagnosisMedicationRule::SEVERITY_HIGH,
        'description' => 'Contraindicated',
        'is_active' => true,
    ]);

    // Should succeed — the cancelled diagnosis is no longer active.
    $order = app(PrescriptionOrderService::class)->create($ctx['encounter'], [
        'items' => [
            ['medication_id' => $medication->id, 'dose' => '1', 'frequency' => 'once_daily', 'route' => 'oral', 'quantity' => 5],
        ],
    ], $request);

    expect($order->status)->toBe(PrescriptionOrder::STATUS_ACTIVE);
});
