<?php

use App\Models\Hospital;
use App\Models\LaboratoryBench;
use App\Models\LaboratoryProcessing;
use App\Models\Staff;
use App\Models\User;

test('a guest is redirected to login for every processing route', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood']);
    $ctx = receivedSampleReadyForProcessing([$test]);
    $record = LaboratoryProcessing::where('laboratory_sample_id', $ctx['sample']->id)->sole();

    $this->get(route('laboratory.processing.index'))->assertRedirect(route('login'));
    $this->get(route('laboratory.processing.show', $ctx['sample']))->assertRedirect(route('login'));
    $this->post(route('laboratory.processing.accept', $ctx['sample']))->assertRedirect(route('login'));
    $this->post(route('laboratory.processing.start', $record))->assertRedirect(route('login'));
});

test('a receptionist has no laboratory processing authority at all', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'urine']);
    $ctx = receivedSampleReadyForProcessing([$test]);
    $record = LaboratoryProcessing::where('laboratory_sample_id', $ctx['sample']->id)->sole();
    $receptionist = userWithRole('receptionist');

    $this->actingAs($receptionist)->get(route('laboratory.processing.index'))->assertForbidden();
    $this->actingAs($receptionist)->get(route('laboratory.processing.show', $ctx['sample']))->assertForbidden();
    $this->actingAs($receptionist)->post(route('laboratory.processing.accept', $ctx['sample']))->assertForbidden();
    $this->actingAs($receptionist)->post(route('laboratory.processing.start', $record))->assertForbidden();
});

test('a clinician orders tests but cannot process samples', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'sputum']);
    $ctx = receivedSampleReadyForProcessing([$test]);
    $record = LaboratoryProcessing::where('laboratory_sample_id', $ctx['sample']->id)->sole();

    $this->actingAs($ctx['doctor']['user'])->get(route('laboratory.processing.index'))->assertOk();
    $this->actingAs($ctx['doctor']['user'])->post(route('laboratory.processing.accept', $ctx['sample']))->assertForbidden();
    $this->actingAs($ctx['doctor']['user'])->post(route('laboratory.processing.start', $record))->assertForbidden();
});

test('a laboratory technician can accept, assign, start, and complete processing', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'plasma']);
    $ctx = receivedSampleReadyForProcessing([$test]);
    $technician = userWithRole('laboratory-technician');
    $bench = LaboratoryBench::factory()->create();

    $this->actingAs($technician)->post(route('laboratory.processing.accept', $ctx['sample']))->assertRedirect();
    $record = LaboratoryProcessing::where('laboratory_sample_id', $ctx['sample']->id)->sole();

    $this->actingAs($technician)->post(route('laboratory.processing.assign', $record), [
        'assigned_to' => $technician->id,
        'bench_id' => $bench->id,
    ])->assertRedirect();

    $this->actingAs($technician)->post(route('laboratory.processing.start', $record->fresh()))->assertRedirect();
    $this->actingAs($technician)->post(route('laboratory.processing.complete', $record->fresh()))->assertRedirect();

    expect($record->fresh()->status)->toBe('result_pending');
});

test('a nurse has no processing permissions', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'tissue']);
    $ctx = receivedSampleReadyForProcessing([$test]);
    $record = LaboratoryProcessing::where('laboratory_sample_id', $ctx['sample']->id)->sole();
    $nurse = userWithRole('nurse');

    $this->actingAs($nurse)->post(route('laboratory.processing.accept', $ctx['sample']))->assertForbidden();
    $this->actingAs($nurse)->post(route('laboratory.processing.start', $record))->assertForbidden();
});

test('a hospital-admin can act on processing within their own hospital but not another', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    [$sampleA, $sampleB] = [
        receivedSampleForHospital($hospitalA, activeLaboratoryTest(['specimen_type' => 'whole_blood'])),
        receivedSampleForHospital($hospitalB, activeLaboratoryTest(['specimen_type' => 'whole_blood'])),
    ];

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->get(route('laboratory.processing.show', $sampleA))->assertOk();
    $this->actingAs($adminA)->get(route('laboratory.processing.show', $sampleB))->assertForbidden();

    $this->actingAs($adminA)->post(route('laboratory.processing.accept', $sampleA))->assertRedirect();
    $this->actingAs($adminA)->post(route('laboratory.processing.accept', $sampleB))->assertForbidden();
});

test('a super-admin bypasses laboratory-processing permission checks and hospital scoping', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'csf']);
    $ctx = receivedSampleReadyForProcessing([$test]);
    $user = userWithRole('super-admin');

    $this->actingAs($user)->post(route('laboratory.processing.accept', $ctx['sample']))->assertRedirect();

    expect($ctx['sample']->fresh()->processingRecords->every(fn ($r) => $r->status === 'accepted'))->toBeTrue();
});
