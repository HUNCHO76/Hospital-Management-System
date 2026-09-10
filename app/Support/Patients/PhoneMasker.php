<?php

namespace App\Support\Patients;

class PhoneMasker
{
    /**
     * Mask a phone number for display in a patient list/search result, where
     * the full number is not needed to identify the record — only the patient
     * profile (a deliberate, authorized view) shows it in full.
     *
     * Keeps the first 4 and last 2 characters, e.g. "0712345678" -> "0712**78".
     */
    public static function mask(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return $phone;
        }

        $length = mb_strlen($phone);

        if ($length <= 6) {
            return str_repeat('*', max($length - 2, 0)).mb_substr($phone, -2);
        }

        $visibleStart = mb_substr($phone, 0, 4);
        $visibleEnd = mb_substr($phone, -2);
        $maskedLength = $length - 6;

        return $visibleStart.str_repeat('*', $maskedLength).$visibleEnd;
    }
}
