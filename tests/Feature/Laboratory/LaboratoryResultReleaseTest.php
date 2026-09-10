<?php

use App\Models\LaboratoryProcessing;
use App\Models\LaboratoryReport;
use App\Models\LaboratoryResult;
use App\Services\LaboratoryProcessingService;
use App\Services\LaboratoryReportService;
use App\Services\LaboratoryResultService;
use App\Services\LaboratorySampleService;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

test('an authorized result can be released, which stamps release fields and generates a report', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric', 'unit' => 'g/dL']);
    $ctx = releasedForItem($test);

    expect($ctx['result']->status)->toBe(LaboratoryResult::STATUS_RELEASED);
    expect($ctx['result']->released_by)->toBe($ctx['supervisor']->id);
    expect($ctx['result']->released_at)->not->toBeNull();
    expect($ctx['result']->isReleased())->toBeTrue();

    expect($ctx['report']->status)->toBe(LaboratoryReport::STATUS_ACTIVE);
    expect($ctx['report']->version)->toBe(1);
    expect($ctx['report']->report_number)->toStartWith('LAB-RPT-');
    expect($ctx['report']->results_snapshot)->toHaveCount(1);
    expect($ctx['report']->results_snapshot[0]['value_numeric'])->toBe('13.5000');
    expect($ctx['report']->results_snapshot[0]['released_by'])->not->toBeNull();
});

test('releasing a result that is not authorized is rejected', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'serum', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $supervisor = userWithRole('laboratory-supervisor');
    $request = requestAs($supervisor);

    expect(fn () => app(LaboratoryResultService::class)->release($ctx['result'], [], $request))
        ->toThrow(ValidationException::class);
});

test('a result cannot be released twice', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'plasma', 'result_type' => 'numeric']);
    $ctx = releasedForItem($test);
    $request = requestAs($ctx['supervisor']);

    expect(fn () => app(LaboratoryResultService::class)->release($ctx['result'], [], $request))
        ->toThrow(ValidationException::class, 'This result has already been released.');
});

test('regenerating a report for the same order bumps the version and supersedes the previous one', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = releasedForItem($test);
    $request = requestAs($ctx['supervisor']);

    expect($ctx['report']->version)->toBe(1);

    $reportV2 = app(LaboratoryReportService::class)->generateForOrder($ctx['result']->item->laboratoryOrder, $request);

    expect($reportV2->version)->toBe(2);
    expect($reportV2->laboratory_order_id)->toBe($ctx['report']->laboratory_order_id);
    expect($ctx['report']->fresh()->status)->toBe(LaboratoryReport::STATUS_SUPERSEDED);
    expect($reportV2->status)->toBe(LaboratoryReport::STATUS_ACTIVE);

    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_report_reissued', 'auditable_id' => $reportV2->id]);
});

test('two tests on separate samples of one order are both released and appear together in one report, correctly grouped by sample', function () {
    $hb = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric', 'code' => 'HB']);
    $ua = activeLaboratoryTest(['specimen_type' => 'urine', 'result_type' => 'numeric', 'code' => 'UA']);
    $ctx = laboratoryOrderReadyForSampling([$hb, $ua]);
    $order = $ctx['order']->fresh('items');
    $hbItem = $order->items->firstWhere('laboratory_test_id', $hb->id);
    $uaItem = $order->items->firstWhere('laboratory_test_id', $ua->id);
    $enteringRequest = requestAs($ctx['doctor']['user']);
    $supervisor = userWithRole('laboratory-supervisor');
    $supervisorRequest = requestAs($supervisor);

    $sampleService = app(LaboratorySampleService::class);
    $processingService = app(LaboratoryProcessingService::class);
    $resultService = app(LaboratoryResultService::class);
    $reportService = app(LaboratoryReportService::class);

    foreach ([[$hbItem, 'whole_blood', '13.5'], [$uaItem, 'urine', '1.020']] as [$item, $specimenType, $value]) {
        $sample = $sampleService->create($order, ['item_ids' => [$item->id], 'specimen_type' => $specimenType], $enteringRequest);
        $sample = $sampleService->collect($sample, [], $enteringRequest);
        $sample = $sampleService->label($sample, $enteringRequest);
        $sample = $sampleService->receive($sample, $enteringRequest);
        $processingService->initializeForSample($sample, $enteringRequest);

        $processingService->acceptSample($sample->fresh(), $enteringRequest);
        $processing = LaboratoryProcessing::where('laboratory_sample_id', $sample->id)->sole();
        $processing = $processingService->start($processing, [], $enteringRequest);
        $processing = $processingService->complete($processing, [], $enteringRequest);

        $resultService->initializeForItem($processing->item, $processing->sample, $enteringRequest);
        $result = LaboratoryResult::where('laboratory_order_item_id', $item->id)->sole();

        $result = $resultService->saveDraft($result, ['value_numeric' => $value], $enteringRequest);
        $result = $resultService->submit($result, $enteringRequest);
        $result = $resultService->startReview($result, $supervisorRequest);
        $result = $resultService->verifyResult($result, [], $supervisorRequest);
        $result = $resultService->authorizeResult($result, $supervisorRequest);
        $resultService->release($result, [], $supervisorRequest);
    }

    $report = $reportService->generateForOrder($order, $supervisorRequest);

    expect($report->results_snapshot)->toHaveCount(2);
    $sampleNumbers = collect($report->results_snapshot)->pluck('sample_number')->unique();
    expect($sampleNumbers)->toHaveCount(2);

    $hbSnapshot = collect($report->results_snapshot)->firstWhere('test_code', 'HB');
    $uaSnapshot = collect($report->results_snapshot)->firstWhere('test_code', 'UA');
    expect($hbSnapshot['sample_number'])->not->toBe($uaSnapshot['sample_number']);
    expect($hbSnapshot['value_numeric'])->toBe('13.5000');
    expect($uaSnapshot['value_numeric'])->toBe('1.0200');
});

test('amending a released result resets it to pending_verification, clears release metadata, and supersedes the active report', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'csf', 'result_type' => 'numeric']);
    $ctx = releasedForItem($test);
    $request = requestAs($ctx['supervisor']);

    $result = app(LaboratoryResultService::class)->amend($ctx['result'], ['value_numeric' => '20.0', 'amendment_reason' => 'Repeat analysis'], $request);
    app(LaboratoryReportService::class)->supersedeActiveForOrder($result->item->laboratoryOrder, $request);

    expect($result->status)->toBe(LaboratoryResult::STATUS_PENDING_VERIFICATION);
    expect($result->released_by)->toBeNull();
    expect($result->released_at)->toBeNull();

    expect($ctx['report']->fresh()->status)->toBe(LaboratoryReport::STATUS_SUPERSEDED);

    $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory_result_amended_after_release', 'auditable_id' => $result->id]);
});

test('a released result can be corrected, re-verified, re-authorized, and re-released producing a new report version', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'sputum', 'result_type' => 'numeric']);
    $ctx = releasedForItem($test, ['value_numeric' => '999']);
    $request = requestAs($ctx['supervisor']);
    $resultService = app(LaboratoryResultService::class);
    $reportService = app(LaboratoryReportService::class);

    $result = $resultService->amend($ctx['result'], ['value_numeric' => '13.5', 'amendment_reason' => 'Instrument correction'], $request);
    $reportService->supersedeActiveForOrder($result->item->laboratoryOrder, $request);

    $result = $resultService->startReview($result, $request);
    $result = $resultService->verifyResult($result, [], $request);
    $result = $resultService->authorizeResult($result, $request);
    $result = $resultService->release($result, [], $request);
    $reportV2 = $reportService->generateForOrder($result->item->laboratoryOrder, $request);

    expect($result->status)->toBe(LaboratoryResult::STATUS_RELEASED);
    expect($reportV2->version)->toBe(2);
    expect($reportV2->results_snapshot[0]['value_numeric'])->toBe('13.5000');
    expect($ctx['report']->fresh()->status)->toBe(LaboratoryReport::STATUS_SUPERSEDED);
    expect($ctx['report']->fresh()->results_snapshot[0]['value_numeric'])->toBe('999.0000'); // v1 unchanged, historically preserved
});

test('a duplicate report row for the same order and version is rejected', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'urine', 'result_type' => 'numeric']);
    $ctx = releasedForItem($test);

    expect(fn () => LaboratoryReport::query()->create([
        'laboratory_order_id' => $ctx['report']->laboratory_order_id,
        'version' => $ctx['report']->version,
        'status' => LaboratoryReport::STATUS_ACTIVE,
        'results_snapshot' => [],
        'released_by' => $ctx['supervisor']->id,
        'released_at' => now(),
    ]))->toThrow(QueryException::class);
});
