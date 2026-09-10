<?php

use App\Models\LaboratoryReport;
use App\Models\LaboratoryResult;
use App\Services\LaboratoryReportService;
use App\Services\LaboratoryResultService;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency protection (spec Part 7): row-locking inside
 * DB::transaction() must ensure that when two users attempt to release the
 * same result, only one succeeds and the other receives a controlled
 * "already released" rejection rather than a duplicate release.
 */
test('only one of several back-to-back attempts to release the same result succeeds', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = pendingVerificationForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');
    $request = requestAs($supervisor);
    $service = app(LaboratoryResultService::class);

    $result = $service->startReview($ctx['result'], $request);
    $result = $service->verifyResult($result, [], $request);
    $result = $service->authorizeResult($result, $request);

    $successes = 0;
    $rejections = 0;
    $messages = [];

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->release($result->fresh(), [], $request);
            $successes++;
        } catch (ValidationException $e) {
            $rejections++;
            $messages[] = $e->validator->errors()->first('status');
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
    expect($messages)->each->toBe('This result has already been released.');
    expect($result->fresh()->status)->toBe(LaboratoryResult::STATUS_RELEASED);
});

test('regenerating a report for the same order concurrently never produces two active versions', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'serum', 'result_type' => 'numeric']);
    $ctx = releasedForItem($test);
    $request = requestAs($ctx['supervisor']);
    $service = app(LaboratoryReportService::class);
    $order = $ctx['result']->item->laboratoryOrder;

    for ($i = 0; $i < 3; $i++) {
        $service->generateForOrder($order, $request);
    }

    $activeCount = LaboratoryReport::where('laboratory_order_id', $order->id)->where('status', LaboratoryReport::STATUS_ACTIVE)->count();
    $totalCount = LaboratoryReport::where('laboratory_order_id', $order->id)->count();

    expect($activeCount)->toBe(1);
    expect($totalCount)->toBe(4); // original + 3 regenerations
    expect(LaboratoryReport::where('laboratory_order_id', $order->id)->max('version'))->toBe(4);
});
