<?php

use App\Models\Admission;
use App\Models\CarePlan;
use App\Models\CarePlanItem;
use App\Models\ClinicalEncounter;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\Staff;
use App\Models\User;
use App\Services\CarePlanService;
use App\Services\DiagnosisService;
use App\Services\InpatientClinicalService;
use Illuminate\Validation\ValidationException;

// ---------------------------------------------------------------------------
// Helper — admission + inpatient encounter + confirmed diagnosis (care plan
// requires at least one non-cancelled diagnosis)
// ---------------------------------------------------------------------------

/**
 * Build the minimum valid context for inpatient care plan tests.
 *
 * @return array{
 *     hospital: Hospital,
 *     patient: Patient,
 *     admission: Admission,
 *     encounter: ClinicalEncounter,
 *     doctor: array{user: User, staff: Staff, practitioner: Practitioner},
 * }
 */
function inpatientCarePlanContext(): array
{
    $ctx = activeAdmission();

    $doctor = doctorWithPractitioner($ctx['hospital']);

    $encounter = app(InpatientClinicalService::class)->openEncounter(
        $ctx['admission'],
        $ctx['practitioner']->id,
        requestAs($doctor['user']),
    );

    // A confirmed diagnosis is required before a care plan can be created.
    $diagnosis = app(DiagnosisService::class)->create($encounter, [
        'diagnosis_code_id' => activeDiagnosisCode()->id,
        'diagnosis_type' => 'primary',
    ], requestAs($doctor['user']));

    return [
        'hospital' => $ctx['hospital'],
        'patient' => $ctx['patient'],
        'admission' => $ctx['admission'],
        'encounter' => $encounter->fresh(),
        'doctor' => $doctor,
        'diagnosis' => $diagnosis,
    ];
}

// ---------------------------------------------------------------------------
// 1. Care plan creation on inpatient encounter
// ---------------------------------------------------------------------------

test('a care plan can be created for an inpatient encounter once a diagnosis exists', function () {
    $ctx = inpatientCarePlanContext();

    $carePlan = app(CarePlanService::class)->create($ctx['encounter'], [
        'goals' => 'Manage fever and monitor for complications.',
    ], requestAs($ctx['doctor']['user']));

    expect($carePlan->clinical_encounter_id)->toBe($ctx['encounter']->id);
    expect($carePlan->status)->toBe(CarePlan::STATUS_DRAFT);
});

test('creating a care plan without a diagnosis is rejected', function () {
    $ctxBase = activeAdmission();
    $doctor = doctorWithPractitioner($ctxBase['hospital']);
    $encounter = app(InpatientClinicalService::class)->openEncounter(
        $ctxBase['admission'],
        $ctxBase['practitioner']->id,
        requestAs($doctor['user']),
    );

    // No diagnosis added — must throw.
    app(CarePlanService::class)->create($encounter, ['goals' => 'test'], requestAs($doctor['user']));
})->throws(ValidationException::class);

test('creating a second care plan for the same encounter is rejected', function () {
    $ctx = inpatientCarePlanContext();
    $req = requestAs($ctx['doctor']['user']);

    app(CarePlanService::class)->create($ctx['encounter'], ['goals' => 'first'], $req);

    app(CarePlanService::class)->create($ctx['encounter'], ['goals' => 'second'], $req);
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// 2. Treatment item lifecycle — planned → active → completed
// ---------------------------------------------------------------------------

test('treatment items can be added and activated on an inpatient care plan', function () {
    $ctx = inpatientCarePlanContext();
    $req = requestAs($ctx['doctor']['user']);

    $carePlan = app(CarePlanService::class)->create($ctx['encounter'], ['goals' => 'Stabilize patient.'], $req);

    $item = app(CarePlanService::class)->addItem($carePlan, [
        'type' => CarePlanItem::TYPE_CLINICAL_INTERVENTION,
        'description' => 'IV fluid replacement',
        'priority' => 'high',
    ], $req);

    expect($item->status)->toBe(CarePlanItem::STATUS_PLANNED);

    $activated = app(CarePlanService::class)->changeItemStatus($item, CarePlanItem::STATUS_ACTIVE, $req);

    expect($activated->status)->toBe(CarePlanItem::STATUS_ACTIVE);
    expect($activated->activated_at)->not->toBeNull();
    expect($activated->activated_by)->toBe($ctx['doctor']['user']->id);
});

test('an active treatment item can be completed — completion timestamps are stamped', function () {
    $ctx = inpatientCarePlanContext();
    $req = requestAs($ctx['doctor']['user']);

    $carePlan = app(CarePlanService::class)->create($ctx['encounter'], ['goals' => 'Monitor vitals.'], $req);
    $item = app(CarePlanService::class)->addItem($carePlan, [
        'type' => CarePlanItem::TYPE_MONITORING,
        'description' => 'Hourly vitals check',
        'priority' => 'medium',
    ], $req);

    app(CarePlanService::class)->changeItemStatus($item, CarePlanItem::STATUS_ACTIVE, $req);
    $completed = app(CarePlanService::class)->changeItemStatus($item->fresh(), CarePlanItem::STATUS_COMPLETED, $req);

    expect($completed->status)->toBe(CarePlanItem::STATUS_COMPLETED);
    expect($completed->completed_at)->not->toBeNull();
    expect($completed->completed_by)->toBe($ctx['doctor']['user']->id);
});

test('a planned item can be cancelled with a reason', function () {
    $ctx = inpatientCarePlanContext();
    $req = requestAs($ctx['doctor']['user']);

    $carePlan = app(CarePlanService::class)->create($ctx['encounter'], ['goals' => 'test'], $req);
    $item = app(CarePlanService::class)->addItem($carePlan, [
        'type' => CarePlanItem::TYPE_PROCEDURE,
        'description' => 'Biopsy — cancelled pending further assessment',
        'priority' => 'low',
    ], $req);

    $cancelled = app(CarePlanService::class)->cancelItem($item, 'Not clinically indicated.', $req);

    expect($cancelled->status)->toBe(CarePlanItem::STATUS_CANCELLED);
    expect($cancelled->cancellation_reason)->toBe('Not clinically indicated.');
});

test('a completed item can be corrected', function () {
    $ctx = inpatientCarePlanContext();
    $req = requestAs($ctx['doctor']['user']);

    $carePlan = app(CarePlanService::class)->create($ctx['encounter'], ['goals' => 'test'], $req);
    $item = app(CarePlanService::class)->addItem($carePlan, [
        'type' => CarePlanItem::TYPE_CLINICAL_INTERVENTION,
        'description' => 'Original description',
        'priority' => 'high',
    ], $req);

    app(CarePlanService::class)->changeItemStatus($item, CarePlanItem::STATUS_ACTIVE, $req);
    app(CarePlanService::class)->changeItemStatus($item->fresh(), CarePlanItem::STATUS_COMPLETED, $req);

    $corrected = app(CarePlanService::class)->correctItem($item->fresh(), [
        'description' => 'Corrected description',
    ], 'Typo in original.', $req);

    expect($corrected->description)->toBe('Corrected description');
    expect($corrected->correction_reason)->toBe('Typo in original.');
});

test('jumping directly from planned to completed is not allowed', function () {
    $ctx = inpatientCarePlanContext();
    $req = requestAs($ctx['doctor']['user']);

    $carePlan = app(CarePlanService::class)->create($ctx['encounter'], ['goals' => 'test'], $req);
    $item = app(CarePlanService::class)->addItem($carePlan, [
        'type' => CarePlanItem::TYPE_MONITORING,
        'description' => 'Skip test',
        'priority' => 'low',
    ], $req);

    app(CarePlanService::class)->changeItemStatus($item, CarePlanItem::STATUS_COMPLETED, $req);
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// 3. Care plan activation / completion
// ---------------------------------------------------------------------------

test('a care plan requires at least one non-cancelled item before activation', function () {
    $ctx = inpatientCarePlanContext();
    $req = requestAs($ctx['doctor']['user']);

    $carePlan = app(CarePlanService::class)->create($ctx['encounter'], ['goals' => 'empty plan'], $req);

    app(CarePlanService::class)->activate($carePlan, $req);
})->throws(ValidationException::class);

test('a care plan can be activated and then completed', function () {
    $ctx = inpatientCarePlanContext();
    $req = requestAs($ctx['doctor']['user']);

    $carePlan = app(CarePlanService::class)->create($ctx['encounter'], ['goals' => 'Two-item plan.'], $req);
    app(CarePlanService::class)->addItem($carePlan, [
        'type' => CarePlanItem::TYPE_MONITORING,
        'description' => 'Daily weight',
        'priority' => 'low',
    ], $req);

    $activated = app(CarePlanService::class)->activate($carePlan->fresh(), $req);
    expect($activated->status)->toBe(CarePlan::STATUS_ACTIVE);

    $completed = app(CarePlanService::class)->complete($activated->fresh(), $req);
    expect($completed->status)->toBe(CarePlan::STATUS_COMPLETED);
    expect($completed->completed_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// 4. IDOR — facility isolation
// ---------------------------------------------------------------------------

test('the inpatient clinical profile page is forbidden to an admin from a different hospital', function () {
    $ctx = inpatientCarePlanContext();

    $otherHospital = Hospital::factory()->create();
    $otherAdmin = User::factory()->create();
    Staff::factory()->create(['hospital_id' => $otherHospital->id, 'user_id' => $otherAdmin->id]);
    $otherAdmin->assignRole('hospital-admin');

    $this->actingAs($otherAdmin)
        ->get(route('inpatient.clinical-profile', $ctx['admission']))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// 5. Audit log entries are created for care plan lifecycle events
// ---------------------------------------------------------------------------

test('care plan creation records an audit event', function () {
    $ctx = inpatientCarePlanContext();

    app(CarePlanService::class)->create($ctx['encounter'], ['goals' => 'audit test'], requestAs($ctx['doctor']['user']));

    $this->assertDatabaseHas('audit_logs', ['action' => 'care_plan_created']);
});

test('treatment item activation records an audit event', function () {
    $ctx = inpatientCarePlanContext();
    $req = requestAs($ctx['doctor']['user']);

    $carePlan = app(CarePlanService::class)->create($ctx['encounter'], ['goals' => 'audit test'], $req);
    $item = app(CarePlanService::class)->addItem($carePlan, [
        'type' => CarePlanItem::TYPE_CLINICAL_INTERVENTION,
        'description' => 'IV line',
        'priority' => 'high',
    ], $req);

    app(CarePlanService::class)->changeItemStatus($item, CarePlanItem::STATUS_ACTIVE, $req);

    $this->assertDatabaseHas('audit_logs', ['action' => 'treatment_item_status_changed']);
});

// ---------------------------------------------------------------------------
// 6. HTTP: clinical profile page loads with care plan data
// ---------------------------------------------------------------------------

test('the clinical profile page loads for an authorized doctor', function () {
    $ctx = inpatientCarePlanContext();

    $this->actingAs($ctx['doctor']['user'])
        ->get(route('inpatient.clinical-profile', $ctx['admission']))
        ->assertOk();
});
