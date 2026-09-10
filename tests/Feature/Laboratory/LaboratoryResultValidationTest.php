<?php

use App\Models\LaboratoryResult;
use App\Services\LaboratoryResultService;
use App\Services\LaboratorySampleService;
use Illuminate\Validation\ValidationException;

test('a non-numeric value is rejected over HTTP for a numeric result', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $technician = userWithRole('laboratory-technician');

    $this->actingAs($technician)
        ->put(route('laboratory.results.update', $ctx['result']), ['value_numeric' => 'abc'])
        ->assertSessionHasErrors('value_numeric');
});

test('an invalid abnormal flag is rejected over HTTP', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'serum', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $technician = userWithRole('laboratory-technician');

    $this->actingAs($technician)
        ->put(route('laboratory.results.update', $ctx['result']), ['value_numeric' => '13.5', 'abnormal_flag' => 'not-a-flag'])
        ->assertSessionHasErrors('abnormal_flag');
});

test('a coded value outside the configured options is rejected by the service', function () {
    $test = activeLaboratoryTest([
        'specimen_type' => 'whole_blood',
        'result_type' => 'enum',
        'result_options' => ['a+', 'b+', 'o+'],
    ]);
    $ctx = resultPendingForItem($test);
    $request = requestAs($ctx['doctor']['user']);

    expect(fn () => app(LaboratoryResultService::class)->saveDraft($ctx['result'], ['value_code' => 'z+'], $request))
        ->toThrow(ValidationException::class);
});

test('no result exists to enter until processing has actually completed — eligibility enforced by construction', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'urine', 'result_type' => 'numeric']);
    $ctx = laboratoryOrderReadyForSampling([$test]);
    $item = $ctx['order']->items->sole();
    $request = requestAs($ctx['doctor']['user']);
    $technician = userWithRole('laboratory-technician');

    app(LaboratorySampleService::class)->create($ctx['order'], [
        'item_ids' => [$item->id],
        'specimen_type' => 'urine',
    ], $request);

    // No LaboratoryResult row exists yet — entry is only ever possible once
    // processing has actually reached RESULT_PENDING (spec §9's eligibility
    // rule), enforced here by construction rather than a bypassable runtime
    // flag: there is nothing for the worklist/detail routes to show.
    expect(LaboratoryResult::query()->count())->toBe(0);

    $this->actingAs($technician)->get(route('laboratory.results.show', 999999))->assertNotFound();
});

test('submitting an already pending_verification result is rejected', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'plasma', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryResultService::class);

    $result = $service->saveDraft($ctx['result'], ['value_numeric' => '13.5'], $request);
    $result = $service->submit($result, $request);

    expect(fn () => $service->submit($result, $request))->toThrow(ValidationException::class);
});

test('amending without a reason is rejected over HTTP', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'csf', 'result_type' => 'numeric']);
    $ctx = resultPendingForItem($test);
    $technician = userWithRole('laboratory-technician');
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryResultService::class);

    $result = $service->saveDraft($ctx['result'], ['value_numeric' => '13.5'], $request);
    $service->submit($result, $request);

    $this->actingAs($technician)
        ->post(route('laboratory.results.amend', $result->fresh()), ['value_numeric' => '14.0'])
        ->assertSessionHasErrors('amendment_reason');
});

test('a text result exceeding the maximum length is rejected over HTTP', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'sputum', 'result_type' => 'text']);
    $ctx = resultPendingForItem($test);
    $technician = userWithRole('laboratory-technician');

    $this->actingAs($technician)
        ->put(route('laboratory.results.update', $ctx['result']), ['value_text' => str_repeat('a', 4001)])
        ->assertSessionHasErrors('value_text');
});
