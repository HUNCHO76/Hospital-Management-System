<?php

namespace App\Models;

use Database\Factories\LaboratoryResultValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single parameter value within a multi-component laboratory result (Phase 4 Batch B).
 *
 * One row per (LaboratoryResult × LaboratoryTestParameter) pair. Reference range
 * columns (reference_low/high/critical_*) are snapshotted at save time so
 * historical flags remain correct even when catalog ranges change later.
 *
 * Single-analyte legacy tests that store their result directly on
 * LaboratoryResult (value_numeric/value_text/value_code) do NOT produce rows
 * here — this table is only populated for tests that have parameters configured.
 */
class LaboratoryResultValue extends Model
{
    /** @use HasFactory<LaboratoryResultValueFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'laboratory_result_id',
        'laboratory_test_parameter_id',
        'value_numeric',
        'value_text',
        'value_code',
        'unit',
        'reference_low',
        'reference_high',
        'reference_critical_low',
        'reference_critical_high',
        'abnormal_flag',
        'result_comment',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_numeric' => 'decimal:4',
            'reference_low' => 'decimal:4',
            'reference_high' => 'decimal:4',
            'reference_critical_low' => 'decimal:4',
            'reference_critical_high' => 'decimal:4',
        ];
    }

    public function laboratoryResult(): BelongsTo
    {
        return $this->belongsTo(LaboratoryResult::class);
    }

    public function parameter(): BelongsTo
    {
        return $this->belongsTo(LaboratoryTestParameter::class, 'laboratory_test_parameter_id');
    }

    public function hasValue(): bool
    {
        return $this->value_numeric !== null
            || ($this->value_text !== null && trim($this->value_text) !== '')
            || ($this->value_code !== null && $this->value_code !== '');
    }
}
