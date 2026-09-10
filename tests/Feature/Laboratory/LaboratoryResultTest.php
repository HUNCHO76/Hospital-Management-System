<?php

use App\Models\AuditLog;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryResult;
use App\Services\LaboratoryResultService;
use Illuminate\Validation\ValidationException;

test('completing processing automatically creates a result pending record with a snapshotted configuration', function () {
    $test = activeLaboratoryTest([
        'specimen_type' => 'whole_blood',
        'result_type' => 'numeric',
        'unit' => 'g/dL',
        'reference_range' => '13.5-17.5',
    ]);
    $ctx = resultPendingForItem($test);

    expect($ctx['result']->status)->toBe(LaboratoryResult::STATUS_RESULT_PENDING);
    expect($ctx['result']->result_type)->toBe('numeric');
    expect($ctx['result']->unit)->toBe('g/dL');
    expect($ctx['result']->reference_range)->toBe('13.5-17.5');
    expect($ctx['result']->laboratory_sample_id)->toBe($ctx['sample']->id);
});

test('a numeric result can be saved as a draft and then submitted for verification', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric', 'unit' => 'g/dL']);
    $ctx = resultPendingForItem($test);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryResultService::class);

    $result = $service->saveDraft($ctx['result'], ['value_numeric' => '13.5', 'abnormal_flag' => 'normal'], $request);

    expect($result->status)->toBe(LaboratoryResult::STATUS_DRAFT);
    expect($result->value_numeric)->toBe('13.5000');
    expect($result->entered_by)->toBe($ctx['doctor']['user']->id);
    expect($result->entered_at)->not->toBeNull();

    $result = $service->submit($result, $request);

    expect($result->status)->toBe(LaboratoryResult::STATUS_PENDING_VERIFICATION);
    expect($result->submitted_by)->toBe($ctx['doctor']['user']->id);
    expect($result->submitted_at)->not->toBeNull();
});

test('a draft can be saved with a blank value but submission requires a present value', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'urine', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryResultService::class);

    $result = $service->saveDraft($ctx['result'], [], $request);
    expect($result->status)->toBe(LaboratoryResult::STATUS_DRAFT);
    expect($result->value_numeric)->toBeNull();

    expect(fn () => $service->submit($result, $request))->toThrow(ValidationException::class);
});

test('a text result is stored in value_text and a qualitative result in value_code', function () {
    $textTest = activeLaboratoryTest(['specimen_type' => 'sputum', 'result_type' => 'text']);
    $textCtx = resultPendingForItem($textTest);
    $textRequest = requestAs($textCtx['doctor']['user']);
    $service = app(LaboratoryResultService::class);

    $textResult = $service->saveDraft($textCtx['result'], ['value_text' => 'Few epithelial cells observed.'], $textRequest);
    expect($textResult->value_text)->toBe('Few epithelial cells observed.');
    expect($textResult->value_numeric)->toBeNull();

    $qualitativeTest = activeLaboratoryTest([
        'specimen_type' => 'whole_blood',
        'result_type' => 'qualitative',
        'result_options' => ['positive', 'negative'],
    ]);
    $qualitativeCtx = resultPendingForItem($qualitativeTest);
    $qualitativeRequest = requestAs($qualitativeCtx['doctor']['user']);

    $qualitativeResult = $service->saveDraft($qualitativeCtx['result'], ['value_code' => 'positive'], $qualitativeRequest);
    expect($qualitativeResult->value_code)->toBe('positive');
    expect($qualitativeResult->value_text)->toBeNull();
});

test('a coded result is rejected when it is not one of the test\'s configured options', function () {
    $test = activeLaboratoryTest([
        'specimen_type' => 'whole_blood',
        'result_type' => 'enum',
        'result_options' => ['a+', 'a-', 'b+', 'b-', 'ab+', 'ab-', 'o+', 'o-'],
    ]);
    $ctx = resultPendingForItem($test);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryResultService::class);

    expect(fn () => $service->saveDraft($ctx['result'], ['value_code' => 'z+'], $request))->toThrow(ValidationException::class);

    $result = $service->saveDraft($ctx['result'], ['value_code' => 'o+'], $request);
    expect($result->value_code)->toBe('o+');
});

test('boolean and positive_negative types fall back to sensible default options when none are configured', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'positive_negative', 'result_options' => null]);
    $ctx = resultPendingForItem($test);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryResultService::class);

    expect(fn () => $service->saveDraft($ctx['result'], ['value_code' => 'maybe'], $request))->toThrow(ValidationException::class);

    $result = $service->saveDraft($ctx['result'], ['value_code' => 'positive'], $request);
    expect($result->value_code)->toBe('positive');
});

test('a submitted result can be amended and the original value is preserved in the audit trail', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryResultService::class);

    $result = $service->saveDraft($ctx['result'], ['value_numeric' => '13.5'], $request);
    $result = $service->submit($result, $request);

    $result = $service->amend($result, ['value_numeric' => '14.0', 'amendment_reason' => 'Transcription correction'], $request);

    expect($result->status)->toBe(LaboratoryResult::STATUS_PENDING_VERIFICATION);
    expect($result->value_numeric)->toBe('14.0000');
    expect($result->amended_by)->toBe($ctx['doctor']['user']->id);
    expect($result->amended_at)->not->toBeNull();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'laboratory_result_amended',
        'auditable_id' => $result->id,
    ]);

    $valueChanged = AuditLog::where('action', 'laboratory_result_value_changed')
        ->where('auditable_id', $result->id)
        ->latest('id')
        ->first();

    expect($valueChanged)->not->toBeNull();
    expect($valueChanged->old_values['value_numeric'])->toBe('13.5000');
    expect($valueChanged->new_values['value_numeric'])->toBe('14.0000');
});

test('a draft cannot be amended — amendment is only reachable once submitted', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryResultService::class);

    $result = $service->saveDraft($ctx['result'], ['value_numeric' => '13.5'], $request);

    expect(fn () => $service->amend($result, ['value_numeric' => '14.0', 'amendment_reason' => 'x'], $request))
        ->toThrow(ValidationException::class);
});

test('a result with no configured result_type falls back to free text rather than blocking entry', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => null]);
    $ctx = resultPendingForItem($test);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryResultService::class);

    $result = $service->saveDraft($ctx['result'], ['value_text' => 'Unremarkable'], $request);

    expect($result->value_text)->toBe('Unremarkable');
});

test('the order and item status are not advanced by result entry in this step', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryResultService::class);

    $result = $service->saveDraft($ctx['result'], ['value_numeric' => '13.5'], $request);
    $service->submit($result, $request);

    expect($ctx['order']->fresh()->status)->toBe(LaboratoryOrder::STATUS_RESULT_PENDING);
});

test('initializing a result for the same item twice never creates a duplicate row', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $request = requestAs($ctx['doctor']['user']);

    app(LaboratoryResultService::class)->initializeForItem($ctx['processing']->item, $ctx['processing']->sample, $request);

    expect(LaboratoryResult::where('laboratory_order_item_id', $ctx['processing']->laboratory_order_item_id)->count())->toBe(1);
});
