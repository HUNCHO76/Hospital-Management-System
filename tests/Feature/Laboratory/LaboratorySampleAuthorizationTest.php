<?php

use App\Models\Hospital;
use App\Models\Staff;
use App\Models\User;
use App\Services\LaboratorySampleService;

test('a guest is redirected to login for every sample route', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $item = $ctx['order']->items->sole();
    $sample = app(LaboratorySampleService::class)->create($ctx['order'], ['item_ids' => [$item->id], 'specimen_type' => 'whole_blood'], requestAs($ctx['doctor']['user']));

    $this->get(route('laboratory.collection.index'))->assertRedirect(route('login'));
    $this->get(route('laboratory.collection.show', $ctx['order']))->assertRedirect(route('login'));
    $this->get(route('laboratory.samples.show', $sample))->assertRedirect(route('login'));
    $this->post(route('laboratory.samples.collect', $sample))->assertRedirect(route('login'));
});

test('a receptionist has no laboratory-samples permissions at all', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'urine']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $item = $ctx['order']->items->sole();
    $sample = app(LaboratorySampleService::class)->create($ctx['order'], ['item_ids' => [$item->id], 'specimen_type' => 'urine'], requestAs($ctx['doctor']['user']));

    $receptionist = userWithRole('receptionist');

    $this->actingAs($receptionist)->get(route('laboratory.collection.index'))->assertForbidden();
    $this->actingAs($receptionist)->get(route('laboratory.collection.show', $ctx['order']))->assertForbidden();
    $this->actingAs($receptionist)->get(route('laboratory.samples.show', $sample))->assertForbidden();
    $this->actingAs($receptionist)->post(route('laboratory.samples.collect', $sample))->assertForbidden();
});

test('a doctor cannot register or collect a physical sample — that is laboratory/phlebotomy staff\'s job', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $item = $ctx['order']->items->sole();
    $doctor = $ctx['doctor']['user'];

    $this->actingAs($doctor)->post(route('laboratory.samples.store', $ctx['order']), [
        'item_ids' => [$item->id],
        'specimen_type' => 'whole_blood',
    ])->assertForbidden();

    $this->assertDatabaseCount('laboratory_samples', 0);

    $sample = app(LaboratorySampleService::class)->create($ctx['order'], ['item_ids' => [$item->id], 'specimen_type' => 'whole_blood'], requestAs($doctor));

    $this->actingAs($doctor)->post(route('laboratory.samples.collect', $sample))->assertForbidden();
    expect($sample->fresh()->status)->toBe('pending_collection');
});

test('a nurse can register and collect a specimen but cannot receive or reject one', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'sputum']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $item = $ctx['order']->items->sole();
    $nurse = userWithRole('nurse');

    $this->actingAs($nurse)->post(route('laboratory.samples.store', $ctx['order']), [
        'item_ids' => [$item->id],
        'specimen_type' => 'sputum',
    ])->assertRedirect();

    $sample = $ctx['order']->fresh()->samples->sole();

    $this->actingAs($nurse)->post(route('laboratory.samples.collect', $sample))->assertRedirect();

    $this->actingAs($nurse)->post(route('laboratory.samples.receive', $sample->fresh()))->assertForbidden();
    $this->actingAs($nurse)->post(route('laboratory.samples.reject', $sample->fresh()), ['rejection_reason' => 'clotted'])->assertForbidden();
});

test('a laboratory technician can receive and reject a labelled/collected specimen', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'stool']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $item = $ctx['order']->items->sole();
    $request = requestAs($ctx['doctor']['user']);
    $sample = app(LaboratorySampleService::class)->create($ctx['order'], ['item_ids' => [$item->id], 'specimen_type' => 'stool'], $request);
    $sample = app(LaboratorySampleService::class)->collect($sample, [], $request);
    $sample = app(LaboratorySampleService::class)->label($sample, $request);

    $technician = userWithRole('laboratory-technician');

    $this->actingAs($technician)->post(route('laboratory.samples.receive', $sample))->assertRedirect();
    expect($sample->fresh()->status)->toBe('received');
});

test('a hospital-admin can act on samples within their own hospital but not another', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $testA = activeLaboratoryTest(['specimen_type' => 'whole_blood']);
    $testB = activeLaboratoryTest(['specimen_type' => 'whole_blood']);

    [$orderA, $doctorA] = laboratorySamplingOrderFor($hospitalA, $testA);
    [$orderB, $doctorB] = laboratorySamplingOrderFor($hospitalB, $testB);

    $itemA = $orderA->items->sole();
    $itemB = $orderB->items->sole();
    $sampleA = app(LaboratorySampleService::class)->create($orderA, ['item_ids' => [$itemA->id], 'specimen_type' => 'whole_blood'], requestAs($doctorA['user']));
    $sampleB = app(LaboratorySampleService::class)->create($orderB, ['item_ids' => [$itemB->id], 'specimen_type' => 'whole_blood'], requestAs($doctorB['user']));

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->get(route('laboratory.samples.show', $sampleA))->assertOk();
    $this->actingAs($adminA)->get(route('laboratory.samples.show', $sampleB))->assertForbidden();

    $this->actingAs($adminA)->post(route('laboratory.samples.collect', $sampleA))->assertRedirect();
    $this->actingAs($adminA)->post(route('laboratory.samples.collect', $sampleB))->assertForbidden();
});

test('a super-admin bypasses laboratory-samples permission checks and hospital scoping', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'csf']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $item = $ctx['order']->items->sole();
    $user = userWithRole('super-admin');

    $this->actingAs($user)->post(route('laboratory.samples.store', $ctx['order']), [
        'item_ids' => [$item->id],
        'specimen_type' => 'csf',
    ])->assertRedirect();

    $this->assertDatabaseCount('laboratory_samples', 1);
});
