<?php

namespace App\Models;

use Database\Factories\LaboratoryTestParameterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single measurable analyte / component within a laboratory test (Batch A).
 *
 * Single-analyte tests (Hemoglobin, Sodium) have exactly one parameter row.
 * Multi-component tests (Urinalysis, CSF analysis) have many. The parameter
 * table drives result entry, flag calculation, and analyzer import — no
 * hard-coded field lists exist anywhere in the result workflow.
 */
class LaboratoryTestParameter extends Model
{
    /** @use HasFactory<LaboratoryTestParameterFactory> */
    use HasFactory;

    public const DATA_TYPE_NUMERIC = 'numeric';

    public const DATA_TYPE_TEXT = 'text';

    public const DATA_TYPE_OPTION = 'option';

    public const DATA_TYPES = [
        self::DATA_TYPE_NUMERIC,
        self::DATA_TYPE_TEXT,
        self::DATA_TYPE_OPTION,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'laboratory_test_id',
        'code',
        'name',
        'unit',
        'data_type',
        'decimal_places',
        'options_json',
        'abnormal_options',
        'display_order',
        'is_calculated',
        'calculation_formula',
        'analyser_code',
        'loinc_code',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'options_json' => 'array',
            'abnormal_options' => 'array',
            'is_calculated' => 'boolean',
            'active' => 'boolean',
            'decimal_places' => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function laboratoryTest(): BelongsTo
    {
        return $this->belongsTo(LaboratoryTest::class);
    }

    public function referenceRanges(): HasMany
    {
        return $this->hasMany(LaboratoryReferenceRange::class);
    }
}
