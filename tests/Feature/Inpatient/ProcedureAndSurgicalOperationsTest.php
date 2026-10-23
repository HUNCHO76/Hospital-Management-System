<?php

use App\Models\Department;
use App\Models\ObservationEscalation;
use App\Models\ProcedureOrder;
use App\Models\Staff;
use App\Models\SurgicalCase;
use App\Services\ProcedureService;

function procedureTestContext(): array
{
    $ctx = activeAdmission();
    $admission = $ctx['admission'];
    $user = $ctx['user'];

    Staff::factory()->create([
        'user_id' => $user->id,
        'hospital_id' => $admission->hospital_id,
        'department_id' => $admission->department_id,
        'status' => 'active',
    ]);

    return $ctx;
}

test('nurse or clinician with matching ward can access procedures workspace', function () {
    $ctx = procedureTestContext();
    $user = $ctx['user'];
    $admission = $ctx['admission'];

    $response = $this->actingAs($user)->get(route('inpatient.procedures.index', $admission->id));
    $response->assertStatus(200);
});

test('nurse from another ward without view-all permission is forbidden from procedures workspace', function () {
    $ctx = procedureTestContext();
    $admission = $ctx['admission'];

    $otherDept = Department::factory()->create(['hospital_id' => $admission->hospital_id]);
    $otherNurse = userWithRole('nurse');
    Staff::factory()->create([
        'user_id' => $otherNurse->id,
        'hospital_id' => $admission->hospital_id,
        'department_id' => $otherDept->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($otherNurse)->get(route('inpatient.procedures.index', $admission->id));
    $response->assertStatus(403);
});

test('doctor can prescribe an emergency surgical procedure order', function () {
    $ctx = procedureTestContext();
    $user = $ctx['user'];
    $admission = $ctx['admission'];

    $response = $this->actingAs($user)->post(route('inpatient.procedures.store', $admission->id), [
        'procedure_name' => 'Emergency Exploratory Laparotomy',
        'procedure_type' => ProcedureOrder::TYPE_MAJOR_THEATRE,
        'urgency' => 'emergency',
        'clinical_indication' => 'Acute abdomen, suspected bowel perforation and peritonitis',
        'scheduled_at' => now()->toDateTimeString(),
    ]);

    $response->assertSessionHasNoErrors();

    $order = ProcedureOrder::where('admission_id', $admission->id)->first();
    expect($order)->not->toBeNull();
    expect($order->order_number)->toStartWith('PROC-');
    expect($order->procedure_name)->toBe('Emergency Exploratory Laparotomy');
    expect($order->urgency)->toBe('emergency');
    expect($order->status)->toBe(ProcedureOrder::STATUS_SCHEDULED);
});

test('clinical staff can schedule operating theatre case and update WHO safety checklist', function () {
    $ctx = procedureTestContext();
    $user = $ctx['user'];
    $admission = $ctx['admission'];

    $order = app(ProcedureService::class)->createOrder($admission, [
        'procedure_name' => 'Appendectomy',
        'procedure_type' => ProcedureOrder::TYPE_MAJOR_THEATRE,
        'urgency' => 'urgent',
        'clinical_indication' => 'Acute phlegmonous appendicitis',
    ], requestAs($user));

    $surgeon = userWithRole('doctor');
    $anesthetist = userWithRole('doctor');

    // 1. Schedule theatre case
    $scheduleResponse = $this->actingAs($user)->post(route('inpatient.procedures.schedule', [$admission->id, $order->id]), [
        'theatre_room' => 'Main Operating Theatre 2',
        'lead_surgeon_id' => $surgeon->id,
        'anesthetist_id' => $anesthetist->id,
        'anesthesia_type' => 'general',
        'started_at' => now()->toDateTimeString(),
    ]);
    $scheduleResponse->assertSessionHasNoErrors();

    $surgCase = SurgicalCase::where('procedure_order_id', $order->id)->first();
    expect($surgCase)->not->toBeNull();
    expect($surgCase->case_number)->toStartWith('SURG-');
    expect($surgCase->theatre_room)->toBe('Main Operating Theatre 2');
    expect($surgCase->status)->toBe(SurgicalCase::STATUS_BOOKED);

    // 2. Complete WHO Surgical Safety Checklist
    $checklistResponse = $this->actingAs($user)->post(route('inpatient.procedures.cases.checklist', [$admission->id, $surgCase->id]), [
        'identity_confirmed' => true,
        'site_marked' => true,
        'consent_confirmed' => true,
        'anesthesia_safety_check_completed' => true,
        'pulse_oximeter_functioning' => true,
        'allergy_screened' => true,
        'difficult_airway_risk_assessed' => true,
        'blood_loss_risk_assessed' => true,
        'fasting_npo_hours_confirmed' => true,
    ]);
    $checklistResponse->assertSessionHasNoErrors();

    $surgCase->refresh();
    expect($surgCase->identity_confirmed)->toBeTrue();
    expect($surgCase->consent_confirmed)->toBeTrue();
    expect($surgCase->checklist_completed_by)->toBe($user->id);
});

test('recording intra-operative surgical notes transitions case to recovery and auto-escalates on major hemorrhage', function () {
    $ctx = procedureTestContext();
    $user = $ctx['user'];
    $admission = $ctx['admission'];

    $order = app(ProcedureService::class)->createOrder($admission, [
        'procedure_name' => 'Splenectomy',
        'procedure_type' => ProcedureOrder::TYPE_MAJOR_THEATRE,
        'urgency' => 'emergency',
        'clinical_indication' => 'Splenic rupture grade IV post trauma',
    ], requestAs($user));

    $surgCase = app(ProcedureService::class)->scheduleSurgicalCase($order, [
        'theatre_room' => 'Theatre 1',
        'lead_surgeon_id' => $user->id,
        'anesthesia_type' => 'general',
    ], requestAs($user));

    // Record intra-op with severe hemorrhage (1200ml EBL)
    $response = $this->actingAs($user)->post(route('inpatient.procedures.cases.intra-op', [$admission->id, $surgCase->id]), [
        'started_at' => now()->subHours(2)->toDateTimeString(),
        'ended_at' => now()->toDateTimeString(),
        'pre_operative_diagnosis' => 'Splenic rupture with hemoperitoneum',
        'post_operative_diagnosis' => 'Grade IV splenic laceration, total splenectomy done',
        'surgical_technique' => 'Midline laparotomy, splenic hilum clamped, splenectomy performed, hemostasis secured with surgicel',
        'intraoperative_findings' => '1.2L hemoperitoneum, shattered spleen lower pole',
        'swab_instrument_count_correct' => true,
        'estimated_blood_loss_ml' => 1200,
    ]);

    $response->assertSessionHasNoErrors();

    $surgCase->refresh();
    expect($surgCase->status)->toBe(SurgicalCase::STATUS_RECOVERY);
    expect($surgCase->estimated_blood_loss_ml)->toBe(1200);

    // Auto escalation for major intraoperative hemorrhage
    $escalation = ObservationEscalation::where('admission_id', $admission->id)
        ->where('reason', 'like', '%Major intra-operative hemorrhage%')
        ->first();
    expect($escalation)->not->toBeNull();
    expect($escalation->status)->toBe(ObservationEscalation::STATUS_OPEN);
});

test('recording PACU recovery monitoring completes surgical case and procedure order', function () {
    $ctx = procedureTestContext();
    $user = $ctx['user'];
    $admission = $ctx['admission'];

    $order = app(ProcedureService::class)->createOrder($admission, [
        'procedure_name' => 'Open Herniorrhaphy',
        'procedure_type' => ProcedureOrder::TYPE_MINOR_THEATRE,
        'urgency' => 'elective',
        'clinical_indication' => 'Right indirect reducible inguinal hernia',
    ], requestAs($user));

    $surgCase = app(ProcedureService::class)->scheduleSurgicalCase($order, [
        'theatre_room' => 'Minor OT',
        'lead_surgeon_id' => $user->id,
        'anesthesia_type' => 'spinal',
    ], requestAs($user));

    app(ProcedureService::class)->recordIntraOp($surgCase, [
        'started_at' => now()->subHour()->toDateTimeString(),
        'ended_at' => now()->toDateTimeString(),
        'post_operative_diagnosis' => 'Right inguinal hernia repaired',
        'surgical_technique' => 'Bassini repair performed with prolene sutures',
        'estimated_blood_loss_ml' => 50,
        'swab_instrument_count_correct' => true,
    ], requestAs($user));

    $response = $this->actingAs($user)->post(route('inpatient.procedures.cases.pacu', [$admission->id, $surgCase->id]), [
        'recovery_started_at' => now()->subMinutes(45)->toDateTimeString(),
        'recovery_ended_at' => now()->toDateTimeString(),
        'aldrete_score' => 9,
        'post_operative_orders' => 'Keep flat for 6 hours post-spinal. Vitals q30m x 2h, paracetamol 1g IV q8h.',
        'transferred_to_ward_at' => now()->toDateTimeString(),
    ]);

    $response->assertSessionHasNoErrors();

    $surgCase->refresh();
    expect($surgCase->status)->toBe(SurgicalCase::STATUS_COMPLETED);
    expect($surgCase->aldrete_score)->toBe(9);

    $order->refresh();
    expect($order->status)->toBe(ProcedureOrder::STATUS_COMPLETED);
});

test('performing ward-based bedside procedure records notes and completes order', function () {
    $ctx = procedureTestContext();
    $user = $ctx['user'];
    $admission = $ctx['admission'];

    $order = app(ProcedureService::class)->createOrder($admission, [
        'procedure_name' => 'Closed Tube Thoracostomy (Chest Tube Insertion)',
        'procedure_type' => ProcedureOrder::TYPE_BEDSIDE,
        'urgency' => 'urgent',
        'clinical_indication' => 'Right-sided moderate pleural effusion',
    ], requestAs($user));

    $response = $this->actingAs($user)->post(route('inpatient.procedures.bedside', [$admission->id, $order->id]), [
        'performed_at' => now()->toDateTimeString(),
        'procedure_notes' => '5th intercostal space mid-axillary line infiltrated with 10ml 1% lidocaine. 28Fr chest tube inserted, connected to underwater seal. 700ml serous fluid drained.',
        'patient_tolerance' => 'tolerated_well',
        'consumables_used' => 'Lidocaine 1% 10ml, 28Fr Chest tube, Underwater seal bottle, sterile drape',
    ]);

    $response->assertSessionHasNoErrors();

    $event = $order->bedsideEvents()->first();
    expect($event)->not->toBeNull();
    expect($event->performed_by)->toBe($user->id);
    expect($event->patient_tolerance)->toBe('tolerated_well');

    $order->refresh();
    expect($order->status)->toBe(ProcedureOrder::STATUS_COMPLETED);
});
