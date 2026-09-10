<?php

namespace App\Support\Patients;

class PhoneNormalizer
{
    /**
     * Build the set of plausible stored-phone representations for a searched
     * phone number, so reasonable formatting differences (leading 0, +255,
     * bare 255, spaces/dashes) don't prevent identification. Matching is done
     * with an exact `whereIn` against these variants, keeping the lookup
     * index-friendly rather than a wildcard scan.
     *
     * @return list<string>
     */
    public static function variants(string $phone): array
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return [];
        }

        // Reduce to the "national significant number" (drop a leading 255
        // country code or a leading trunk 0), then rebuild each common form.
        $core = $digits;
        if (str_starts_with($core, '255')) {
            $core = substr($core, 3);
        } elseif (str_starts_with($core, '0')) {
            $core = substr($core, 1);
        }

        if ($core === '') {
            return array_values(array_unique([$digits]));
        }

        return array_values(array_unique([
            $digits,
            $core,
            '0'.$core,
            '255'.$core,
            '+255'.$core,
        ]));
    }
}
