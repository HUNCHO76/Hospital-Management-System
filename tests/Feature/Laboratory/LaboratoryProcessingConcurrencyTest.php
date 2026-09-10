<?php

use App\Models\LaboratoryProcessing;
use App\Services\LaboratoryProcessingService;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency protection (spec §41-42): row-locking inside
 * DB::transaction() must prevent double-START and double-COMPLETION even
 * when several requests race against the same processing record. Mirrors
 * LaboratorySampleConcurrencyTest's repeated-attempt style.
 */
test('only one of several back-to-back attempts to accept the same specimen succeeds', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood']);
    $ctx = receivedSampleReadyForProcessing([$test]);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryProcessingService::class);

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->acceptSample($ctx['sample']->fresh(), $request);
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
});

test('only one of several back-to-back attempts to start the same processing record succeeds', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'serum']);
    $ctx = receivedSampleReadyForProcessing([$test]);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryProcessingService::class);

    $service->acceptSample($ctx['sample']->fresh(), $request);
    $record = LaboratoryProcessing::where('laboratory_sample_id', $ctx['sample']->id)->sole();

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->start($record->fresh(), [], $request);
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
    expect($record->fresh()->status)->toBe(LaboratoryProcessing::STATUS_IN_PROGRESS);
});

test('only one of several back-to-back attempts to complete the same processing record succeeds', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'plasma']);
    $ctx = receivedSampleReadyForProcessing([$test]);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryProcessingService::class);

    $service->acceptSample($ctx['sample']->fresh(), $request);
    $record = LaboratoryProcessing::where('laboratory_sample_id', $ctx['sample']->id)->sole();
    $record = $service->start($record, [], $request);

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->complete($record->fresh(), [], $request);
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
    expect($record->fresh()->status)->toBe(LaboratoryProcessing::STATUS_RESULT_PENDING);
});

test('a processing record cannot be duplicated for the same sample and item', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'urine']);
    $ctx = receivedSampleReadyForProcessing([$test]);
    $record = LaboratoryProcessing::where('laboratory_sample_id', $ctx['sample']->id)->sole();

    expect(fn () => LaboratoryProcessing::query()->create([
        'laboratory_sample_id' => $record->laboratory_sample_id,
        'laboratory_order_item_id' => $record->laboratory_order_item_id,
        'status' => LaboratoryProcessing::STATUS_RECEIVED,
    ]))->toThrow(QueryException::class);
});
