<?php

use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderItem;
use App\Models\LaboratoryReferenceRange;
use App\Models\LaboratoryResult;
use App\Models\LaboratoryResultValue;
use App\Models\LaboratorySample;
use App\Models\LaboratoryTestParameter;
use App\Models\Patient;
use App\Services\LaboratoryFlagCalculator;
use App\Services\LaboratoryReferenceRangeService;
use App\Services\LaboratoryReportService;

test('reference range service selects age- and sex-specific ranges correctly', function () {
    $test = activeLaboratoryTest();
    $param = LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create([
        'code' => 'HGB',
        'name' => 'Hemoglobin',
        'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
        'unit' => 'g/dL',
    ]);

    LaboratoryReferenceRange::factory()->for($param, 'parameter')->create([
        'sex' => 'male', 'age_min_days' => 4380, 'age_max_days' => null,
        'low' => 13.0, 'high' => 17.0, 'critical_low' => 7.0, 'critical_high' => 20.0,
    ]);

    LaboratoryReferenceRange::factory()->for($param, 'parameter')->create([
        'sex' => 'female', 'age_min_days' => 4380, 'age_max_days' => null,
        'low' => 12.0, 'high' => 15.5, 'critical_low' => 7.0, 'critical_high' => 20.0,
    ]);

    LaboratoryReferenceRange::factory()->for($param, 'parameter')->create([
        'sex' => 'any', 'age_min_days' => 365, 'age_max_days' => 4379,
        'low' => 11.5, 'high' => 15.5, 'critical_low' => 7.0, 'critical_high' => 20.0,
    ]);

    $service = app(LaboratoryReferenceRangeService::class);

    $adultMale = Patient::factory()->create(['gender' => 'male', 'date_of_birth' => now()->subYears(30)->toDateString()]);
    $maleRange = $service->selectForPatient($param, $adultMale);
    expect($maleRange)->not->toBeNull();
    expect((float) $maleRange->low)->toBe(13.0);
    expect((float) $maleRange->high)->toBe(17.0);

    $adultFemale = Patient::factory()->create(['gender' => 'female', 'date_of_birth' => now()->subYears(25)->toDateString()]);
    $femaleRange = $service->selectForPatient($param, $adultFemale);
    expect($femaleRange)->not->toBeNull();
    expect((float) $femaleRange->low)->toBe(12.0);
    expect((float) $femaleRange->high)->toBe(15.5);

    $child = Patient::factory()->create(['gender' => 'female', 'date_of_birth' => now()->subYears(5)->toDateString()]);
    $childRange = $service->selectForPatient($param, $child);
    expect($childRange)->not->toBeNull();
    expect((float) $childRange->low)->toBe(11.5);
    expect((float) $childRange->high)->toBe(15.5);
});

test('qualitative option parameters flag abnormal based on abnormal_options without numeric range', function () {
    $test = activeLaboratoryTest();
    $param = LaboratoryTestParameter::factory()->option()->for($test, 'laboratoryTest')->create([
        'code' => 'MALARIA',
        'name' => 'Malaria Smear',
        'unit' => null,
        'options_json' => ['negative', 'positive'],
        'abnormal_options' => ['positive'],
    ]);

    $calc = app(LaboratoryFlagCalculator::class);

    expect($calc->flag($param, null, 'negative', null))->toBe(LaboratoryFlagCalculator::FLAG_NORMAL);
    expect($calc->flag($param, null, 'positive', null))->toBe(LaboratoryFlagCalculator::FLAG_ABNORMAL);
});

test('unconfigured numeric parameter returns null range from service', function () {
    $test = activeLaboratoryTest();
    $param = LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create([
        'code' => 'EXPERIMENTAL',
        'name' => 'Experimental Analyser',
        'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
        'unit' => 'U/L',
    ]);

    $patient = Patient::factory()->create(['gender' => 'male', 'date_of_birth' => now()->subYears(30)->toDateString()]);
    $range = app(LaboratoryReferenceRangeService::class)->selectForPatient($param, $patient);

    expect($range)->toBeNull();
});

test('historical result values snapshot reference range and unit immutably', function () {
    $test = activeLaboratoryTest(['specimen_type' => 'whole_blood', 'result_type' => 'numeric']);
    $param = LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create([
        'code' => 'TEST_ANALYTE',
        'name' => 'Analyte',
        'data_type' => 'numeric',
        'unit' => 'mmol/L',
        'active' => true,
        'display_order' => 1,
    ]);

    $range = LaboratoryReferenceRange::factory()->for($param, 'parameter')->create([
        'sex' => 'any',
        'age_min_days' => 0,
        'age_max_days' => null,
        'low' => 3.5,
        'high' => 5.5,
        'critical_low' => 2.0,
        'critical_high' => 7.0,
    ]);

    $result = LaboratoryResult::factory()->create(['laboratory_test_id' => $test->id]);
    $rv = LaboratoryResultValue::create([
        'laboratory_result_id' => $result->id,
        'laboratory_test_parameter_id' => $param->id,
        'value_numeric' => '4.2',
        'unit' => 'mmol/L',
        'reference_low' => '3.5000',
        'reference_high' => '5.5000',
        'reference_critical_low' => '2.0000',
        'reference_critical_high' => '7.0000',
        'abnormal_flag' => 'normal',
    ]);

    expect($rv->unit)->toBe('mmol/L');
    expect((float) $rv->reference_low)->toBe(3.5);
    expect((float) $rv->reference_high)->toBe(5.5);
    expect($rv->abnormal_flag)->toBe('normal');

    // Alter the catalog reference range in the database
    $range->update(['low' => 4.5, 'high' => 6.5]);

    // The historical snapshotted value must remain unchanged
    $rv->refresh();
    expect((float) $rv->reference_low)->toBe(3.5);
    expect((float) $rv->reference_high)->toBe(5.5);
    expect($rv->abnormal_flag)->toBe('normal');
});

test('laboratory report snapshot includes parameter details and reference ranges', function () {
    $order = LaboratoryOrder::factory()->create();
    $item = LaboratoryOrderItem::factory()->for($order, 'laboratoryOrder')->create([
        'test_code' => 'GLU',
        'test_name' => 'Glucose Test',
    ]);
    $sample = LaboratorySample::factory()->for($order, 'laboratoryOrder')->create([
        'sample_number' => 'SMP-1001',
        'specimen_type' => 'whole_blood',
    ]);
    $result = LaboratoryResult::factory()->create([
        'laboratory_order_item_id' => $item->id,
        'laboratory_sample_id' => $sample->id,
        'laboratory_test_id' => $item->laboratory_test_id,
        'status' => LaboratoryResult::STATUS_RELEASED,
    ]);

    $param = LaboratoryTestParameter::factory()->for($item->laboratoryTest, 'laboratoryTest')->create([
        'code' => 'GLU',
        'name' => 'Glucose',
        'data_type' => 'numeric',
        'unit' => 'mmol/L',
        'display_order' => 1,
    ]);

    LaboratoryResultValue::create([
        'laboratory_result_id' => $result->id,
        'laboratory_test_parameter_id' => $param->id,
        'value_numeric' => '5.0',
        'unit' => 'mmol/L',
        'reference_low' => '3.9000',
        'reference_high' => '5.6000',
        'abnormal_flag' => 'normal',
    ]);

    $user = userWithRole('doctor');
    $request = requestAs($user);
    $reportService = app(LaboratoryReportService::class);
    $report = $reportService->generateForOrder($order, $request);

    expect($report)->not->toBeNull();
    expect($report->results_snapshot)->toBeArray();
    expect(count($report->results_snapshot))->toBeGreaterThan(0);

    $group = $report->results_snapshot[0];
    expect($group['result_values'])->toBeArray();
    expect(count($group['result_values']))->toBe(1);

    $paramSnapshot = $group['result_values'][0];
    expect($paramSnapshot['parameter_name'])->toBe('Glucose');
    expect($paramSnapshot['unit'])->toBe('mmol/L');
    expect((float) $paramSnapshot['reference_low'])->toBe(3.9);
    expect((float) $paramSnapshot['reference_high'])->toBe(5.6);
});
