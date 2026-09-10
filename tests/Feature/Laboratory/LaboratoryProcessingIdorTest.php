<?php

use App\Models\Hospital;
use App\Models\LaboratoryBench;
use App\Models\LaboratoryProcessing;
use App\Models\Staff;
use App\Models\User;

test('a hospital-admin cannot open another hospital\'s processing record by guessing its id', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $sampleA = receivedSampleForHospital($hospitalA, activeLaboratoryTest(['specimen_type' => 'whole_blood']));
    $sampleB = receivedSampleForHospital($hospitalB, activeLaboratoryTest(['specimen_type' => 'whole_blood']));
    $recordB = LaboratoryProcessing::where('laboratory_sample_id', $sampleB->id)->sole();

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->get(route('laboratory.processing.show', $sampleB))->assertForbidden();
    $this->actingAs($adminA)->post(route('laboratory.processing.accept', $sampleB))->assertForbidden();
    $this->actingAs($adminA)->post(route('laboratory.processing.assign', $recordB), [
        'assigned_to' => $adminA->id,
    ])->assertForbidden();
    $this->actingAs($adminA)->post(route('laboratory.processing.start', $recordB))->assertForbidden();

    expect($sampleA->id)->not->toBe($sampleB->id);
    expect($recordB->fresh()->status)->toBe('received');
});

test('a hospital-admin of one hospital cannot complete or transfer another hospital\'s processing record', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();

    $sampleB = receivedSampleForHospital($hospitalB, activeLaboratoryTest(['specimen_type' => 'urine']));
    $recordB = LaboratoryProcessing::where('laboratory_sample_id', $sampleB->id)->sole();

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->post(route('laboratory.processing.start', $recordB))->assertForbidden();
    $this->actingAs($adminA)->post(route('laboratory.processing.complete', $recordB))->assertForbidden();
    $bench = LaboratoryBench::factory()->create();
    $this->actingAs($adminA)->post(route('laboratory.processing.transfer', $recordB), [
        'bench_id' => $bench->id,
        'transfer_reason' => 'test',
    ])->assertForbidden();
});
