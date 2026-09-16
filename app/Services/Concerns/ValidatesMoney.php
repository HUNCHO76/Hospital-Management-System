<?php

namespace App\Services\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * Server-side monetary validation (Phase 7, Step 52/53) — extracted here
 * once `ServicePricingService` needed the exact same rules
 * `ChargeService::validateMoney()` already enforced (reject negative/
 * non-numeric/`NaN`/`Infinity`/over-2-decimal-place amounts, spec §21/§40),
 * rather than a second, drifting copy.
 */
trait ValidatesMoney
{
    /**
     * @throws ValidationException
     */
    protected function validateMoney(mixed $value, string $field, bool $allowZero): float
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw ValidationException::withMessages([$field => ucfirst(str_replace('_', ' ', $field)).' must be a valid amount.']);
        }

        $amount = (float) $value;

        if ($allowZero ? $amount < 0 : $amount <= 0) {
            throw ValidationException::withMessages([$field => ucfirst(str_replace('_', ' ', $field)).' cannot be negative.']);
        }

        if (round($amount, 2) !== round($amount, 10)) {
            throw ValidationException::withMessages([$field => ucfirst(str_replace('_', ' ', $field)).' may not have more than 2 decimal places.']);
        }

        return round($amount, 2);
    }
}
