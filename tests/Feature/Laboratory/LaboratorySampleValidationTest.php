<?php

use App\Models\LaboratoryOrderItem;
use App\Services\LaboratoryOrderService;
use App\Services\LaboratorySampleService;
use Illuminate\Validation\ValidationException;

test('a specimen cannot be registered against a cancelled order', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'urine']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $request = requestAs($ctx['doctor']['user']);
    $item = $ctx['order']->items->sole();

    app(LaboratoryOrderService::class)->cancel($ctx['order'], 'No longer needed', $request);

    expect(fn () => app(LaboratorySampleService::class)->create($ctx['order']->fresh(), [
        'item_ids' => [$item->id],
        'specimen_type' => 'urine',
    ], $request))->toThrow(ValidationException::class);
});

test('a specimen cannot be registered for a cancelled item', function () {
    $testA = activeLaboratoryTest(['specimen_type' => 'urine']);
    $testB = activeLaboratoryTest(['specimen_type' => 'urine']);
    $ctx = laboratoryOrderReadyForSampling([$testA, $testB]);
    $request = requestAs($ctx['doctor']['user']);
    $itemA = $ctx['order']->items->firstWhere('laboratory_test_id', $testA->id);

    $itemA->update(['status' => LaboratoryOrderItem::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancellation_reason' => 'x']);

    expect(fn () => app(LaboratorySampleService::class)->create($ctx['order']->fresh(), [
        'item_ids' => [$itemA->id],
        'specimen_type' => 'urine',
    ], $request))->toThrow(ValidationException::class);
});

test('a specimen registration is rejected with an empty item list over HTTP', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'urine']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $nurse = userWithRole('nurse');

    $this->actingAs($nurse)->post(route('laboratory.samples.store', $ctx['order']), [
        'item_ids' => [],
        'specimen_type' => 'urine',
    ])->assertSessionHasErrors('item_ids');

    $this->assertDatabaseCount('laboratory_samples', 0);
});

test('a specimen registration is rejected with an invalid specimen type over HTTP', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'urine']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $item = $ctx['order']->items->sole();
    $nurse = userWithRole('nurse');

    $this->actingAs($nurse)->post(route('laboratory.samples.store', $ctx['order']), [
        'item_ids' => [$item->id],
        'specimen_type' => 'not-a-real-specimen',
    ])->assertSessionHasErrors('specimen_type');
});

test('collection is rejected without a collection reason field but succeeds without one since it is optional', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'urine']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $item = $ctx['order']->items->sole();
    $nurse = userWithRole('nurse');

    $this->actingAs($nurse)->post(route('laboratory.samples.store', $ctx['order']), [
        'item_ids' => [$item->id],
        'specimen_type' => 'urine',
    ])->assertRedirect();

    $sample = $ctx['order']->fresh()->samples->sole();

    $this->actingAs($nurse)->post(route('laboratory.samples.collect', $sample), [])->assertRedirect();

    expect($sample->fresh()->status)->toBe('collected');
});

test('rejection without a reason is rejected over HTTP', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'urine']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $item = $ctx['order']->items->sole();
    $request = requestAs($ctx['doctor']['user']);

    $sample = app(LaboratorySampleService::class)->create($ctx['order'], ['item_ids' => [$item->id], 'specimen_type' => 'urine'], $request);
    $sample = app(LaboratorySampleService::class)->collect($sample, [], $request);

    $technician = userWithRole('laboratory-technician');

    $this->actingAs($technician)->post(route('laboratory.samples.reject', $sample), [])->assertSessionHasErrors('rejection_reason');
});

test('rejection with an "other" reason requires notes', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'urine']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $item = $ctx['order']->items->sole();
    $request = requestAs($ctx['doctor']['user']);

    $sample = app(LaboratorySampleService::class)->create($ctx['order'], ['item_ids' => [$item->id], 'specimen_type' => 'urine'], $request);
    $sample = app(LaboratorySampleService::class)->collect($sample, [], $request);

    $technician = userWithRole('laboratory-technician');

    $this->actingAs($technician)
        ->post(route('laboratory.samples.reject', $sample), ['rejection_reason' => 'other'])
        ->assertSessionHasErrors('rejection_notes');
});

test('a sample is always attached to its own order\'s patient — there is no input path to attach it to another patient', function () {
    $testA = activeLaboratoryTest(['specimen_type' => 'urine']);
    $testB = activeLaboratoryTest(['specimen_type' => 'urine']);
    $ctxA = laboratoryOrderReadyForSampling([$testA]);
    $ctxB = laboratoryOrderReadyForSampling([$testB]);
    $itemA = $ctxA['order']->items->sole();

    // create() only ever accepts a LaboratoryOrder and item_ids belonging to
    // it — patient_id is always derived server-side from the order's own
    // encounter (LaboratorySampleService::create()), never accepted as
    // client input, so a sample can never be misattributed to another
    // patient regardless of what a caller submits.
    $sample = app(LaboratorySampleService::class)->create($ctxA['order'], ['item_ids' => [$itemA->id], 'specimen_type' => 'urine'], requestAs($ctxA['doctor']['user']));

    expect($sample->patient_id)->toBe($ctxA['patient']->id);
    expect($sample->patient_id)->not->toBe($ctxB['patient']->id);

    // The other patient's order cannot even see items that aren't its own —
    // attempting to register a sample against orderB using orderA's item id
    // is rejected the same way a cancelled/foreign item is (spec's
    // belongs-to-this-order check in create()).
    expect(fn () => app(LaboratorySampleService::class)->create($ctxB['order'], [
        'item_ids' => [$itemA->id],
        'specimen_type' => 'urine',
    ], requestAs($ctxB['doctor']['user'])))->toThrow(ValidationException::class);
});

test('invalid status transitions are rejected by the service regardless of frontend state', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'urine']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $item = $ctx['order']->items->sole();
    $request = requestAs($ctx['doctor']['user']);

    $sample = app(LaboratorySampleService::class)->create($ctx['order'], ['item_ids' => [$item->id], 'specimen_type' => 'urine'], $request);

    // PENDING_COLLECTION -> RECEIVED is never valid.
    expect(fn () => app(LaboratorySampleService::class)->receive($sample, $request))->toThrow(ValidationException::class);

    // PENDING_COLLECTION -> LABELLED is never valid.
    expect(fn () => app(LaboratorySampleService::class)->label($sample, $request))->toThrow(ValidationException::class);

    $sample = app(LaboratorySampleService::class)->collect($sample, [], $request);
    $sample = app(LaboratorySampleService::class)->label($sample, $request);
    $sample = app(LaboratorySampleService::class)->receive($sample, $request);

    // RECEIVED -> RECEIVED (double receipt) is never valid.
    expect(fn () => app(LaboratorySampleService::class)->receive($sample, $request))->toThrow(ValidationException::class);

    // RECEIVED -> REJECTED is valid as of Phase 4 Step 32 (rejection found
    // during processing) — see LaboratoryProcessingTest/EndToEndTest for
    // full mid-processing-rejection coverage.
    $sample = app(LaboratorySampleService::class)->reject($sample, ['rejection_reason' => 'other', 'rejection_notes' => 'x'], $request);
    expect($sample->status)->toBe('rejected');

    // REJECTED -> RECEIVED is never valid (recollection is a distinct path).
    expect(fn () => app(LaboratorySampleService::class)->receive($sample, $request))->toThrow(ValidationException::class);
});
