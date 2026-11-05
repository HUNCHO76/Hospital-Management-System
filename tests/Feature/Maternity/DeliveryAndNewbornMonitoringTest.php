<?php

use App\Models\Admission;
use App\Models\DeliveryRecord;
use App\Models\Department;
use App\Models\Hospital;
use App\Models\MaternityProfile;
use App\Models\NewbornGrowthRecord;
use App\Models\Patient;
use App\Models\ReceptionVisit;
use App\Models\Staff;
use App\Models\User;

function deliveryTestContext(): array
{
    $hospital = Hospital::factory()->create(['code' => 'MAIN']);

    $maternityDept = Department::factory()->create([
        'hospital_id' => $hospital->id,
        'name' => 'Maternity Department',
        'code' => 'MATERNITY',
        'status' => 'active',
    ]);

    $mother = Patient::factory()->create([
        'gender' => 'female',
        'first_name' => 'Halima',
        'last_name' => 'Mwinyi',
    ]);

    $midwife = userWithRole('nurse');
    Staff::factory()->create([
        'user_id' => $midwife->id,
        'hospital_id' => $hospital->id,
        'department_id' => $maternityDept->id,
        'job_title' => 'Registered Midwife',
        'status' => 'active',
    ]);

    $doctor = userWithRole('doctor');
    Staff::factory()->create([
        'user_id' => $doctor->id,
        'hospital_id' => $hospital->id,
        'department_id' => $maternityDept->id,
        'job_title' => 'Obstetrician',
        'status' => 'active',
    ]);

    $visit = ReceptionVisit::create([
        'patient_id' => $mother->id,
        'department_id' => $maternityDept->id,
        'visit_type' => 'walk_in',
        'visit_purpose' => 'labour_assessment',
        'status' => ReceptionVisit::STATUS_CHECKED_IN,
        'maternity_status' => ReceptionVisit::MATERNITY_STATUS_WAITING,
    ]);

    return compact('hospital', 'maternityDept', 'mother', 'midwife', 'doctor', 'visit');
}

test('midwife can document delivery creating an independent linked clinical patient record for the newborn without admission', function () {
    $ctx = deliveryTestContext();

    // Mother assessment initiated
    $this->actingAs($ctx['midwife'])->get(route('maternity.mother-assessment', $ctx['visit']->id));

    $response = $this->actingAs($ctx['midwife'])->post(route('maternity.deliveries.store', $ctx['visit']->id), [
        'delivered_at' => now()->toDateTimeString(),
        'delivery_mode' => 'spontaneous_vaginal',
        'baby_outcome' => 'live_birth',
        'baby_gender' => 'female',
        'baby_name' => 'Baby Halima',
        'birth_order' => 1,
        'birth_weight_grams' => 3200,
        'birth_length_cm' => 49.5,
        'head_circumference_cm' => 34.0,
        'apgar_1min' => 8,
        'apgar_5min' => 10,
        'apgar_10min' => 10,
        'resuscitation_required' => false,
        'vitamin_k_given' => true,
        'eye_ointment_given' => true,
        'bcg_given' => true,
        'polio_0_given' => true,
        'placenta_mode' => 'spontaneous',
        'placenta_complete' => true,
        'blood_loss_ml' => 200,
        'perineal_status' => 'intact',
        'notes' => 'Spontaneous vaginal delivery of a vigorous baby girl. Immediate skin-to-skin and breastfeeding initiated within 30 minutes.',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    // 1. Verify delivery record created
    $delivery = DeliveryRecord::where('patient_id', $ctx['mother']->id)->first();
    expect($delivery)->not->toBeNull();
    expect($delivery->delivery_mode)->toBe('spontaneous_vaginal');
    expect($delivery->baby_outcome)->toBe('live_birth');
    expect($delivery->birth_weight_grams)->toBe(3200);
    expect($delivery->apgar_1min)->toBe(8);
    expect($delivery->apgar_5min)->toBe(10);
    expect($delivery->baby_patient_id)->not->toBeNull();

    // 2. CRITICAL: Verify newborn received an INDEPENDENT clinical Patient record linked to the mother
    $newbornPatient = Patient::find($delivery->baby_patient_id);
    expect($newbornPatient)->not->toBeNull();
    expect($newbornPatient->id)->not->toBe($ctx['mother']->id);
    expect($newbornPatient->mother_patient_id)->toBe($ctx['mother']->id);
    expect($newbornPatient->first_name)->toBe('Baby Halima');
    expect($newbornPatient->gender)->toBe('female');
    expect($newbornPatient->patient_number)->not->toBeNull();
    expect($newbornPatient->patient_number)->toStartWith('PT');

    // 3. CRITICAL: Verify initial birth growth observation was automatically created
    $birthGrowth = NewbornGrowthRecord::where('patient_id', $newbornPatient->id)->first();
    expect($birthGrowth)->not->toBeNull();
    expect($birthGrowth->age_days)->toBe(0);
    expect((float) $birthGrowth->weight_kg)->toBe(3.2);
    expect((float) $birthGrowth->length_height_cm)->toBe(49.5);
    expect((float) $birthGrowth->head_circumference_cm)->toBe(34.0);
    expect($birthGrowth->growth_reference_standard)->toBe('WHO Child Growth Standards 2006');

    // 4. CRITICAL: Maternity profile care stage transitioned to postpartum
    $profile = MaternityProfile::where('reception_visit_id', $ctx['visit']->id)->first();
    expect($profile->labour_stage)->toBe('delivered');
    expect($profile->care_stage)->toBe(MaternityProfile::CARE_STAGE_POSTPARTUM_CARE);

    // 5. CRITICAL: Outpatient delivery performed WITHOUT an inpatient admission!
    expect(Admission::where('patient_id', $ctx['mother']->id)->count())->toBe(0);
});

test('clinician can record serial follow up growth and developmental milestones for the newborn', function () {
    $ctx = deliveryTestContext();

    // Document birth first
    $this->actingAs($ctx['midwife'])->get(route('maternity.mother-assessment', $ctx['visit']->id));
    $this->actingAs($ctx['midwife'])->post(route('maternity.deliveries.store', $ctx['visit']->id), [
        'delivered_at' => now()->subDays(42)->toDateTimeString(), // 6 weeks ago
        'delivery_mode' => 'spontaneous_vaginal',
        'baby_outcome' => 'live_birth',
        'baby_gender' => 'male',
        'baby_name' => 'Baby Salim',
        'birth_weight_grams' => 3100,
        'birth_length_cm' => 49.0,
        'head_circumference_cm' => 33.5,
        'apgar_1min' => 9,
        'apgar_5min' => 10,
    ]);

    $delivery = DeliveryRecord::where('patient_id', $ctx['mother']->id)->first();
    $babyPatient = Patient::find($delivery->baby_patient_id);
    expect($babyPatient)->not->toBeNull();

    // 6-week child health & growth follow-up visit
    $response = $this->actingAs($ctx['doctor'])->post(route('maternity.newborn-growth.store', $babyPatient->id), [
        'recorded_at' => now()->toDateTimeString(),
        'weight_kg' => 4.6,
        'length_height_cm' => 55.2,
        'head_circumference_cm' => 37.8,
        'temperature_c' => 36.6,
        'feeding_method' => 'exclusive_breastfeeding',
        'immunizations_given' => ['OPV 1', 'Pentavalent 1', 'PCV 1', 'Rotavirus 1'],
        'developmental_milestones' => 'Social smile present. Follows moving object with eyes. Good head control when prone.',
        'clinical_notes' => 'Thriving 6-week infant. Growth parameters tracking along WHO 50th percentile. No developmental concerns.',
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $growthRecords = NewbornGrowthRecord::where('patient_id', $babyPatient->id)
        ->orderBy('age_days')
        ->get();

    expect($growthRecords)->toHaveCount(2);

    // Baseline birth record
    expect($growthRecords[0]->age_days)->toBe(0);
    expect((float) $growthRecords[0]->weight_kg)->toBe(3.1);

    // 6-week follow up observation
    expect($growthRecords[1]->age_days)->toBe(42);
    expect((float) $growthRecords[1]->weight_kg)->toBe(4.6);
    expect((float) $growthRecords[1]->length_height_cm)->toBe(55.2);
    expect((float) $growthRecords[1]->head_circumference_cm)->toBe(37.8);
    expect($growthRecords[1]->immunizations_given)->toContain('Pentavalent 1');
    expect($growthRecords[1]->developmental_milestones)->toContain('Social smile present');
    expect($growthRecords[1]->growth_reference_standard)->toBe('WHO Child Growth Standards 2006');
});

test('multiple births create separate independent patient records for each twin', function () {
    $ctx = deliveryTestContext();

    $this->actingAs($ctx['midwife'])->get(route('maternity.mother-assessment', $ctx['visit']->id));

    // Twin 1 delivery
    $this->actingAs($ctx['midwife'])->post(route('maternity.deliveries.store', $ctx['visit']->id), [
        'delivered_at' => now()->subMinutes(15)->toDateTimeString(),
        'delivery_mode' => 'spontaneous_vaginal',
        'baby_outcome' => 'live_birth',
        'baby_gender' => 'male',
        'baby_name' => 'Twin A',
        'birth_order' => 1,
        'birth_weight_grams' => 2400,
        'apgar_1min' => 8,
        'apgar_5min' => 9,
    ]);

    // Twin 2 delivery (15 minutes later)
    $this->actingAs($ctx['midwife'])->post(route('maternity.deliveries.store', $ctx['visit']->id), [
        'delivered_at' => now()->toDateTimeString(),
        'delivery_mode' => 'breech_extraction',
        'baby_outcome' => 'live_birth',
        'baby_gender' => 'female',
        'baby_name' => 'Twin B',
        'birth_order' => 2,
        'birth_weight_grams' => 2250,
        'apgar_1min' => 7,
        'apgar_5min' => 9,
    ]);

    $deliveries = DeliveryRecord::where('patient_id', $ctx['mother']->id)->orderBy('birth_order')->get();
    expect($deliveries)->toHaveCount(2);

    expect($deliveries[0]->baby_name)->toBe('Twin A');
    expect($deliveries[1]->baby_name)->toBe('Twin B');

    // Both twins have distinct patient IDs
    expect($deliveries[0]->baby_patient_id)->not->toBe($deliveries[1]->baby_patient_id);

    $twinA = Patient::find($deliveries[0]->baby_patient_id);
    $twinB = Patient::find($deliveries[1]->baby_patient_id);

    expect($twinA->gender)->toBe('male');
    expect($twinB->gender)->toBe('female');
    expect($twinA->mother_patient_id)->toBe($ctx['mother']->id);
    expect($twinB->mother_patient_id)->toBe($ctx['mother']->id);
});

test('unauthorized user cannot document delivery or record newborn growth', function () {
    $ctx = deliveryTestContext();
    $unauthorizedUser = User::factory()->create();

    $response = $this->actingAs($unauthorizedUser)->post(route('maternity.deliveries.store', $ctx['visit']->id), [
        'delivered_at' => now()->toDateTimeString(),
        'delivery_mode' => 'spontaneous_vaginal',
        'baby_outcome' => 'live_birth',
        'baby_gender' => 'female',
    ]);

    $response->assertForbidden();
});
