<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;

trait GeneratesBusinessNumber
{
    /**
     * Generate the next sequential business number for the given prefix and year,
     * e.g. "PT-2026-000001".
     */
    protected static function generateBusinessNumber(string $prefix, string $column): string
    {
        return DB::transaction(function () use ($prefix, $column) {
            $pattern = "{$prefix}-".now()->year.'-';

            $last = static::query()
                ->where($column, 'like', "{$pattern}%")
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value($column);

            $sequence = $last ? ((int) substr($last, strlen($pattern))) + 1 : 1;

            return $pattern.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
        });
    }
}
