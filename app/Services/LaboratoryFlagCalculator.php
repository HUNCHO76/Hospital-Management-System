<?php

namespace App\Services;

use App\Models\LaboratoryReferenceRange;
use App\Models\LaboratoryTestParameter;

/**
 * Calculates the abnormal_flag for a single result value against a reference
 * range snapshot (Batch A). The snapshot columns (low/high/critical_low/
 * critical_high) are passed directly — not fetched from the DB — so flag
 * calculation works identically on live entry and on historical result values.
 *
 * Priority order (most severe wins):
 *   critical > low/high > normal
 */
class LaboratoryFlagCalculator
{
    public const FLAG_NORMAL = 'normal';

    public const FLAG_LOW = 'low';

    public const FLAG_HIGH = 'high';

    public const FLAG_CRITICAL = 'critical';

    public const FLAG_ABNORMAL = 'abnormal';

    public const FLAGS = [
        self::FLAG_NORMAL,
        self::FLAG_LOW,
        self::FLAG_HIGH,
        self::FLAG_CRITICAL,
        self::FLAG_ABNORMAL,
    ];

    /**
     * Calculate the flag for a numeric value against an optional range.
     *
     * @param  array{critical_low?: string|float|null, critical_high?: string|float|null, low?: string|float|null, high?: string|float|null}|null  $range
     */
    public function flagNumeric(?float $value, ?array $range): string
    {
        if ($value === null || $range === null) {
            return self::FLAG_NORMAL;
        }

        $criticalLow = isset($range['critical_low']) ? (float) $range['critical_low'] : null;
        $criticalHigh = isset($range['critical_high']) ? (float) $range['critical_high'] : null;
        $low = isset($range['low']) ? (float) $range['low'] : null;
        $high = isset($range['high']) ? (float) $range['high'] : null;

        if ($criticalLow !== null && $value < $criticalLow) {
            return self::FLAG_CRITICAL;
        }

        if ($criticalHigh !== null && $value > $criticalHigh) {
            return self::FLAG_CRITICAL;
        }

        if ($low !== null && $value < $low) {
            return self::FLAG_LOW;
        }

        if ($high !== null && $value > $high) {
            return self::FLAG_HIGH;
        }

        return self::FLAG_NORMAL;
    }

    /**
     * Calculate the flag for an option-type value against a parameter's abnormal_options list.
     */
    public function flagOption(?string $value, LaboratoryTestParameter $parameter): string
    {
        if ($value === null) {
            return self::FLAG_NORMAL;
        }

        $abnormal = $parameter->abnormal_options ?? [];

        return in_array($value, $abnormal, true) ? self::FLAG_ABNORMAL : self::FLAG_NORMAL;
    }

    /**
     * Calculate the flag for a result value given a parameter and an optional
     * reference range model (nil = no structured range configured).
     */
    public function flag(
        LaboratoryTestParameter $parameter,
        ?string $valueNumeric,
        ?string $valueCode,
        ?LaboratoryReferenceRange $range,
    ): string {
        return match ($parameter->data_type) {
            LaboratoryTestParameter::DATA_TYPE_NUMERIC => $this->flagNumeric(
                $valueNumeric !== null ? (float) $valueNumeric : null,
                $range ? [
                    'critical_low' => $range->critical_low,
                    'critical_high' => $range->critical_high,
                    'low' => $range->low,
                    'high' => $range->high,
                ] : null,
            ),
            LaboratoryTestParameter::DATA_TYPE_OPTION => $this->flagOption($valueCode, $parameter),
            default => self::FLAG_NORMAL,
        };
    }
}
