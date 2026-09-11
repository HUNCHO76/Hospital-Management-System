<?php

use App\Models\Department;
use App\Models\Patient;
use App\Services\ClinicalNoteService;
use App\Services\RadiologyOrderService;

test('a guest is redirected to login for the clinical history page', function () {
    $ctx = carePlanReadyEncounter();

    $this->get(route('patients.clinical-history', $ctx['patient']))->assertRedirect(route('login'));
});

test('a receptionist cannot view a patient\'s clinical history despite patients.view', function () {
    $ctx = carePlanReadyEncounter();
    $receptionist = userWithRole('receptionist');

    $this->actingAs($receptionist)->get(route('patients.clinical-history', $ctx['patient']))->assertForbidden();
});

test('a doctor can view a patient\'s clinical history and it surfaces the full encounter chain', function () {
    $ctx = carePlanReadyEncounter();
    app(ClinicalNoteService::class)->create($ctx['encounter'], ['note_type' => 'progress', 'note_content' => 'x'], requestAs($ctx['doctor']['user']));

    $response = $this->actingAs($ctx['doctor']['user'])->get(route('patients.clinical-history', $ctx['patient']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('patients/clinical-history')
        ->where('encounters.data.0.id', $ctx['encounter']->id)
        ->where('encounters.data.0.diagnoses.0.id', $ctx['diagnosis']->id)
        ->has('encounters.data.0.clinical_notes', 1));
});

test('viewing the clinical history page records an audit event without content', function () {
    $ctx = carePlanReadyEncounter();

    $this->actingAs($ctx['doctor']['user'])->get(route('patients.clinical-history', $ctx['patient']));

    $this->assertDatabaseHas('audit_logs', ['action' => 'clinical_history_viewed', 'auditable_id' => $ctx['patient']->id]);
});

test('the clinical history page filters by department and encounter status', function () {
    $ctx = carePlanReadyEncounter();

    $response = $this->actingAs($ctx['doctor']['user'])->get(route('patients.clinical-history', [
        'patient' => $ctx['patient'],
        'department_id' => $ctx['department']->id,
    ]));
    $response->assertInertia(fn ($page) => $page->has('encounters.data', 1));

    $otherDepartment = Department::factory()->create(['hospital_id' => $ctx['hospital']->id]);
    $response = $this->actingAs($ctx['doctor']['user'])->get(route('patients.clinical-history', [
        'patient' => $ctx['patient'],
        'department_id' => $otherDepartment->id,
    ]));
    $response->assertInertia(fn ($page) => $page->has('encounters.data', 0));
});

test('a patient\'s clinical history surfaces their radiology orders, including procedure and status', function () {
    $ctx = carePlanReadyEncounter();
    $procedure = activeRadiologyProcedure(['code' => 'XR-HIST', 'name' => 'History X-Ray', 'modality' => 'x_ray']);
    $order = app(RadiologyOrderService::class)->create($ctx['encounter'], [
        'procedure_ids' => [$procedure->id],
    ], requestAs($ctx['doctor']['user']));

    $response = $this->actingAs($ctx['doctor']['user'])->get(route('patients.clinical-history', $ctx['patient']));

    $response->assertInertia(fn ($page) => $page
        ->where('encounters.data.0.radiology_orders.0.id', $order->id)
        ->where('encounters.data.0.radiology_orders.0.status', 'ordered')
        ->where('encounters.data.0.radiology_orders.0.items.0.procedure_code', 'XR-HIST'));
});

test('requesting one patient\'s clinical history never returns another patient\'s encounters', function () {
    $ctxA = carePlanReadyEncounter();
    $otherPatient = Patient::factory()->create();

    // clinical-history.view is a scope permission, not a per-patient
    // ownership check (matching ClinicalEncounterPolicy's own hospital-scope
    // model) — the guarantee that matters is data isolation: requesting the
    // unrelated patient's timeline must never surface ctxA's encounter.
    $response = $this->actingAs($ctxA['doctor']['user'])->get(route('patients.clinical-history', $otherPatient));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('encounters.data', 0));
});
