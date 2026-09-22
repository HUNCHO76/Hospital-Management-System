<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * NHIF package/benefit catalog item — synced from the NHIF API (Phase 8, Step 68).
 */
class NhifPackage extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'nhif_package_code',
        'nhif_package_name',
        'scheme_id',
        'scheme_name',
        'is_active',
        'synced_at',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }
}
