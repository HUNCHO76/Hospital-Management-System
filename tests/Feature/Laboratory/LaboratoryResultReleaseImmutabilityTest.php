<?php

use App\Models\LaboratoryResult;
use App\Services\LaboratoryReportService;
use App\Services\LaboratoryResultService;

test('ordinary update, submit, verify, and authorize are all rejected over HTTP once a result is released', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = releasedForItem($test);
    $supervisor = $ctx['supervisor'];

    $this->actingAs($supervisor)
        ->put(route('laboratory.results.update', $ctx['result']), ['value_numeric' => '999'])
        ->assertSessionHasErrors('status');

    $this->actingAs($supervisor)->post(route('laboratory.results.submit', $ctx['result']))->assertSessionHasErrors('status');
    $this->actingAs($supervisor)->post(route('laboratory.results.verify', $ctx['result']))->assertSessionHasErrors('status');
    $this->actingAs($supervisor)->post(route('laboratory.results.authorize', $ctx['result']))->assertSessionHasErrors('status');
    $this->actingAs($supervisor)->post(route('laboratory.results.release', $ctx['result']))->assertSessionHasErrors('status');

    expect($ctx['result']->fresh()->value_numeric)->toBe('13.5000');
});

test('a laboratory technician cannot amend a released result even though they hold the ordinary amend permission', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'serum', 'result_type' => 'numeric']);
    $ctx = releasedForItem($test);
    $technician = userWithRole('laboratory-technician');

    $this->actingAs($technician)
        ->post(route('laboratory.results.amend', $ctx['result']), ['value_numeric' => '999', 'amendment_reason' => 'x'])
        ->assertForbidden();

    expect($ctx['result']->fresh()->status)->toBe(LaboratoryResult::STATUS_RELEASED);
});

test('a released report\'s superseded version keeps its original snapshot values forever', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'plasma', 'result_type' => 'numeric']);
    $ctx = releasedForItem($test, ['value_numeric' => '111']);
    $originalSnapshot = $ctx['report']->results_snapshot;

    $result = app(LaboratoryResultService::class)->amend($ctx['result'], ['value_numeric' => '222', 'amendment_reason' => 'x'], requestAs($ctx['supervisor']));
    app(LaboratoryReportService::class)->supersedeActiveForOrder($result->item->laboratoryOrder, requestAs($ctx['supervisor']));

    expect($ctx['report']->fresh()->results_snapshot)->toBe($originalSnapshot);
    expect($ctx['report']->fresh()->results_snapshot[0]['value_numeric'])->toBe('111.0000');
});
