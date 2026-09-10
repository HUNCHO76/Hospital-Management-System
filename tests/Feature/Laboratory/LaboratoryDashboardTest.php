<?php

use App\Models\ClinicalEncounter;
use App\Models\Hospital;
use App\Models\Staff;
use App\Models\User;
use App\Services\ConsultationService;
use App\Services\LaboratoryOrderService;
use App\Services\TriageService;
use App\Services\VitalSignsService;

test('the laboratory dashboard shows real status counts and a recent-orders preview', function () {
    $ctx = diagnosisReadyEncounter();
    $test = activeLaboratoryTest();
    $order = app(LaboratoryOrderService::class)->create($ctx['encounter'], [
        'test_ids' => [$test->id],
        'priority' => 'stat',
    ], requestAs($ctx['doctor']['user']));

    $technician = userWithRole('laboratory-technician');

    $response = $this->actingAs($technician)->get(route('laboratory.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('laboratory/index')
        ->where('recentOrders.0.order_number', $order->fresh()->order_number)
        ->where('recentOrders.0.priority', 'stat')
        ->where('statusCounts.ordered', 1));
});

test('the laboratory worklist lists orders, paginated, and filters by status and priority', function () {
    $ctx = diagnosisReadyEncounter();
    $test = activeLaboratoryTest();
    app(LaboratoryOrderService::class)->create($ctx['encounter'], [
        'test_ids' => [$test->id],
        'priority' => 'routine',
    ], requestAs($ctx['doctor']['user']));

    $technician = userWithRole('laboratory-technician');

    $response = $this->actingAs($technician)->get(route('laboratory.orders.index'));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('laboratory/orders/index')->has('orders.data', 1));

    $response = $this->actingAs($technician)->get(route('laboratory.orders.index', ['priority' => 'urgent']));
    $response->assertInertia(fn ($page) => $page->has('orders.data', 0));

    $response = $this->actingAs($technician)->get(route('laboratory.orders.index', ['priority' => 'routine']));
    $response->assertInertia(fn ($page) => $page->has('orders.data', 1));

    $response = $this->actingAs($technician)->get(route('laboratory.orders.index', ['status' => 'cancelled']));
    $response->assertInertia(fn ($page) => $page->has('orders.data', 0));
});

test('a hospital-admin only sees laboratory orders within their own hospital on the worklist', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $test = activeLaboratoryTest();

    $ctxA = clinicalEncounterContext($hospitalA);
    $doctorA = doctorWithPractitioner($hospitalA);
    $encounterA = ClinicalEncounter::factory()->create(['department_id' => $ctxA['department']->id, 'patient_id' => $ctxA['patient']->id]);

    $nurseRequest = requestAs(userWithRole('nurse'));
    $triage = app(TriageService::class)->create($encounterA, $nurseRequest);
    app(TriageService::class)->start($triage, $nurseRequest);
    app(TriageService::class)->update($triage, ['triage_category' => 'urgent'], $nurseRequest);
    app(VitalSignsService::class)->record($triage, ['temperature_c' => 37.0], $nurseRequest);
    app(TriageService::class)->complete($triage->fresh(), $nurseRequest);

    $docRequest = requestAs($doctorA['user']);
    $consultation = app(ConsultationService::class)->create($encounterA->fresh(), $docRequest);
    app(ConsultationService::class)->start($consultation, $docRequest);

    app(LaboratoryOrderService::class)->create($encounterA->fresh(), ['test_ids' => [$test->id]], $docRequest);

    $adminB = User::factory()->create();
    $adminB->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminB->id, 'hospital_id' => $hospitalB->id]);

    $response = $this->actingAs($adminB)->get(route('laboratory.orders.index'));
    $response->assertInertia(fn ($page) => $page->has('orders.data', 0));
});
