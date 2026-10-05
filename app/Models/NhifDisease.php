<?php

namespace App\Models;

use Database\Factories\NhifDiseaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * NHIF disease reference, synced from OCS /api/Reference/GetDiseases.
 * Scheme-less: these diseases apply across all schemes.
 * Snapshot columns on EncounterDiagnosis hold the data at diagnosis time
 * — this table is only a search/sync target, not a FK source.
 */
class NhifDisease extends Model
{
    /** @use HasFactory<NhifDiseaseFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'disease_code',
        'disease_name',
        'icd_version_code',
        'synced_at',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }
}
