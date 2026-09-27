<?php

namespace App\Models;

use Database\Factories\LaboratoryReferenceRangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One age/sex-specific reference band for a laboratory test parameter (Batch A).
 *
 * Multiple rows per parameter implement pediatric / adult / neonatal bands.
 * LaboratoryReferenceRangeService selects the most-specific matching row at
 * result-entry time and snapshots low/high/critical into laboratory_result_values.
 */
class LaboratoryReferenceRange extends Model
{
    /** @use HasFactory<LaboratoryReferenceRangeFactory> */
    use HasFactory;

    public const SEX_MALE = 'male';

    public const SEX_FEMALE = 'female';

    public const SEX_ANY = 'any';

    public const SEXES = [self::SEX_MALE, self::SEX_FEMALE, self::SEX_ANY];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'laboratory_test_parameter_id',
        'sex',
        'age_min_days',
        'age_max_days',
        'low',
        'high',
        'critical_low',
        'critical_high',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'age_min_days' => 'integer',
            'age_max_days' => 'integer',
            'low' => 'decimal:4',
            'high' => 'decimal:4',
            'critical_low' => 'decimal:4',
            'critical_high' => 'decimal:4',
        ];
    }

    public function parameter(): BelongsTo
    {
        return $this->belongsTo(LaboratoryTestParameter::class, 'laboratory_test_parameter_id');
    }
}
