<?php

use App\Models\LaboratoryBench;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderItem;
use App\Models\LaboratoryProcessing;
use App\Services\LaboratoryProcessingService;
use App\Services\LaboratorySampleService;
use Illuminate\Validation\ValidationException;

test('receiving a specimen automatically creates a processing record per item', function () {
    $testA = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube']);
    $testB = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube']);
    $ctx = receivedSampleReadyForProcessing([$testA, $testB]);

    expect(LaboratoryProcessing::where('laboratory_sample_id', $ctx['sample']->id)->count())->toBe(2);
    LaboratoryProcessing::where('laboratory_sample_id', $ctx['sample']->id)->get()
        ->each(fn (LaboratoryProcessing $r) => expect($r->status)->toBe(LaboratoryProcessing::STATUS_RECEIVED));
});

test('accepting a specimen moves every one of its tests to accepted together', function () {
    $testA = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube']);
    $testB = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube']);
    $ctx = receivedSampleReadyForProcessing([$testA, $testB]);
    $request = requestAs($ctx['doctor']['user']);

    app(LaboratoryProcessingService::class)->acceptSample($ctx['sample'], $request);

    $records = LaboratoryProcessing::where('laboratory_sample_id', $ctx['sample']->id)->get();
    expect($records->every(fn (LaboratoryProcessing $r) => $r->status === LaboratoryProcessing::STATUS_ACCEPTED))->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_sample_accepted', 'auditable_id' => $ctx['sample']->id]);
});

test('a specimen cannot be accepted twice', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'urine']);
    $ctx = receivedSampleReadyForProcessing([$test]);
    $request = requestAs($ctx['doctor']['user']);

    app(LaboratoryProcessingService::class)->acceptSample($ctx['sample'], $request);

    expect(fn () => app(LaboratoryProcessingService::class)->acceptSample($ctx['sample']->fresh(), $request))
        ->toThrow(ValidationException::class);
});

test('a test can be assigned to a technician and bench, and processing can start directly from accepted', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'serum']);
    $ctx = receivedSampleReadyForProcessing([$test]);
    $request = requestAs($ctx['doctor']['user']);
    $bench = LaboratoryBench::factory()->create();
    $tech = userWithRole('laboratory-technician');

    app(LaboratoryProcessingService::class)->acceptSample($ctx['sample'], $request);
    $record = LaboratoryProcessing::where('laboratory_sample_id', $ctx['sample']->id)->sole();

    $assigned = app(LaboratoryProcessingService::class)->assign($record, ['assigned_to' => $tech->id, 'bench_id' => $bench->id], $request);
    expect($assigned->status)->toBe(LaboratoryProcessing::STATUS_ASSIGNED);
    expect($assigned->assigned_to)->toBe($tech->id);
    expect($assigned->bench_id)->toBe($bench->id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_sample_assigned', 'auditable_id' => $record->id]);

    // Assignment is optional — a fresh (unassigned) record can start directly.
    $test2 = activeLaboratoryTest(['specimen_type' => 'serum']);
    $ctx2 = receivedSampleReadyForProcessing([$test2]);
    $request2 = requestAs($ctx2['doctor']['user']);
    app(LaboratoryProcessingService::class)->acceptSample($ctx2['sample'], $request2);
    $record2 = LaboratoryProcessing::where('laboratory_sample_id', $ctx2['sample']->id)->sole();

    $started = app(LaboratoryProcessingService::class)->start($record2, [], $request2);
    expect($started->status)->toBe(LaboratoryProcessing::STATUS_IN_PROGRESS);
});

test('starting processing rolls the item and order status up to in_progress', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'sputum']);
    $ctx = receivedSampleReadyForProcessing([$test]);
    $request = requestAs($ctx['doctor']['user']);
    $item = $ctx['order']->items->sole();

    app(LaboratoryProcessingService::class)->acceptSample($ctx['sample'], $request);
    $record = LaboratoryProcessing::where('laboratory_sample_id', $ctx['sample']->id)->sole();
    app(LaboratoryProcessingService::class)->start($record, ['processing_notes' => 'Centrifuged.'], $request);

    expect($item->fresh()->status)->toBe(LaboratoryOrderItem::STATUS_IN_PROGRESS);
    expect($ctx['order']->fresh()->status)->toBe(LaboratoryOrder::STATUS_IN_PROGRESS);
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_sample_processing_started', 'auditable_id' => $record->id]);
});

test('completing processing immediately advances to result_pending and rolls up item/order status', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'stool']);
    $ctx = receivedSampleReadyForProcessing([$test]);
    $request = requestAs($ctx['doctor']['user']);
    $item = $ctx['order']->items->sole();

    app(LaboratoryProcessingService::class)->acceptSample($ctx['sample'], $request);
    $record = LaboratoryProcessing::where('laboratory_sample_id', $ctx['sample']->id)->sole();
    app(LaboratoryProcessingService::class)->start($record, [], $request);
    $completed = app(LaboratoryProcessingService::class)->complete($record->fresh(), ['processing_notes' => 'Done.'], $request);

    expect($completed->status)->toBe(LaboratoryProcessing::STATUS_RESULT_PENDING);
    expect($completed->completed_by)->toBe($request->user()->id);
    expect($completed->completed_at)->not->toBeNull();
    expect($item->fresh()->status)->toBe(LaboratoryOrderItem::STATUS_RESULT_PENDING);
    expect($ctx['order']->fresh()->status)->toBe(LaboratoryOrder::STATUS_RESULT_PENDING);
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_sample_processing_completed', 'auditable_id' => $record->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_processing_status_changed', 'auditable_id' => $record->id]);
});

test('a test cannot be completed twice', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'csf']);
    $ctx = receivedSampleReadyForProcessing([$test]);
    $request = requestAs($ctx['doctor']['user']);

    app(LaboratoryProcessingService::class)->acceptSample($ctx['sample'], $request);
    $record = LaboratoryProcessing::where('laboratory_sample_id', $ctx['sample']->id)->sole();
    app(LaboratoryProcessingService::class)->start($record, [], $request);
    app(LaboratoryProcessingService::class)->complete($record->fresh(), [], $request);

    expect(fn () => app(LaboratoryProcessingService::class)->complete($record->fresh(), [], $request))
        ->toThrow(ValidationException::class);
});

test('a test can be transferred between benches without changing its status', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'body_fluid']);
    $ctx = receivedSampleReadyForProcessing([$test]);
    $request = requestAs($ctx['doctor']['user']);
    $benchA = LaboratoryBench::factory()->create();
    $benchB = LaboratoryBench::factory()->create();
    $tech = userWithRole('laboratory-technician');

    app(LaboratoryProcessingService::class)->acceptSample($ctx['sample'], $request);
    $record = LaboratoryProcessing::where('laboratory_sample_id', $ctx['sample']->id)->sole();
    $record = app(LaboratoryProcessingService::class)->assign($record, ['assigned_to' => $tech->id, 'bench_id' => $benchA->id], $request);
    $record = app(LaboratoryProcessingService::class)->start($record, [], $request);

    $transferred = app(LaboratoryProcessingService::class)->transfer($record, ['bench_id' => $benchB->id, 'transfer_reason' => 'Better equipment'], $request);

    expect($transferred->bench_id)->toBe($benchB->id);
    expect($transferred->status)->toBe(LaboratoryProcessing::STATUS_IN_PROGRESS);
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_sample_transferred', 'auditable_id' => $record->id]);
});

test('partial processing: a sample with two tests keeps the order in_progress until both complete', function () {
    $testA = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube']);
    $testB = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube']);
    $ctx = receivedSampleReadyForProcessing([$testA, $testB]);
    $request = requestAs($ctx['doctor']['user']);

    app(LaboratoryProcessingService::class)->acceptSample($ctx['sample'], $request);
    $records = LaboratoryProcessing::where('laboratory_sample_id', $ctx['sample']->id)->get();
    $recordA = $records->first();
    $recordB = $records->last();

    app(LaboratoryProcessingService::class)->start($recordA, [], $request);
    app(LaboratoryProcessingService::class)->start($recordB, [], $request);
    expect($ctx['order']->fresh()->status)->toBe(LaboratoryOrder::STATUS_IN_PROGRESS);

    // Completing only one of the two tests must never imply the whole order
    // is done (spec §17/§40) — the order stays in_progress.
    app(LaboratoryProcessingService::class)->complete($recordA->fresh(), [], $request);
    expect($ctx['order']->fresh()->status)->toBe(LaboratoryOrder::STATUS_IN_PROGRESS);

    app(LaboratoryProcessingService::class)->complete($recordB->fresh(), [], $request);
    expect($ctx['order']->fresh()->status)->toBe(LaboratoryOrder::STATUS_RESULT_PENDING);
});

test('multiple samples on one order each progress independently', function () {
    $testA = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube']);
    $testB = activeLaboratoryTest(['specimen_type' => 'urine']);
    $ctx = laboratoryOrderReadyForSampling([$testA, $testB]);
    $request = requestAs($ctx['doctor']['user']);
    $itemA = $ctx['order']->items->firstWhere('laboratory_test_id', $testA->id);
    $itemB = $ctx['order']->items->firstWhere('laboratory_test_id', $testB->id);

    $sampleA = app(LaboratorySampleService::class)->create($ctx['order'], [
        'item_ids' => [$itemA->id],
        'specimen_type' => 'whole_blood',
        'container_type' => 'edta_tube',
    ], $request);
    $sampleA = app(LaboratorySampleService::class)->collect($sampleA, [], $request);
    $sampleA = app(LaboratorySampleService::class)->label($sampleA, $request);
    $sampleA = app(LaboratorySampleService::class)->receive($sampleA, $request);
    app(LaboratoryProcessingService::class)->initializeForSample($sampleA, $request);

    $sampleB = app(LaboratorySampleService::class)->create($ctx['order']->fresh(), [
        'item_ids' => [$itemB->id],
        'specimen_type' => 'urine',
    ], $request);
    $sampleB = app(LaboratorySampleService::class)->collect($sampleB, [], $request);
    $sampleB = app(LaboratorySampleService::class)->label($sampleB, $request);
    $sampleB = app(LaboratorySampleService::class)->receive($sampleB, $request);
    app(LaboratoryProcessingService::class)->initializeForSample($sampleB, $request);

    // Fully process sample A's test.
    app(LaboratoryProcessingService::class)->acceptSample($sampleA, $request);
    $recordA = LaboratoryProcessing::where('laboratory_sample_id', $sampleA->id)->sole();
    app(LaboratoryProcessingService::class)->start($recordA, [], $request);
    app(LaboratoryProcessingService::class)->complete($recordA->fresh(), [], $request);

    // Sample B never even accepted.
    $recordB = LaboratoryProcessing::where('laboratory_sample_id', $sampleB->id)->sole();
    expect($recordB->status)->toBe(LaboratoryProcessing::STATUS_RECEIVED);

    // Order must reflect the least-advanced sample, not the most-advanced.
    expect($ctx['order']->fresh()->status)->toBe(LaboratoryOrder::STATUS_RECEIVED);
});
