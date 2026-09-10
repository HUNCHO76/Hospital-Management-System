<?php

namespace App\Models;

use Database\Factories\ClinicalNoteVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable, frozen snapshot of a ClinicalNote's content at the moment it
 * was signed or amended — the multi-version audit trail spec §9 requires for
 * notes specifically (no other clinical module in this app keeps a full
 * version table; they instead mutate the row in place and stamp
 * corrected_at/corrected_by/correction_reason). Never updated after creation.
 */
class ClinicalNoteVersion extends Model
{
    /** @use HasFactory<ClinicalNoteVersionFactory> */
    use HasFactory;

    /**
     * This table has no updated_at column — a version is written once and
     * never modified.
     */
    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'clinical_note_id',
        'version_number',
        'note_type',
        'note_content',
        'action',
        'author_id',
        'reason',
    ];

    /**
     * The note this version belongs to.
     */
    public function clinicalNote(): BelongsTo
    {
        return $this->belongsTo(ClinicalNote::class);
    }

    /**
     * The user who authored this version's content (the signer for the
     * first version, the amender for every subsequent one).
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
