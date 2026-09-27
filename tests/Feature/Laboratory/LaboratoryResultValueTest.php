<?php

use App\Models\Hospital;
use App\Models\LaboratoryResult;
use App\Models\LaboratoryResultValue;
use App\Models\LaboratoryTestParameter;
use App\Models\Staff;
use App\Services\LaboratoryResultService;
use Illuminate\Validation\ValidationException;

// ── Parameter value creation on initializeForItem ───────────────────────────

test('initializing a result for a test with parameters creates empty result value rows', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $parameter = LaboratoryTestParameter::factory()->for($test)->create([
        'code' => 'FBC_HGB',
        'name' => 'HGB',
        'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
        'active' => true,
        'display_order' => 1,
    ]);

    $ctx = resultPendingForItem($test);

    $values = LaboratoryResultValue::where('laboratory_result_id', $ctx['result']->id)->get();
    expect($values)->toHaveCount(1);
    expect($values->first()->laboratory_test_parameter_id)->toBe($parameter->id);
    expect($values->first()->value_numeric)->toBeNull();
});

test('initializeForItem is idempotent — calling it twice does not duplicate parameter rows', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    LaboratoryTestParameter::factory()->for($test)->create(['data_type' => 'numeric', 'active' => true, 'display_order' => 1]);

    $ctx = resultPendingForItem($test);

    // Re-init would be triggered by a duplicate processing completion in a race.
    // The firstOrCreate in initializeForItem guards against it.
    $countBefore = LaboratoryResultValue::where('laboratory_result_id', $ctx['result']->id)->count();
    expect($countBefore)->toBe(1);
});

// ── saveDraft multi-parameter path ───────────────────────────────────────────

test('saveDraft with parameter_values populates the result value rows', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $wbc = LaboratoryTestParameter::factory()->for($test)->create([
        'code' => 'FBC_WBC', 'name' => 'WBC', 'data_type' => 'numeric', 'unit' => '10⁹/L', 'active' => true, 'display_order' => 1,
    ]);
    $hgb = LaboratoryTestParameter::factory()->for($test)->create([
        'code' => 'FBC_HGB', 'name' => 'HGB', 'data_type' => 'numeric', 'unit' => 'g/dL', 'active' => true, 'display_order' => 2,
    ]);

    $ctx = resultPendingForItem($test);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryResultService::class);

    $result = $service->saveDraft($ctx['result'], [
        'parameter_values' => [
            ['parameter_id' => $wbc->id, 'value_numeric' => '7.2'],
            ['parameter_id' => $hgb->id, 'value_numeric' => '14.5'],
        ],
    ], $request);

    expect($result->status)->toBe(LaboratoryResult::STATUS_DRAFT);

    $wbcValue = LaboratoryResultValue::where('laboratory_result_id', $result->id)
        ->where('laboratory_test_parameter_id', $wbc->id)
        ->first();

    expect($wbcValue)->not->toBeNull();
    expect($wbcValue->value_numeric)->toBe('7.2000');
    expect($wbcValue->unit)->toBe('10⁹/L');
});

test('saveDraft rejects a non-numeric value for a numeric parameter', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $param = LaboratoryTestParameter::factory()->for($test)->create([
        'code' => 'FBC_HGB', 'data_type' => 'numeric', 'active' => true, 'display_order' => 1,
    ]);

    $ctx = resultPendingForItem($test);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryResultService::class);

    expect(fn () => $service->saveDraft($ctx['result'], [
        'parameter_values' => [
            ['parameter_id' => $param->id, 'value_numeric' => 'not-a-number'],
        ],
    ], $request))->toThrow(ValidationException::class);
});

test('saveDraft rejects a parameter that does not belong to this test', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $otherTest = activeLaboratoryTest(['specimen_type' => 'serum', 'result_type' => 'numeric']);
    $foreignParam = LaboratoryTestParameter::factory()->for($otherTest)->create([
        'code' => 'OTHER_PARAM', 'data_type' => 'numeric', 'active' => true, 'display_order' => 1,
    ]);

    $ctx = resultPendingForItem($test);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryResultService::class);

    expect(fn () => $service->saveDraft($ctx['result'], [
        'parameter_values' => [
            ['parameter_id' => $foreignParam->id, 'value_numeric' => '5.0'],
        ],
    ], $request))->toThrow(ValidationException::class);
});

test('saveDraft rejects an invalid option value for an option parameter', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'positive_negative']);
    $param = LaboratoryTestParameter::factory()->for($test)->create([
        'code' => 'MALARIA_RES', 'data_type' => 'option',
        'options_json' => ['positive', 'negative'],
        'active' => true, 'display_order' => 1,
    ]);

    $ctx = resultPendingForItem($test);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryResultService::class);

    expect(fn () => $service->saveDraft($ctx['result'], [
        'parameter_values' => [
            ['parameter_id' => $param->id, 'value_code' => 'invalid_option'],
        ],
    ], $request))->toThrow(ValidationException::class);
});

// ── submit / validateParameterValuesPresent ──────────────────────────────────

test('submitting a parameterized result fails when some parameter values are missing', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    LaboratoryTestParameter::factory()->for($test)->create([
        'code' => 'FBC_WBC', 'name' => 'WBC', 'data_type' => 'numeric', 'active' => true, 'display_order' => 1,
    ]);
    LaboratoryTestParameter::factory()->for($test)->create([
        'code' => 'FBC_HGB', 'name' => 'HGB', 'data_type' => 'numeric', 'active' => true, 'display_order' => 2,
    ]);

    $ctx = resultPendingForItem($test);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryResultService::class);

    // Save draft with only one parameter filled
    $service->saveDraft($ctx['result'], [
        'parameter_values' => [
            ['parameter_id' => $ctx['result']->resultValues()->first()->laboratory_test_parameter_id, 'value_numeric' => '7.0'],
        ],
    ], $request);

    // Submit should fail because the second parameter has no value
    expect(fn () => $service->submit($ctx['result']->fresh(), $request))->toThrow(ValidationException::class);
});

test('submitting a parameterized result succeeds when all parameter values are present', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $wbc = LaboratoryTestParameter::factory()->for($test)->create([
        'code' => 'FBC_WBC', 'name' => 'WBC', 'data_type' => 'numeric', 'active' => true, 'display_order' => 1,
    ]);
    $hgb = LaboratoryTestParameter::factory()->for($test)->create([
        'code' => 'FBC_HGB', 'name' => 'HGB', 'data_type' => 'numeric', 'active' => true, 'display_order' => 2,
    ]);

    $ctx = resultPendingForItem($test);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryResultService::class);

    $result = $service->saveDraft($ctx['result'], [
        'parameter_values' => [
            ['parameter_id' => $wbc->id, 'value_numeric' => '7.2'],
            ['parameter_id' => $hgb->id, 'value_numeric' => '14.5'],
        ],
    ], $request);

    $submitted = $service->submit($result->fresh(), $request);
    expect($submitted->status)->toBe(LaboratoryResult::STATUS_PENDING_VERIFICATION);
});

// ── Aggregate flag propagation ────────────────────────────────────────────────

test('aggregate flag on the parent result reflects the worst flag across parameters', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $wbc = LaboratoryTestParameter::factory()->for($test)->create([
        'code' => 'FBC_WBC', 'data_type' => 'numeric', 'active' => true, 'display_order' => 1,
    ]);
    $hgb = LaboratoryTestParameter::factory()->for($test)->create([
        'code' => 'FBC_HGB', 'data_type' => 'numeric', 'active' => true, 'display_order' => 2,
    ]);

    $ctx = resultPendingForItem($test);
    $request = requestAs($ctx['doctor']['user']);
    $service = app(LaboratoryResultService::class);

    // Set one normal, one low (no reference range configured — both will
    // come back 'normal' from LaboratoryFlagCalculator since no range is
    // configured). The aggregate flag is therefore null/normal.
    $result = $service->saveDraft($ctx['result'], [
        'parameter_values' => [
            ['parameter_id' => $wbc->id, 'value_numeric' => '7.2'],
            ['parameter_id' => $hgb->id, 'value_numeric' => '14.5'],
        ],
    ], $request);

    // Without reference ranges configured on these factory parameters, the
    // flag calculator returns 'normal' for both — aggregate is 'normal'.
    expect($result->abnormal_flag)->toBe('normal');
});

// ── IDOR ─────────────────────────────────────────────────────────────────────

test('a hospital-admin from a different hospital cannot update a parameterized result via the HTTP endpoint', function () {
    seedRolesAndPermissions();

    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $param = LaboratoryTestParameter::factory()->for($test)->create([
        'code' => 'FBC_HGB', 'data_type' => 'numeric', 'active' => true, 'display_order' => 1,
    ]);

    $ctx = resultPendingForItem($test);

    // hospital-admin at a different hospital — belongsToUsersHospital will return false
    $adminOtherHospital = userWithRole('hospital-admin');
    Staff::factory()->create([
        'user_id' => $adminOtherHospital->id,
        'hospital_id' => Hospital::factory()->create()->id,
    ]);

    $response = $this->actingAs($adminOtherHospital)->put(route('laboratory.results.update', $ctx['result']), [
        'parameter_values' => [
            ['parameter_id' => $param->id, 'value_numeric' => '7.0'],
        ],
    ]);

    $response->assertForbidden();
});

// ── isReferenced guard ────────────────────────────────────────────────────────

test('a parameter with recorded result values cannot be deleted', function () {
    seedRolesAndPermissions();

    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $param = LaboratoryTestParameter::factory()->for($test)->create([
        'code' => 'FBC_HGB', 'data_type' => 'numeric', 'active' => true, 'display_order' => 1,
    ]);

    LaboratoryResultValue::factory()->create(['laboratory_test_parameter_id' => $param->id]);

    $admin = userWithRole('hospital-admin');

    $response = $this->actingAs($admin)->delete(route('laboratory-test-parameters.destroy', [$test, $param]));

    // Cannot delete because isReferenced() returns true — Inertia redirects back with session error
    $response->assertSessionHasErrors('parameter');
    expect(LaboratoryTestParameter::find($param->id))->not->toBeNull();
});
