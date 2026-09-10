<?php

use App\Models\LaboratoryBench;
use App\Models\LaboratoryProcessing;
use App\Services\LaboratoryProcessingService;
use App\Services\LaboratorySampleService;
use Illuminate\Validation\ValidationException;

test('assignment is rejected over HTTP without a technician', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood']);
    $ctx = receivedSampleReadyForProcessing([$test]);
    app(LaboratoryProcessingService::class)->acceptSample($ctx['sample']->fresh(), requestAs($ctx['doctor']['user']));
    $record = LaboratoryProcessing::where('laboratory_sample_id', $ctx['sample']->id)->sole();

    $technician = userWithRole('laboratory-technician');

    $this->actingAs($technician)->post(route('laboratory.processing.assign', $record), [])
        ->assertSessionHasErrors('assigned_to');
});

test('assignment is rejected over HTTP with a non-existent technician id', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'serum']);
    $ctx = receivedSampleReadyForProcessing([$test]);
    app(LaboratoryProcessingService::class)->acceptSample($ctx['sample']->fresh(), requestAs($ctx['doctor']['user']));
    $record = LaboratoryProcessing::where('laboratory_sample_id', $ctx['sample']->id)->sole();

    $technician = userWithRole('laboratory-technician');

    $this->actingAs($technician)->post(route('laboratory.processing.assign', $record), ['assigned_to' => 999999])
        ->assertSessionHasErrors('assigned_to');
});

test('assignment is rejected over HTTP with an inactive bench', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'plasma']);
    $ctx = receivedSampleReadyForProcessing([$test]);
    app(LaboratoryProcessingService::class)->acceptSample($ctx['sample']->fresh(), requestAs($ctx['doctor']['user']));
    $record = LaboratoryProcessing::where('laboratory_sample_id', $ctx['sample']->id)->sole();

    $technician = userWithRole('laboratory-technician');
    $bench = LaboratoryBench::factory()->create(['status' => LaboratoryBench::STATUS_INACTIVE]);

    $this->actingAs($technician)->post(route('laboratory.processing.assign', $record), [
        'assigned_to' => $technician->id,
        'bench_id' => $bench->id,
    ])->assertSessionHasErrors('bench_id');
});

test('transfer is rejected over HTTP without a bench or reason', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'urine']);
    $ctx = receivedSampleReadyForProcessing([$test]);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryProcessingService::class);
    $service->acceptSample($ctx['sample']->fresh(), $request);
    $record = LaboratoryProcessing::where('laboratory_sample_id', $ctx['sample']->id)->sole();
    $record = $service->start($record, [], $request);

    $technician = userWithRole('laboratory-technician');

    $this->actingAs($technician)->post(route('laboratory.processing.transfer', $record), [])
        ->assertSessionHasErrors(['bench_id', 'transfer_reason']);
});

test('rejection during processing is rejected over HTTP without a reason', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'sputum']);
    $ctx = receivedSampleReadyForProcessing([$test]);
    $technician = userWithRole('laboratory-technician');

    $this->actingAs($technician)->post(route('laboratory.processing.reject', $ctx['sample']), [])
        ->assertSessionHasErrors('rejection_reason');

    expect($ctx['sample']->fresh()->status)->toBe('received');
});

test('a specimen cannot be rejected during processing once one of its tests has reached result_pending', function () {
    $testA = activeLaboratoryTest(['specimen_type' => 'whole_blood']);
    $testB = activeLaboratoryTest(['specimen_type' => 'whole_blood']);
    $ctx = receivedSampleReadyForProcessing([$testA, $testB]);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryProcessingService::class);

    $sample = $service->acceptSample($ctx['sample']->fresh(), $request);
    $recordA = $sample->processingRecords->first();
    $recordA = $service->start($recordA, [], $request);
    $service->complete($recordA, [], $request);

    // The other test on this same specimen is still only ACCEPTED — the
    // specimen as a whole can no longer be rejected, since one of its
    // tests already has a result pending against it, and there is no
    // sample-level status to represent "rejected for one test, resulted
    // for another."
    expect(fn () => $service->rejectSample($ctx['sample']->fresh(), ['rejection_reason' => 'other', 'rejection_notes' => 'x'], $request))
        ->toThrow(ValidationException::class);

    expect($ctx['sample']->fresh()->status)->toBe('received');
});

test('invalid processing status transitions are rejected by the service regardless of frontend state', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'csf']);
    $ctx = receivedSampleReadyForProcessing([$test]);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryProcessingService::class);
    $record = LaboratoryProcessing::where('laboratory_sample_id', $ctx['sample']->id)->sole();

    // RECEIVED -> IN_PROGRESS is never valid; must be accepted first.
    expect(fn () => $service->start($record, [], $request))->toThrow(ValidationException::class);

    // RECEIVED -> assign is never valid either.
    expect(fn () => $service->assign($record, ['assigned_to' => $ctx['doctor']['user']->id], $request))
        ->toThrow(ValidationException::class);

    $record = $service->acceptSample($ctx['sample']->fresh(), $request)->processingRecords->sole();
    $record = $service->start($record, [], $request);
    $record = $service->complete($record, [], $request);

    // RESULT_PENDING -> anything is never valid in this step.
    expect(fn () => $service->start($record, [], $request))->toThrow(ValidationException::class);
    expect(fn () => $service->complete($record, [], $request))->toThrow(ValidationException::class);
    expect(fn () => $service->transfer($record, ['bench_id' => 1, 'transfer_reason' => 'x'], $request))
        ->toThrow(ValidationException::class);
});

test('a specimen that is not received cannot be accepted', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'tissue']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $item = $ctx['order']->items->sole();
    $request = requestAs($ctx['doctor']['user']);

    $sample = app(LaboratorySampleService::class)->create($ctx['order'], [
        'item_ids' => [$item->id],
        'specimen_type' => 'tissue',
    ], $request);

    expect(fn () => app(LaboratoryProcessingService::class)->acceptSample($sample, $request))
        ->toThrow(ValidationException::class);
});
