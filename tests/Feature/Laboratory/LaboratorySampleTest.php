<?php

use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderItem;
use App\Models\LaboratorySample;
use App\Services\LaboratorySampleService;
use Illuminate\Validation\ValidationException;

test('a specimen can be registered against multiple compatible tests', function () {
    $cbc = activeLaboratoryTest(['code' => 'CBC', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube']);
    $mal = activeLaboratoryTest(['code' => 'MAL', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube']);
    $ctx = laboratoryOrderReadyForSampling([$cbc, $mal]);
    $request = requestAs($ctx['doctor']['user']);

    $sample = app(LaboratorySampleService::class)->create($ctx['order'], [
        'item_ids' => $ctx['order']->items->pluck('id')->all(),
        'specimen_type' => 'whole_blood',
        'container_type' => 'edta_tube',
    ], $request);

    expect($sample->sample_number)->toStartWith('SMP-'.now()->year.'-');
    expect($sample->status)->toBe(LaboratorySample::STATUS_PENDING_COLLECTION);
    expect($sample->items)->toHaveCount(2);
    expect($ctx['order']->fresh()->status)->toBe(LaboratoryOrder::STATUS_SAMPLE_PENDING);
    $ctx['order']->fresh()->items->each(fn (LaboratoryOrderItem $item) => expect($item->status)->toBe(LaboratoryOrderItem::STATUS_SAMPLE_PENDING));
});

test('creating a specimen records an audit event without leaking clinical content', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'urine']);
    $ctx = laboratoryOrderReadyForSampling([$test]);

    $sample = app(LaboratorySampleService::class)->create($ctx['order'], [
        'item_ids' => $ctx['order']->items->pluck('id')->all(),
        'specimen_type' => 'urine',
    ], requestAs($ctx['doctor']['user']));

    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_sample_created', 'auditable_id' => $sample->id]);
});

test('incompatible tests require separate specimens', function () {
    $cbc = activeLaboratoryTest(['code' => 'CBC', 'specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube']);
    $crea = activeLaboratoryTest(['code' => 'CREA', 'specimen_type' => 'serum', 'specimen_container' => 'plain_tube']);
    $ctx = laboratoryOrderReadyForSampling([$cbc, $crea]);
    $request = requestAs($ctx['doctor']['user']);

    expect(fn () => app(LaboratorySampleService::class)->create($ctx['order'], [
        'item_ids' => $ctx['order']->items->pluck('id')->all(),
        'specimen_type' => 'whole_blood',
        'container_type' => 'edta_tube',
    ], $request))->toThrow(ValidationException::class);
});

test('a test with no catalog specimen configured cannot be sampled', function () {
    $test = activeLaboratoryTest(['specimen_type' => null, 'specimen_container' => null]);
    $ctx = laboratoryOrderReadyForSampling([$test]);

    expect(fn () => app(LaboratorySampleService::class)->create($ctx['order'], [
        'item_ids' => $ctx['order']->items->pluck('id')->all(),
        'specimen_type' => 'whole_blood',
    ], requestAs($ctx['doctor']['user'])))->toThrow(ValidationException::class);
});

test('a test already covered by an active specimen cannot be registered again', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'urine']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $request = requestAs($ctx['doctor']['user']);
    $itemIds = $ctx['order']->items->pluck('id')->all();

    app(LaboratorySampleService::class)->create($ctx['order'], ['item_ids' => $itemIds, 'specimen_type' => 'urine'], $request);

    expect(fn () => app(LaboratorySampleService::class)->create($ctx['order'], ['item_ids' => $itemIds, 'specimen_type' => 'urine'], $request))
        ->toThrow(ValidationException::class);
});

test('collecting a specimen stamps the actor and timestamp server-side and rolls up item/order status', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'sputum']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $request = requestAs($ctx['doctor']['user']);
    $item = $ctx['order']->items->sole();

    $sample = app(LaboratorySampleService::class)->create($ctx['order'], ['item_ids' => [$item->id], 'specimen_type' => 'sputum'], $request);
    $sample = app(LaboratorySampleService::class)->collect($sample, ['collection_site' => 'Left arm'], $request);

    expect($sample->status)->toBe(LaboratorySample::STATUS_COLLECTED);
    expect($sample->collected_by)->toBe($ctx['doctor']['user']->id);
    expect($sample->collected_at)->not->toBeNull();
    expect($item->fresh()->status)->toBe(LaboratoryOrderItem::STATUS_SAMPLE_COLLECTED);
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_sample_collected', 'auditable_id' => $sample->id]);
});

test('labelling transitions to labelled once, and reprints never change the sample number or status', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'stool']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $request = requestAs($ctx['doctor']['user']);
    $item = $ctx['order']->items->sole();

    $sample = app(LaboratorySampleService::class)->create($ctx['order'], ['item_ids' => [$item->id], 'specimen_type' => 'stool'], $request);
    $sample = app(LaboratorySampleService::class)->collect($sample, [], $request);
    $originalNumber = $sample->sample_number;

    $sample = app(LaboratorySampleService::class)->label($sample, $request);
    expect($sample->status)->toBe(LaboratorySample::STATUS_LABELLED);
    $labelledAt = $sample->labelled_at;
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_sample_labelled', 'auditable_id' => $sample->id]);

    $sample = app(LaboratorySampleService::class)->label($sample, $request);
    expect($sample->status)->toBe(LaboratorySample::STATUS_LABELLED);
    expect($sample->sample_number)->toBe($originalNumber);
    expect($sample->labelled_at->equalTo($labelledAt))->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_sample_label_reprinted', 'auditable_id' => $sample->id]);
});

test('a specimen cannot be received before it is labelled', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'swab']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $request = requestAs($ctx['doctor']['user']);
    $item = $ctx['order']->items->sole();

    $sample = app(LaboratorySampleService::class)->create($ctx['order'], ['item_ids' => [$item->id], 'specimen_type' => 'swab'], $request);
    $sample = app(LaboratorySampleService::class)->collect($sample, [], $request);

    expect(fn () => app(LaboratorySampleService::class)->receive($sample, $request))->toThrow(ValidationException::class);
});

test('receiving a labelled specimen rolls the order up to received once every item is received', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'csf']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $request = requestAs($ctx['doctor']['user']);
    $item = $ctx['order']->items->sole();

    $sample = app(LaboratorySampleService::class)->create($ctx['order'], ['item_ids' => [$item->id], 'specimen_type' => 'csf'], $request);
    $sample = app(LaboratorySampleService::class)->collect($sample, [], $request);
    $sample = app(LaboratorySampleService::class)->label($sample, $request);
    $sample = app(LaboratorySampleService::class)->receive($sample, $request);

    expect($sample->status)->toBe(LaboratorySample::STATUS_RECEIVED);
    expect($item->fresh()->status)->toBe(LaboratoryOrderItem::STATUS_RECEIVED);
    expect($ctx['order']->fresh()->status)->toBe(LaboratoryOrder::STATUS_RECEIVED);
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_order_status_changed', 'auditable_id' => $ctx['order']->id]);
});

test('a partially collected order never reports as fully collected', function () {
    $testA = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'specimen_container' => 'edta_tube']);
    $testB = activeLaboratoryTest(['specimen_type' => 'urine']);
    $ctx = laboratoryOrderReadyForSampling([$testA, $testB]);
    $request = requestAs($ctx['doctor']['user']);
    $itemA = $ctx['order']->items->firstWhere('laboratory_test_id', $testA->id);

    app(LaboratorySampleService::class)->create($ctx['order'], [
        'item_ids' => [$itemA->id],
        'specimen_type' => 'whole_blood',
        'container_type' => 'edta_tube',
    ], $request);

    expect($ctx['order']->fresh()->status)->toBe(LaboratoryOrder::STATUS_SAMPLE_PENDING);
});

test('rejecting a specimen requires a reason and reverts its items to needing a new specimen', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'body_fluid']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $request = requestAs($ctx['doctor']['user']);
    $item = $ctx['order']->items->sole();

    $sample = app(LaboratorySampleService::class)->create($ctx['order'], ['item_ids' => [$item->id], 'specimen_type' => 'body_fluid'], $request);
    $sample = app(LaboratorySampleService::class)->collect($sample, [], $request);
    $sample = app(LaboratorySampleService::class)->reject($sample, ['rejection_reason' => 'clotted'], $request);

    expect($sample->status)->toBe(LaboratorySample::STATUS_REJECTED);
    expect($sample->rejection_reason)->toBe('clotted');
    expect($item->fresh()->status)->toBe(LaboratoryOrderItem::STATUS_ORDERED);
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_sample_rejected', 'auditable_id' => $sample->id]);
});

test('a rejected specimen is never mutated or deleted, and a recollection preserves the link', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'tissue']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $request = requestAs($ctx['doctor']['user']);
    $item = $ctx['order']->items->sole();

    $rejected = app(LaboratorySampleService::class)->create($ctx['order'], ['item_ids' => [$item->id], 'specimen_type' => 'tissue'], $request);
    $rejected = app(LaboratorySampleService::class)->collect($rejected, [], $request);
    $rejected = app(LaboratorySampleService::class)->reject($rejected, ['rejection_reason' => 'contaminated'], $request);
    $rejectedNumber = $rejected->sample_number;

    $recollection = app(LaboratorySampleService::class)->recollect($rejected, [], $request);

    expect($rejected->fresh()->status)->toBe(LaboratorySample::STATUS_REJECTED);
    expect($rejected->fresh()->sample_number)->toBe($rejectedNumber);
    expect($recollection->recollection_of_sample_id)->toBe($rejected->id);
    expect($recollection->status)->toBe(LaboratorySample::STATUS_PENDING_COLLECTION);
    expect($recollection->sample_number)->not->toBe($rejectedNumber);
    expect($item->fresh()->status)->toBe(LaboratoryOrderItem::STATUS_SAMPLE_PENDING);
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_sample_recollection_created', 'auditable_id' => $recollection->id]);
});

test('only a rejected specimen can be recollected', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'other']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $request = requestAs($ctx['doctor']['user']);
    $item = $ctx['order']->items->sole();

    $sample = app(LaboratorySampleService::class)->create($ctx['order'], ['item_ids' => [$item->id], 'specimen_type' => 'other'], $request);

    expect(fn () => app(LaboratorySampleService::class)->recollect($sample, [], $request))->toThrow(ValidationException::class);
});

test('a pending specimen can be cancelled but never a collected one', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'plasma']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $request = requestAs($ctx['doctor']['user']);
    $item = $ctx['order']->items->sole();

    $sample = app(LaboratorySampleService::class)->create($ctx['order'], ['item_ids' => [$item->id], 'specimen_type' => 'plasma'], $request);
    $sample = app(LaboratorySampleService::class)->cancel($sample, 'Patient unavailable', $request);

    expect($sample->status)->toBe(LaboratorySample::STATUS_CANCELLED);
    expect($item->fresh()->status)->toBe(LaboratoryOrderItem::STATUS_ORDERED);
    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_sample_cancelled', 'auditable_id' => $sample->id]);

    $collectedTest = activeLaboratoryTest(['specimen_type' => 'plasma']);
    $ctx2 = laboratoryOrderReadyForSampling([$collectedTest]);
    $request2 = requestAs($ctx2['doctor']['user']);
    $item2 = $ctx2['order']->items->sole();
    $collected = app(LaboratorySampleService::class)->create($ctx2['order'], ['item_ids' => [$item2->id], 'specimen_type' => 'plasma'], $request2);
    $collected = app(LaboratorySampleService::class)->collect($collected, [], $request2);

    expect(fn () => app(LaboratorySampleService::class)->cancel($collected, 'too late', $request2))->toThrow(ValidationException::class);
});
