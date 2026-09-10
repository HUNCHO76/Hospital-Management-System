<?php

use App\Models\Hospital;
use App\Models\Staff;
use App\Models\User;
use App\Services\LaboratorySampleService;

test('a hospital-admin cannot open another hospital\'s specimen by guessing its id', function () {
    seedRolesAndPermissions();

    $hospitalA = Hospital::factory()->create();
    $hospitalB = Hospital::factory()->create();
    $testA = activeLaboratoryTest(['specimen_type' => 'whole_blood']);
    $testB = activeLaboratoryTest(['specimen_type' => 'whole_blood']);

    [$orderA] = laboratorySamplingOrderFor($hospitalA, $testA);
    [$orderB, $doctorB] = laboratorySamplingOrderFor($hospitalB, $testB);

    $itemB = $orderB->items->sole();
    $sampleB = app(LaboratorySampleService::class)->create($orderB, ['item_ids' => [$itemB->id], 'specimen_type' => 'whole_blood'], requestAs($doctorB['user']));

    $adminA = User::factory()->create();
    $adminA->assignRole('hospital-admin');
    Staff::factory()->create(['user_id' => $adminA->id, 'hospital_id' => $hospitalA->id]);

    $this->actingAs($adminA)->get(route('laboratory.samples.show', $sampleB))->assertForbidden();
    $this->actingAs($adminA)->get(route('laboratory.samples.label-view', $sampleB))->assertForbidden();
    $this->actingAs($adminA)->get(route('laboratory.collection.show', $orderB))->assertForbidden();

    expect($orderA->id)->not->toBe($orderB->id);
});

test('a laboratory order cannot be registered with an item id belonging to another order', function () {
    $testA = activeLaboratoryTest(['specimen_type' => 'whole_blood']);
    $testB = activeLaboratoryTest(['specimen_type' => 'whole_blood']);
    $ctxA = laboratoryOrderReadyForSampling([$testA]);
    $ctxB = laboratoryOrderReadyForSampling([$testB]);

    $foreignItem = $ctxB['order']->items->sole();
    $nurse = userWithRole('nurse');

    $this->actingAs($nurse)->post(route('laboratory.samples.store', $ctxA['order']), [
        'item_ids' => [$foreignItem->id],
        'specimen_type' => 'whole_blood',
    ])->assertSessionHasErrors('item_ids');

    $this->assertDatabaseCount('laboratory_samples', 0);
});
