<?php

use App\Models\Department;
use App\Models\MaternityProfile;
use App\Models\ObservationEscalation;
use App\Models\Staff;
use App\Services\MaternityService;
use Carbon\Carbon;

function maternityTestContext(): array
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

test('nurse with matching ward can access maternity workspace', function () {
    $ctx = maternityTestContext();
    $user = $ctx['user'];
    $admission = $ctx['admission'];

    $response = $this->actingAs($user)->get(route('inpatient.maternity.show', $admission->id));
    $response->assertStatus(200);
});

test('nurse from another department without view-all permission is forbidden from maternity workspace', function () {
    $ctx = maternityTestContext();
    $admission = $ctx['admission'];

    $otherDept = Department::factory()->create(['hospital_id' => $admission->hospital_id]);
    $otherNurse = userWithRole('nurse');
    Staff::factory()->create([
        'user_id' => $otherNurse->id,
        'hospital_id' => $admission->hospital_id,
        'department_id' => $otherDept->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($otherNurse)->get(route('inpatient.maternity.show', $admission->id));
    $response->assertStatus(403);
});

test('saving maternity profile calculates EDD and gestational age from LMP', function () {
    $ctx = maternityTestContext();
    $user = $ctx['user'];
    $admission = $ctx['admission'];

    $lmp = now()->subWeeks(39)->toDateString();

    $response = $this->actingAs($user)->post(route('inpatient.maternity.profile.store', $admission->id), [
        'gravida' => 2,
        'para' => 1,
        'living_children' => 1,
        'abortions' => 0,
        'last_menstrual_period' => $lmp,
        'presentation' => 'cephalic',
        'membrane_status' => 'intact',
        'blood_group' => 'O',
        'rhesus' => 'positive',
        'hiv_status' => 'negative',
        'vdrl_rpr_status' => 'non_reactive',
        'risk_factors' => ['Previous Caesarean Section'],
        'labour_stage' => 'latent_phase',
    ]);

    $response->assertSessionHasNoErrors();

    $profile = MaternityProfile::where('admission_id', $admission->id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->gravida)->toBe(2);
    expect($profile->para)->toBe(1);
    expect($profile->presentation)->toBe('cephalic');
    expect($profile->risk_factors)->toContain('Previous Caesarean Section');

    // EDD calculated via Naegele's rule (LMP + 280 days)
    $expectedEdd = Carbon::parse($lmp)->addDays(280)->toDateString();
    expect($profile->estimated_date_of_delivery->toDateString())->toBe($expectedEdd);
    expect($profile->gestational_age_weeks)->toBeGreaterThanOrEqual(38.0);
});

test('recording partograph entries tracks cervical dilation and triggers auto escalation when action line is crossed', function () {
    $ctx = maternityTestContext();
    $user = $ctx['user'];
    $admission = $ctx['admission'];

    $profile = app(MaternityService::class)->saveProfile($admission, [
        'gravida' => 1,
        'para' => 0,
        'presentation' => 'cephalic',
        'membrane_status' => 'ruptured',
        'labour_stage' => 'active_phase',
    ], requestAs($user));

    // Entry 1: 08:00 — Active phase start at 4.0 cm
    $time0 = Carbon::parse('2026-10-23 08:00:00');
    $entry1 = app(MaternityService::class)->recordPartograph($profile, [
        'recorded_at' => $time0->toDateTimeString(),
        'fetal_heart_rate' => 140,
        'amniotic_fluid' => 'C',
        'moulding' => '0',
        'cervical_dilation_cm' => 4.0,
        'descent_of_head' => 4,
        'contractions_per_10min' => 2,
        'contraction_duration' => 'mild',
        'systolic_bp' => 118,
        'diastolic_bp' => 76,
        'pulse_bpm' => 80,
    ], requestAs($user));

    expect($entry1->cervical_dilation_cm)->toBe(4.0);
    expect($entry1->alert_line_dilation_cm)->toBe(4.0);
    expect($entry1->action_line_crossed)->toBeFalse();

    // Entry 2: 13:00 (5 hours later) — Slow progress: dilation only 4.5 cm (action line expected >= 5.0 cm)
    $time1 = Carbon::parse('2026-10-23 13:00:00');
    $entry2 = app(MaternityService::class)->recordPartograph($profile, [
        'recorded_at' => $time1->toDateTimeString(),
        'fetal_heart_rate' => 136,
        'amniotic_fluid' => 'C',
        'moulding' => '+',
        'cervical_dilation_cm' => 4.5,
        'descent_of_head' => 4,
        'contractions_per_10min' => 3,
        'contraction_duration' => 'moderate',
    ], requestAs($user));

    expect($entry2->action_line_dilation_cm)->toBe(5.0);
    expect($entry2->action_line_crossed)->toBeTrue();

    // Verify auto escalation was raised for action line crossed
    $escalation = ObservationEscalation::where('admission_id', $admission->id)
        ->where('reason', 'like', '%action line crossed%')
        ->first();
    expect($escalation)->not->toBeNull();
    expect($escalation->status)->toBe(ObservationEscalation::STATUS_OPEN);
});

test('partograph reading with fetal distress triggers auto escalation', function () {
    $ctx = maternityTestContext();
    $user = $ctx['user'];
    $admission = $ctx['admission'];

    $profile = app(MaternityService::class)->saveProfile($admission, [
        'gravida' => 1,
        'para' => 0,
        'labour_stage' => 'active_phase',
    ], requestAs($user));

    // Abnormal FHR (100 bpm = fetal bradycardia) + Meconium stained liquor
    $entry = app(MaternityService::class)->recordPartograph($profile, [
        'recorded_at' => now()->toDateTimeString(),
        'fetal_heart_rate' => 100,
        'amniotic_fluid' => 'M',
        'moulding' => '++',
        'cervical_dilation_cm' => 6.0,
        'contractions_per_10min' => 4,
        'contraction_duration' => 'strong',
    ], requestAs($user));

    $escalation = ObservationEscalation::where('admission_id', $admission->id)
        ->where('reason', 'like', '%Abnormal FHR%')
        ->first();

    expect($escalation)->not->toBeNull();
    expect($escalation->reason)->toContain('Meconium-stained liquor');
});

test('documenting delivery records maternal and newborn outcomes and flags PPH on significant blood loss', function () {
    $ctx = maternityTestContext();
    $user = $ctx['user'];
    $admission = $ctx['admission'];
    $practitioner = $ctx['practitioner'];

    $profile = app(MaternityService::class)->saveProfile($admission, [
        'gravida' => 2,
        'para' => 1,
        'labour_stage' => 'second_stage',
    ], requestAs($user));

    $response = $this->actingAs($user)->post(route('inpatient.maternity.delivery.store', [$admission->id, $profile->id]), [
        'delivered_at' => now()->toDateTimeString(),
        'delivery_mode' => 'spontaneous_vaginal',
        'conducted_by' => $user->id,
        'placenta_delivered_at' => now()->toDateTimeString(),
        'placenta_mode' => 'spontaneous',
        'placenta_complete' => true,
        'blood_loss_ml' => 650, // >= 500 ml -> triggers PPH
        'perineal_status' => 'second_degree',
        'suture_materials_used' => 'Chromic catgut 2-0',
        'oxytocic_given' => 'Oxytocin 10 IU IM',
        'baby_outcome' => 'live_birth',
        'baby_gender' => 'male',
        'birth_weight_grams' => 3450,
        'birth_length_cm' => 51.0,
        'head_circumference_cm' => 35.0,
        'apgar_1min' => 8,
        'apgar_5min' => 10,
        'apgar_10min' => 10,
        'resuscitation_required' => false,
        'vitamin_k_given' => true,
        'eye_ointment_given' => true,
        'bcg_given' => true,
        'polio_0_given' => true,
    ]);

    $response->assertSessionHasNoErrors();

    $delivery = $profile->deliveryRecord()->first();
    expect($delivery)->not->toBeNull();
    expect($delivery->delivery_mode)->toBe('spontaneous_vaginal');
    expect($delivery->postpartum_hemorrhage)->toBeTrue();
    expect($delivery->baby_outcome)->toBe('live_birth');
    expect($delivery->baby_gender)->toBe('male');
    expect($delivery->apgar_5min)->toBe(10);
    expect($delivery->birth_weight_grams)->toBe(3450);

    // Profile transitioned to completed and delivered
    $profile->refresh();
    expect($profile->status)->toBe('completed');
    expect($profile->labour_stage)->toBe('delivered');

    // Escalation created for PPH
    $pphEscalation = ObservationEscalation::where('admission_id', $admission->id)
        ->where('reason', 'like', '%Postpartum Hemorrhage%')
        ->first();
    expect($pphEscalation)->not->toBeNull();
});
