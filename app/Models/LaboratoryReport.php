<?php

namespace App\Models;

use App\Models\Concerns\GeneratesBusinessNumber;
use Database\Factories\LaboratoryReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One released VERSION of a laboratory order's report (Phase 4, Step 35) —
 * deliberately keyed to `LaboratoryOrder`, not `LaboratoryResult`: one order
 * may carry several independently-verified/authorized/released tests (Steps
 * 33-34 kept every action at that per-test granularity), but the printable
 * document a clinician actually wants is the whole order's results together
 * (spec Part 12-13's own "never mix results from different samples, but do
 * show every sample in one report" example). `results_snapshot` freezes
 * exactly what was released at that moment — created once per
 * `LaboratoryResultService::release()`/report-regeneration call
 * (LaboratoryReportService::generateForOrder()) and never mutated after
 * that; a later amendment creates a NEW version rather than changing this
 * one, so a released report can never be silently rewritten.
 */
class LaboratoryReport extends Model
{
    /** @use HasFactory<LaboratoryReportFactory> */
    use GeneratesBusinessNumber, HasFactory;

    /**
     * The prefix used when generating report numbers.
     */
    public const NUMBER_PREFIX = 'LAB-RPT';

    /**
     * Only the most recently generated version for a given order is
     * "active" (the one clinicians should see by default); every earlier
     * version becomes "superseded" the moment a newer one is generated —
     * or immediately, without waiting for a new version to exist yet, the
     * moment an already-released result is pulled back for amendment (spec
     * Part 17 — a report known to contain a value under correction must
     * never keep presenting as current). Superseded versions are never
     * deleted; they remain fully viewable as historical record (spec Part
     * 37 — "View Previous Version").
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_SUPERSEDED,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'laboratory_order_id',
        'report_number',
        'version',
        'status',
        'results_snapshot',
        'released_by',
        'released_at',
        'printed_by',
        'printed_at',
        'downloaded_by',
        'downloaded_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'results_snapshot' => 'array',
            'released_at' => 'datetime',
            'printed_at' => 'datetime',
            'downloaded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $report): void {
            if (empty($report->report_number)) {
                $report->report_number = static::generateBusinessNumber(self::NUMBER_PREFIX, 'report_number');
            }
        });
    }

    /**
     * The order this report was generated for.
     */
    public function laboratoryOrder(): BelongsTo
    {
        return $this->belongsTo(LaboratoryOrder::class);
    }

    /**
     * The user who released this report version.
     */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    /**
     * The user who most recently printed this report version, if any.
     */
    public function printedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }

    /**
     * The user who most recently downloaded this report version, if any.
     */
    public function downloadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'downloaded_by');
    }
}
