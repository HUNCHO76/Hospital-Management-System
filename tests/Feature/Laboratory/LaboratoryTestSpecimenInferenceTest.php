<?php

use App\Models\LaboratoryTest;

test('resolveNhifItem automatically infers specimen requirements for ABG/VBG tests', function () {
    $doctor = userWithRole('doctor');

    $response = $this->actingAs($doctor)->getJson(route('laboratory-tests.resolve-nhif-item', [
        'item_code' => 'TEST-5026-INFER',
        'item_name' => 'Arterial Blood Gases(ABG)/ Venous Blood Gases(VBG) Analysis',
    ]));

    $response->assertOk();

    $test = LaboratoryTest::where('code', 'NHIF-TEST-5026-INFER')->first();
    expect($test)->not->toBeNull()
        ->and($test->specimen_type)->toBe('whole_blood')
        ->and($test->specimen_container)->toBe('other')
        ->and($test->collection_notes)->toContain('Heparinized');
});

test('resolveNhifItem automatically infers specimen requirements for urine and blood tests', function () {
    $doctor = userWithRole('doctor');

    // Urine
    $this->actingAs($doctor)->getJson(route('laboratory-tests.resolve-nhif-item', [
        'item_code' => 'TEST-URINE',
        'item_name' => 'Routine Urinalysis',
    ]))->assertOk();

    $urineTest = LaboratoryTest::where('code', 'NHIF-TEST-URINE')->first();
    expect($urineTest->specimen_type)->toBe('urine')
        ->and($urineTest->specimen_container)->toBe('sterile_container');

    // CBC
    $this->actingAs($doctor)->getJson(route('laboratory-tests.resolve-nhif-item', [
        'item_code' => 'TEST-CBC',
        'item_name' => 'Full Blood Count (CBC)',
    ]))->assertOk();

    $cbcTest = LaboratoryTest::where('code', 'NHIF-TEST-CBC')->first();
    expect($cbcTest->specimen_type)->toBe('whole_blood')
        ->and($cbcTest->specimen_container)->toBe('edta_tube');

    // Fallback for unknown tests
    $this->actingAs($doctor)->getJson(route('laboratory-tests.resolve-nhif-item', [
        'item_code' => 'TEST-UNKNOWN',
        'item_name' => 'Novel Diagnostic Assay',
    ]))->assertOk();

    $unknownTest = LaboratoryTest::where('code', 'NHIF-TEST-UNKNOWN')->first();
    expect($unknownTest->specimen_type)->toBe('other')
        ->and($unknownTest->specimen_container)->toBe('other');
});

test('laboratory test specimen requirement can be updated with redirect_to preserved', function () {
    $admin = userWithRole('hospital-admin');
    $test = activeLaboratoryTest([
        'code' => 'SPEC-TEST-1',
        'name' => 'Custom Assay',
        'specimen_type' => 'other',
        'specimen_container' => 'other',
    ]);

    $response = $this->actingAs($admin)->patch(route('laboratory-tests.update', $test), [
        'specimen_type' => 'serum',
        'specimen_container' => 'plain_tube',
        'collection_notes' => 'Collect in yellow top SST tube',
        'redirect_to' => '/laboratory/collection/31',
    ]);

    $response->assertRedirect('/laboratory/collection/31');

    $test->refresh();
    expect($test->specimen_type)->toBe('serum')
        ->and($test->specimen_container)->toBe('plain_tube')
        ->and($test->collection_notes)->toBe('Collect in yellow top SST tube');
});

test('laboratory order with inferred ABG/VBG test can register specimen cleanly', function () {
    $test = activeLaboratoryTest([
        'code' => 'NHIF-5026',
        'name' => 'Arterial Blood Gases(ABG)/ Venous Blood Gases(VBG) Analysis',
        'specimen_type' => 'whole_blood',
        'specimen_container' => 'other',
    ]);

    $ctx = laboratoryOrderReadyForSampling([$test]);
    $order = $ctx['order'];
    $item = $order->items->sole();

    $nurse = userWithRole('nurse');

    $response = $this->actingAs($nurse)->post(route('laboratory.samples.store', $order), [
        'item_ids' => [$item->id],
        'specimen_type' => 'whole_blood',
        'container_type' => 'other',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertDatabaseHas('laboratory_samples', [
        'laboratory_order_id' => $order->id,
        'specimen_type' => 'whole_blood',
        'container_type' => 'other',
    ]);
});
