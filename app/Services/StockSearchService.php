<?php

namespace App\Services;

use App\Models\StockBatch;
use App\Models\StockItem;
use App\Models\StockTransaction;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Builds the pharmacy stock item search/filter query (Phase 6, Step 49) —
 * mirrors MedicationSearchService/DispensingSearchService's "one query
 * builder, several callers" shape. Availability, low-stock, and
 * expiring/expired classification are all expressed as correlated SQL
 * subqueries against `stock_batches` rather than loaded into PHP and
 * filtered there (spec §23/§46: server-side filtering, avoid loading
 * thousands of rows). `dashboardCount()` reuses this same query builder for
 * every dashboard tile, so the classification logic exists in exactly one
 * place.
 */
class StockSearchService
{
    /**
     * Supported keys: search (medication code/generic/brand name), status
     * ('' = all, 'low_stock', 'out_of_stock', 'expiring_soon', 'expired',
     * 'quarantined').
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<StockItem>
     */
    public function itemsQuery(array $filters): Builder
    {
        $today = now()->toDateString();
        $warningDate = now()->addDays((int) config('pharmacy.stock_expiry_warning_days'))->toDateString();

        $query = StockItem::query()
            ->join('medications', 'medications.id', '=', 'stock_items.medication_id')
            ->select('stock_items.*')
            ->selectRaw('(SELECT COALESCE(SUM(quantity), 0) FROM stock_batches WHERE stock_batches.stock_item_id = stock_items.id) as total_quantity')
            ->selectRaw(
                "(SELECT COALESCE(SUM(quantity), 0) FROM stock_batches WHERE stock_batches.stock_item_id = stock_items.id AND status = 'active' AND expiry_date >= ?) as available_quantity",
                [$today],
            )
            ->selectRaw(
                "(SELECT MIN(expiry_date) FROM stock_batches WHERE stock_batches.stock_item_id = stock_items.id AND status = 'active' AND quantity > 0) as nearest_expiry_date",
            )
            ->with(['medication:id,code,generic_name,brand_name,category,form,unit', 'hospital:id,name']);

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('medications.generic_name', 'like', "%{$search}%")
                    ->orWhere('medications.brand_name', 'like', "%{$search}%")
                    ->orWhere('medications.code', 'like', "%{$search}%");
            });
        }

        match ($filters['status'] ?? '') {
            'low_stock' => $query->whereRaw(
                "(SELECT COALESCE(SUM(quantity), 0) FROM stock_batches WHERE stock_batches.stock_item_id = stock_items.id AND status = 'active' AND expiry_date >= ?) <= stock_items.reorder_level",
                [$today],
            ),
            'out_of_stock' => $query->whereRaw(
                "(SELECT COALESCE(SUM(quantity), 0) FROM stock_batches WHERE stock_batches.stock_item_id = stock_items.id AND status = 'active' AND expiry_date >= ?) = 0",
                [$today],
            ),
            'expiring_soon' => $query->whereExists(function ($query) use ($today, $warningDate) {
                $query->select(DB::raw(1))->from('stock_batches')
                    ->whereColumn('stock_batches.stock_item_id', 'stock_items.id')
                    ->where('status', StockBatch::STATUS_ACTIVE)
                    ->where('quantity', '>', 0)
                    ->whereBetween('expiry_date', [$today, $warningDate]);
            }),
            'expired' => $query->whereExists(function ($query) use ($today) {
                $query->select(DB::raw(1))->from('stock_batches')
                    ->whereColumn('stock_batches.stock_item_id', 'stock_items.id')
                    ->where('quantity', '>', 0)
                    ->where(fn ($query) => $query->where('status', StockBatch::STATUS_EXPIRED)->orWhere('expiry_date', '<', $today));
            }),
            'quarantined' => $query->whereExists(function ($query) {
                $query->select(DB::raw(1))->from('stock_batches')
                    ->whereColumn('stock_batches.stock_item_id', 'stock_items.id')
                    ->where('status', StockBatch::STATUS_QUARANTINED);
            }),
            // Unlike 'expiring_soon'/'expired', no `quantity > 0` check: a
            // DAMAGED batch's quantity is always 0 by the time
            // StockService::adjust() sets that status (the write-off empties
            // it), so requiring a positive quantity would make this branch
            // never match anything.
            'damaged' => $query->whereExists(function ($query) {
                $query->select(DB::raw(1))->from('stock_batches')
                    ->whereColumn('stock_batches.stock_item_id', 'stock_items.id')
                    ->where('status', StockBatch::STATUS_DAMAGED);
            }),
            default => null,
        };

        return $query->orderBy('medications.generic_name');
    }

    /**
     * Restrict a query() result to the given hospital's own stock.
     *
     * @param  Builder<StockItem>  $query
     * @return Builder<StockItem>
     */
    public function scopeToHospital(Builder $query, ?int $hospitalId): Builder
    {
        if ($hospitalId === null) {
            return $query;
        }

        return $query->where('stock_items.hospital_id', $hospitalId);
    }

    /**
     * The number of stock items matching the given filters, scoped to a
     * hospital — reuses itemsQuery() so dashboard tile counts and the
     * searchable list's own filter buttons can never drift apart.
     *
     * @param  array<string, mixed>  $filters
     */
    public function count(array $filters, ?int $hospitalId): int
    {
        $query = $this->itemsQuery($filters);
        $this->scopeToHospital($query, $hospitalId);

        return $query->count();
    }

    /**
     * Build the cross-medication batch report query (Phase 6, Step 50, spec
     * §7) — every StockBatch joined back to its medication, unlike
     * itemsQuery()'s per-stock-item aggregate rows. Supported keys: search
     * (medication code/generic/brand name or batch_number), status ('' =
     * all, 'active', 'quarantined', 'expired', 'damaged', 'depleted',
     * 'expiring_soon' — 'expired' also catches an ACTIVE batch whose
     * expiry_date has silently passed, mirroring itemsQuery()'s own
     * 'expired' branch so the two reports never disagree on what counts as
     * expired), expiry_from/expiry_to.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<StockBatch>
     */
    public function batchesQuery(array $filters): Builder
    {
        $today = now()->toDateString();
        $warningDate = now()->addDays((int) config('pharmacy.stock_expiry_warning_days'))->toDateString();

        $query = StockBatch::query()
            ->join('stock_items', 'stock_items.id', '=', 'stock_batches.stock_item_id')
            ->join('medications', 'medications.id', '=', 'stock_items.medication_id')
            ->select('stock_batches.*')
            ->with(['stockItem.medication:id,code,generic_name,brand_name,category,form,unit', 'stockItem.hospital:id,name']);

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('medications.generic_name', 'like', "%{$search}%")
                    ->orWhere('medications.brand_name', 'like', "%{$search}%")
                    ->orWhere('medications.code', 'like', "%{$search}%")
                    ->orWhere('stock_batches.batch_number', 'like', "%{$search}%");
            });
        }

        match ($filters['status'] ?? '') {
            'expiring_soon' => $query->where('stock_batches.status', StockBatch::STATUS_ACTIVE)
                ->whereBetween('stock_batches.expiry_date', [$today, $warningDate]),
            'expired' => $query->where(fn ($query) => $query->where('stock_batches.status', StockBatch::STATUS_EXPIRED)
                ->orWhere(fn ($query) => $query->where('stock_batches.status', StockBatch::STATUS_ACTIVE)->where('stock_batches.expiry_date', '<', $today))),
            'active', 'quarantined', 'damaged', 'depleted' => $query->where('stock_batches.status', $filters['status']),
            default => null,
        };

        if (filled($filters['expiry_from'] ?? null)) {
            $query->whereDate('stock_batches.expiry_date', '>=', $filters['expiry_from']);
        }

        if (filled($filters['expiry_to'] ?? null)) {
            $query->whereDate('stock_batches.expiry_date', '<=', $filters['expiry_to']);
        }

        return $query->orderBy('stock_batches.expiry_date');
    }

    /**
     * Restrict a batchesQuery() result to the given hospital's own stock.
     *
     * @param  Builder<StockBatch>  $query
     * @return Builder<StockBatch>
     */
    public function scopeBatchesToHospital(Builder $query, ?int $hospitalId): Builder
    {
        if ($hospitalId === null) {
            return $query;
        }

        return $query->where('stock_items.hospital_id', $hospitalId);
    }

    /**
     * Build the stock movement ledger report query (Phase 6, Step 50, spec
     * §10) — the immutable StockTransaction log itself, never re-derived.
     * Supported keys: search (medication name or transaction/batch number),
     * transaction_type, medication_id, date_from/date_to (against
     * occurred_at), performed_by.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<StockTransaction>
     */
    public function movementsQuery(array $filters): Builder
    {
        $query = StockTransaction::query()
            ->join('stock_items', 'stock_items.id', '=', 'stock_transactions.stock_item_id')
            ->select('stock_transactions.*')
            ->with(['stockItem.medication:id,code,generic_name,brand_name', 'stockBatch:id,batch_number', 'performedBy:id,UserName']);

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('stock_transactions.transaction_number', 'like', "%{$search}%")
                    ->orWhereHas('stockBatch', fn ($query) => $query->where('batch_number', 'like', "%{$search}%"))
                    ->orWhereHas('stockItem.medication', fn ($query) => $query->where('generic_name', 'like', "%{$search}%"));
            });
        }

        if (filled($filters['transaction_type'] ?? null)) {
            $query->where('stock_transactions.transaction_type', $filters['transaction_type']);
        }

        if (filled($filters['medication_id'] ?? null)) {
            $query->where('stock_items.medication_id', $filters['medication_id']);
        }

        if (filled($filters['performed_by'] ?? null)) {
            $query->where('stock_transactions.performed_by', $filters['performed_by']);
        }

        if (filled($filters['date_from'] ?? null)) {
            $query->whereDate('stock_transactions.occurred_at', '>=', $filters['date_from']);
        }

        if (filled($filters['date_to'] ?? null)) {
            $query->whereDate('stock_transactions.occurred_at', '<=', $filters['date_to']);
        }

        return $query->orderByDesc('stock_transactions.occurred_at');
    }

    /**
     * Restrict a movementsQuery() result to the given hospital's own stock.
     *
     * @param  Builder<StockTransaction>  $query
     * @return Builder<StockTransaction>
     */
    public function scopeMovementsToHospital(Builder $query, ?int $hospitalId): Builder
    {
        if ($hospitalId === null) {
            return $query;
        }

        return $query->where('stock_items.hospital_id', $hospitalId);
    }
}
