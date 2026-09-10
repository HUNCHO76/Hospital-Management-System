<?php

use App\Models\LaboratoryResult;
use App\Services\LaboratoryResultService;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Mandatory concurrency protection (spec §35): row-locking inside
 * DB::transaction() must prevent two racing submissions/amendments from
 * both succeeding. Mirrors LaboratoryProcessingConcurrencyTest's repeated-
 * attempt style.
 */
test('only one of several back-to-back attempts to submit the same result succeeds', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryResultService::class);

    $service->saveDraft($ctx['result'], ['value_numeric' => '13.5'], $request);

    $successes = 0;
    $rejections = 0;

    for ($i = 0; $i < 5; $i++) {
        try {
            $service->submit($ctx['result']->fresh(), $request);
            $successes++;
        } catch (ValidationException) {
            $rejections++;
        }
    }

    expect($successes)->toBe(1);
    expect($rejections)->toBe(4);
    expect($ctx['result']->fresh()->status)->toBe(LaboratoryResult::STATUS_PENDING_VERIFICATION);
});

test('repeated draft saves never reset entered_by/entered_at once set, even against a stale in-memory copy', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'serum', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $technicianA = requestAs($ctx['doctor']['user']);
    $technicianB = requestAs(userWithRole('laboratory-technician'));

    // Both "requests" load the same RESULT_PENDING row before either writes.
    $staleForA = $ctx['result'];
    $staleForB = LaboratoryResult::find($ctx['result']->id);

    $service = app(LaboratoryResultService::class);
    $service->saveDraft($staleForA, ['value_numeric' => '13.5'], $technicianA);

    // Technician B's save is based on a stale (still RESULT_PENDING) copy,
    // but the service re-fetches and locks the row itself — it must not
    // reset entered_by/entered_at back to technician B just because the
    // in-memory object B started from predates A's write.
    $service->saveDraft($staleForB, ['value_numeric' => '14.0'], $technicianB);

    $final = $ctx['result']->fresh();
    expect($final->value_numeric)->toBe('14.0000');
    expect($final->entered_by)->toBe($technicianA->user()->id);
    expect($final->entered_at)->not->toBeNull();
});

test('a result cannot be duplicated for the same order item', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'urine', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);

    expect(fn () => LaboratoryResult::query()->create([
        'laboratory_order_item_id' => $ctx['result']->laboratory_order_item_id,
        'laboratory_sample_id' => $ctx['result']->laboratory_sample_id,
        'laboratory_test_id' => $ctx['result']->laboratory_test_id,
        'status' => LaboratoryResult::STATUS_RESULT_PENDING,
    ]))->toThrow(QueryException::class);
});
