<?php

namespace App\Models;

use App\Models\Concerns\HasStatus;
use Database\Factories\DiagnosisCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable diagnosis/coding-system catalog entry (e.g. an ICD-10 code) —
 * master data, not a clinical record. An EncounterDiagnosis references a row
 * here rather than duplicating its code/description, keeping "clinical
 * diagnosis" (the selection, per encounter) separate from "coding" (the
 * catalog itself), per spec §9.
 */
class DiagnosisCode extends Model
{
    /** @use HasFactory<DiagnosisCodeFactory> */
    use HasFactory, HasStatus;

    /**
     * Status values recognized for a diagnosis code.
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * The coding system recognized by default. No separate coding_systems
     * table exists — see class docs on DiagnosisCode's migration for why.
     */
    public const CODING_SYSTEM_ICD10 = 'icd-10';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'coding_system',
        'code',
        'description',
        'category',
        'status',
    ];

    /**
     * The encounter diagnoses recorded against this code.
     */
    public function encounterDiagnoses(): HasMany
    {
        return $this->hasMany(EncounterDiagnosis::class);
    }
}
