<?php

use App\Models\LaboratoryReferenceRange;
use App\Models\LaboratoryTestParameter;
use App\Services\LaboratoryFlagCalculator;

// ── flagNumeric ──────────────────────────────────────────────────────────────

test('value below critical_low yields critical flag', function () {
    $calc = app(LaboratoryFlagCalculator::class);

    expect($calc->flagNumeric(5.0, ['critical_low' => 7.0, 'critical_high' => 20.0, 'low' => 13.0, 'high' => 17.0]))
        ->toBe(LaboratoryFlagCalculator::FLAG_CRITICAL);
});

test('value above critical_high yields critical flag', function () {
    $calc = app(LaboratoryFlagCalculator::class);

    expect($calc->flagNumeric(22.0, ['critical_low' => 7.0, 'critical_high' => 20.0, 'low' => 13.0, 'high' => 17.0]))
        ->toBe(LaboratoryFlagCalculator::FLAG_CRITICAL);
});

test('value below low (but above critical_low) yields low flag', function () {
    $calc = app(LaboratoryFlagCalculator::class);

    expect($calc->flagNumeric(10.0, ['critical_low' => 7.0, 'critical_high' => 20.0, 'low' => 13.0, 'high' => 17.0]))
        ->toBe(LaboratoryFlagCalculator::FLAG_LOW);
});

test('value above high (but below critical_high) yields high flag', function () {
    $calc = app(LaboratoryFlagCalculator::class);

    expect($calc->flagNumeric(18.5, ['critical_low' => 7.0, 'critical_high' => 20.0, 'low' => 13.0, 'high' => 17.0]))
        ->toBe(LaboratoryFlagCalculator::FLAG_HIGH);
});

test('value within normal range yields normal flag', function () {
    $calc = app(LaboratoryFlagCalculator::class);

    expect($calc->flagNumeric(14.5, ['critical_low' => 7.0, 'critical_high' => 20.0, 'low' => 13.0, 'high' => 17.0]))
        ->toBe(LaboratoryFlagCalculator::FLAG_NORMAL);
});

test('null value yields normal flag regardless of range', function () {
    $calc = app(LaboratoryFlagCalculator::class);

    expect($calc->flagNumeric(null, ['critical_low' => 7.0, 'critical_high' => 20.0, 'low' => 13.0, 'high' => 17.0]))
        ->toBe(LaboratoryFlagCalculator::FLAG_NORMAL);
});

test('null range yields normal flag for any value', function () {
    $calc = app(LaboratoryFlagCalculator::class);

    expect($calc->flagNumeric(3.0, null))->toBe(LaboratoryFlagCalculator::FLAG_NORMAL);
});

test('range with null critical bounds still flags low and high correctly', function () {
    $calc = app(LaboratoryFlagCalculator::class);

    $range = ['critical_low' => null, 'critical_high' => null, 'low' => 80.0, 'high' => 100.0];

    expect($calc->flagNumeric(75.0, $range))->toBe(LaboratoryFlagCalculator::FLAG_LOW);
    expect($calc->flagNumeric(105.0, $range))->toBe(LaboratoryFlagCalculator::FLAG_HIGH);
    expect($calc->flagNumeric(90.0, $range))->toBe(LaboratoryFlagCalculator::FLAG_NORMAL);
});

test('range with null low/high bounds still flags critical correctly', function () {
    $calc = app(LaboratoryFlagCalculator::class);

    $range = ['critical_low' => 2.5, 'critical_high' => 6.5, 'low' => null, 'high' => null];

    expect($calc->flagNumeric(2.0, $range))->toBe(LaboratoryFlagCalculator::FLAG_CRITICAL);
    expect($calc->flagNumeric(7.0, $range))->toBe(LaboratoryFlagCalculator::FLAG_CRITICAL);
    expect($calc->flagNumeric(4.0, $range))->toBe(LaboratoryFlagCalculator::FLAG_NORMAL);
});

// ── flagOption ───────────────────────────────────────────────────────────────

test('option value in abnormal_options yields abnormal flag', function () {
    $parameter = LaboratoryTestParameter::factory()->option()->make([
        'abnormal_options' => ['positive'],
    ]);

    $calc = app(LaboratoryFlagCalculator::class);

    expect($calc->flagOption('positive', $parameter))->toBe(LaboratoryFlagCalculator::FLAG_ABNORMAL);
});

test('option value not in abnormal_options yields normal flag', function () {
    $parameter = LaboratoryTestParameter::factory()->option()->make([
        'abnormal_options' => ['positive'],
    ]);

    $calc = app(LaboratoryFlagCalculator::class);

    expect($calc->flagOption('negative', $parameter))->toBe(LaboratoryFlagCalculator::FLAG_NORMAL);
});

test('null option value yields normal flag', function () {
    $parameter = LaboratoryTestParameter::factory()->option()->make([
        'abnormal_options' => ['positive'],
    ]);

    $calc = app(LaboratoryFlagCalculator::class);

    expect($calc->flagOption(null, $parameter))->toBe(LaboratoryFlagCalculator::FLAG_NORMAL);
});

test('option parameter with null abnormal_options list treats everything as normal', function () {
    $parameter = LaboratoryTestParameter::factory()->option()->make([
        'abnormal_options' => null,
    ]);

    $calc = app(LaboratoryFlagCalculator::class);

    expect($calc->flagOption('positive', $parameter))->toBe(LaboratoryFlagCalculator::FLAG_NORMAL);
});

// ── flag() dispatcher ────────────────────────────────────────────────────────

test('flag() dispatches to flagNumeric for numeric parameters', function () {
    $parameter = LaboratoryTestParameter::factory()->make([
        'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
    ]);
    $range = LaboratoryReferenceRange::factory()->make([
        'low' => 13.0, 'high' => 17.0, 'critical_low' => 7.0, 'critical_high' => 20.0,
    ]);

    $calc = app(LaboratoryFlagCalculator::class);

    expect($calc->flag($parameter, '5.0', null, $range))->toBe(LaboratoryFlagCalculator::FLAG_CRITICAL);
    expect($calc->flag($parameter, '14.5', null, $range))->toBe(LaboratoryFlagCalculator::FLAG_NORMAL);
});

test('flag() dispatches to flagOption for option parameters', function () {
    $parameter = LaboratoryTestParameter::factory()->option()->make([
        'abnormal_options' => ['reactive'],
    ]);

    $calc = app(LaboratoryFlagCalculator::class);

    expect($calc->flag($parameter, null, 'reactive', null))->toBe(LaboratoryFlagCalculator::FLAG_ABNORMAL);
    expect($calc->flag($parameter, null, 'non_reactive', null))->toBe(LaboratoryFlagCalculator::FLAG_NORMAL);
});

test('flag() always returns normal for text parameters', function () {
    $parameter = LaboratoryTestParameter::factory()->text()->make();

    $calc = app(LaboratoryFlagCalculator::class);

    expect($calc->flag($parameter, null, null, null))->toBe(LaboratoryFlagCalculator::FLAG_NORMAL);
    expect($calc->flag($parameter, '99.0', null, null))->toBe(LaboratoryFlagCalculator::FLAG_NORMAL);
});

test('flag() with null range for numeric parameter returns normal', function () {
    $parameter = LaboratoryTestParameter::factory()->make([
        'data_type' => LaboratoryTestParameter::DATA_TYPE_NUMERIC,
    ]);

    $calc = app(LaboratoryFlagCalculator::class);

    expect($calc->flag($parameter, '3.0', null, null))->toBe(LaboratoryFlagCalculator::FLAG_NORMAL);
});
