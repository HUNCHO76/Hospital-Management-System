<?php

use App\Models\ClinicalEncounter;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderItem;
use App\Models\LaboratoryReferenceRange;
use App\Models\LaboratoryResult;
use App\Models\LaboratorySample;
use App\Models\LaboratoryTestParameter;
use App\Models\Patient;
use App\Services\LaboratoryFlagCalculator;
use App\Services\LaboratoryReferenceRangeService;
use App\Services\LaboratoryResultService;

test('laboratory reference range service resolves arterial vs venous ranges by specimen source', function () {
    $test = activeLaboratoryTest();

    $phParam = LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create([
        'code' => 'PH',
        'name' => 'pH',
        'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
        'unit' => 'pH units',
    ]);

    $arterialRange = LaboratoryReferenceRange::factory()->for($phParam, 'parameter')->create([
        'sex' => 'any',
        'specimen_source' => LaboratoryReferenceRange::SPECIMEN_SOURCE_ARTERIAL,
        'low' => 7.35,
        'high' => 7.45,
        'critical_low' => 7.20,
        'critical_high' => 7.60,
    ]);

    $venousRange = LaboratoryReferenceRange::factory()->for($phParam, 'parameter')->create([
        'sex' => 'any',
        'specimen_source' => LaboratoryReferenceRange::SPECIMEN_SOURCE_VENOUS,
        'low' => 7.31,
        'high' => 7.41,
        'critical_low' => 7.20,
        'critical_high' => 7.60,
    ]);

    $service = app(LaboratoryReferenceRangeService::class);
    $patient = Patient::factory()->create(['gender' => 'male', 'date_of_birth' => now()->subYears(30)->toDateString()]);

    $resolvedArterial = $service->selectForPatient($phParam, $patient, 'arterial');
    expect($resolvedArterial)->not->toBeNull();
    expect($resolvedArterial->id)->toBe($arterialRange->id);
    expect((float) $resolvedArterial->low)->toBe(7.35);
    expect((float) $resolvedArterial->high)->toBe(7.45);

    $resolvedVenous = $service->selectForPatient($phParam, $patient, 'venous');
    expect($resolvedVenous)->not->toBeNull();
    expect($resolvedVenous->id)->toBe($venousRange->id);
    expect((float) $resolvedVenous->low)->toBe(7.31);
    expect((float) $resolvedVenous->high)->toBe(7.41);
});

test('laboratory flag calculator correctly differentiates abg normal, low, high, critical_low, critical_high and unconfigured', function () {
    $calculator = app(LaboratoryFlagCalculator::class);

    $range = new LaboratoryReferenceRange([
        'low' => 7.35,
        'high' => 7.45,
        'critical_low' => 7.20,
        'critical_high' => 7.60,
    ]);

    // Legacy mode ($detailed = false)
    expect($calculator->flagNumeric(7.40, $range))->toBe(LaboratoryFlagCalculator::FLAG_NORMAL);
    expect($calculator->flagNumeric(7.30, $range))->toBe(LaboratoryFlagCalculator::FLAG_LOW);
    expect($calculator->flagNumeric(7.50, $range))->toBe(LaboratoryFlagCalculator::FLAG_HIGH);
    expect($calculator->flagNumeric(7.15, $range))->toBe(LaboratoryFlagCalculator::FLAG_CRITICAL);
    expect($calculator->flagNumeric(7.65, $range))->toBe(LaboratoryFlagCalculator::FLAG_CRITICAL);

    // Detailed mode ($detailed = true)
    expect($calculator->flagNumeric(7.40, $range, true))->toBe(LaboratoryFlagCalculator::FLAG_NORMAL);
    expect($calculator->flagNumeric(7.30, $range, true))->toBe(LaboratoryFlagCalculator::FLAG_LOW);
    expect($calculator->flagNumeric(7.50, $range, true))->toBe(LaboratoryFlagCalculator::FLAG_HIGH);
    expect($calculator->flagNumeric(7.15, $range, true))->toBe(LaboratoryFlagCalculator::FLAG_CRITICAL_LOW);
    expect($calculator->flagNumeric(7.65, $range, true))->toBe(LaboratoryFlagCalculator::FLAG_CRITICAL_HIGH);

    // Unconfigured
    expect($calculator->flagNumeric(7.40, null, true))->toBe(LaboratoryFlagCalculator::FLAG_UNCONFIGURED);
});

test('result service evaluates pH 7.33 as normal in venous specimen but low in arterial specimen', function () {
    $test = activeLaboratoryTest();

    $phParam = LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create([
        'code' => 'PH',
        'name' => 'pH',
        'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
        'unit' => 'pH units',
    ]);

    LaboratoryReferenceRange::factory()->for($phParam, 'parameter')->create([
        'sex' => 'any',
        'specimen_source' => LaboratoryReferenceRange::SPECIMEN_SOURCE_ARTERIAL,
        'low' => 7.35,
        'high' => 7.45,
        'critical_low' => 7.20,
        'critical_high' => 7.60,
    ]);

    LaboratoryReferenceRange::factory()->for($phParam, 'parameter')->create([
        'sex' => 'any',
        'specimen_source' => LaboratoryReferenceRange::SPECIMEN_SOURCE_VENOUS,
        'low' => 7.31,
        'high' => 7.41,
        'critical_low' => 7.20,
        'critical_high' => 7.60,
    ]);

    $patient = Patient::factory()->create(['gender' => 'female', 'date_of_birth' => now()->subYears(40)->toDateString()]);
    $encounter = ClinicalEncounter::factory()->for($patient, 'patient')->create();
    $order = LaboratoryOrder::factory()->for($encounter, 'clinicalEncounter')->create();
    $item = LaboratoryOrderItem::factory()->for($order, 'laboratoryOrder')->for($test, 'laboratoryTest')->create();

    // Venous sample
    $venousSample = LaboratorySample::factory()->for($order, 'laboratoryOrder')->create([
        'specimen_source' => 'venous',
    ]);
    $venousResult = LaboratoryResult::factory()->for($item, 'item')->for($venousSample, 'sample')->create([
        'laboratory_test_id' => $test->id,
    ]);

    $service = app(LaboratoryResultService::class);
    $service->syncParameterValues($venousResult, [
        ['parameter_id' => $phParam->id, 'value_numeric' => 7.33],
    ]);

    $venousResult->refresh();
    $venousRv = $venousResult->resultValues()->where('laboratory_test_parameter_id', $phParam->id)->first();
    expect($venousRv)->not->toBeNull();
    expect((float) $venousRv->reference_low)->toBe(7.31);
    expect((float) $venousRv->reference_high)->toBe(7.41);
    expect($venousRv->abnormal_flag)->toBe('normal');

    // Arterial sample
    $arterialItem = LaboratoryOrderItem::factory()->for($order, 'laboratoryOrder')->for($test, 'laboratoryTest')->create();
    $arterialSample = LaboratorySample::factory()->for($order, 'laboratoryOrder')->create([
        'specimen_source' => 'arterial',
    ]);
    $arterialResult = LaboratoryResult::factory()->for($arterialItem, 'item')->for($arterialSample, 'sample')->create([
        'laboratory_test_id' => $test->id,
    ]);

    $service->syncParameterValues($arterialResult, [
        ['parameter_id' => $phParam->id, 'value_numeric' => 7.33],
    ]);

    $arterialResult->refresh();
    $arterialRv = $arterialResult->resultValues()->where('laboratory_test_parameter_id', $phParam->id)->first();
    expect($arterialRv)->not->toBeNull();
    expect((float) $arterialRv->reference_low)->toBe(7.35);
    expect((float) $arterialRv->reference_high)->toBe(7.45);
    expect($arterialRv->abnormal_flag)->toBe('low');
});

test('result service infers specimen source from collection site text if specimen_source column is empty', function () {
    $test = activeLaboratoryTest();

    $phParam = LaboratoryTestParameter::factory()->for($test, 'laboratoryTest')->create([
        'code' => 'PH_INFER',
        'name' => 'pH Infer',
        'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
        'unit' => 'pH units',
    ]);

    LaboratoryReferenceRange::factory()->for($phParam, 'parameter')->create([
        'sex' => 'any',
        'specimen_source' => LaboratoryReferenceRange::SPECIMEN_SOURCE_ARTERIAL,
        'low' => 7.35,
        'high' => 7.45,
        'critical_low' => 7.20,
        'critical_high' => 7.60,
    ]);

    $patient = Patient::factory()->create(['gender' => 'male', 'date_of_birth' => now()->subYears(28)->toDateString()]);
    $encounter = ClinicalEncounter::factory()->for($patient, 'patient')->create();
    $order = LaboratoryOrder::factory()->for($encounter, 'clinicalEncounter')->create();
    $item = LaboratoryOrderItem::factory()->for($order, 'laboratoryOrder')->for($test, 'laboratoryTest')->create();

    // Sample has null specimen_source but collection_site has "Left radial artery"
    $sample = LaboratorySample::factory()->for($order, 'laboratoryOrder')->create([
        'specimen_source' => null,
        'collection_site' => 'Left radial artery blood draw',
    ]);

    $result = LaboratoryResult::factory()->for($item, 'item')->for($sample, 'sample')->create([
        'laboratory_test_id' => $test->id,
    ]);

    $service = app(LaboratoryResultService::class);
    $service->syncParameterValues($result, [
        ['parameter_id' => $phParam->id, 'value_numeric' => 7.38],
    ]);

    $rv = $result->resultValues()->where('laboratory_test_parameter_id', $phParam->id)->first();
    expect($rv)->not->toBeNull();
    expect((float) $rv->reference_low)->toBe(7.35);
    expect((float) $rv->reference_high)->toBe(7.45);
    expect($rv->abnormal_flag)->toBe('normal');
});
