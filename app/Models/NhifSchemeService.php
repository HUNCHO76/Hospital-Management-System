<?php

namespace App\Models;

use Database\Factories\NhifSchemeServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single NHIF service item that is covered under a specific NHIF scheme.
 * Synced from /api/Approvals/GetAllowedItems per scheme.
 */
class NhifSchemeService extends Model
{
    /** @use HasFactory<NhifSchemeServiceFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'scheme_id',
        'scheme_name',
        'item_code',
        'item_name',
        'item_type_id',
        'type_name',
        'unit_price',
        'dosage',
        'strength',
        'start_date',
        'end_date',
        'is_active',
        'synced_at',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
            'start_date' => 'date',
            'end_date' => 'date',
            'unit_price' => 'decimal:2',
            'raw_data' => 'array',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('start_date')->orWhere('start_date', '<=', today()))
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>=', today()));
    }

    /**
     * Query from the catalog dump table (`schemes_services`), date-filtered.
     * This table is populated via SQL import and lacks `is_active`/`unit_price`.
     */
    public function scopeCatalog(Builder $query): void
    {
        $query->from('schemes_services')
            ->where(fn (Builder $q) => $q->whereNull('start_date')->orWhere('start_date', '<=', today()))
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>=', today()));
    }
}
