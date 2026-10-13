<?php

namespace App\Services\Procurement;

use App\Models\StockBatch;
use App\Models\StockItem;
use App\Models\Store;
use App\Models\StoreIssue;
use App\Models\VendorInvoice;
use Illuminate\Support\Facades\DB;

class ProcurementReportService
{
    /**
     * Store Inventory Valuation Report
     */
    public function getStockValuationReport(int $hospitalId, ?int $storeId = null, ?string $category = null): array
    {
        $query = DB::table('stock_batches as sb')
            ->join('stock_items as si', 'sb.stock_item_id', '=', 'si.id')
            ->leftJoin('procurement_items as pi', 'si.procurement_item_id', '=', 'pi.id')
            ->leftJoin('medications as m', 'si.medication_id', '=', 'm.id')
            ->leftJoin('stores as s', 'sb.store_id', '=', 's.id')
            ->where('si.hospital_id', $hospitalId)
            ->where('sb.status', StockBatch::STATUS_ACTIVE)
            ->where('sb.quantity', '>', 0);

        if ($storeId) {
            $query->where('sb.store_id', $storeId);
        }

        if ($category) {
            $query->where('pi.category', $category);
        }

        $items = $query->select([
            's.id as store_id',
            's.name as store_name',
            'si.id as stock_item_id',
            DB::raw('COALESCE(pi.name, m.generic_name, "Unknown Item") as item_name'),
            DB::raw('COALESCE(pi.category, "medicine") as category'),
            DB::raw('COALESCE(pi.unit_of_measure, m.unit, "unit") as unit_of_measure'),
            'sb.batch_number',
            'sb.expiry_date',
            'sb.quantity',
            'sb.unit_cost',
            DB::raw('(sb.quantity * COALESCE(sb.unit_cost, 0)) as total_valuation'),
        ])
            ->orderBy('s.name')
            ->orderBy('item_name')
            ->orderBy('sb.expiry_date')
            ->get();

        $totalValue = $items->sum('total_valuation');
        $totalItems = $items->sum('quantity');

        return [
            'items' => $items,
            'summary' => [
                'total_quantity' => $totalItems,
                'total_valuation' => $totalValue,
                'batch_count' => $items->count(),
            ],
        ];
    }

    /**
     * Stock Expiry Analysis Report (batches expired, <30 days, <60 days, <90 days)
     */
    public function getStockExpiryReport(int $hospitalId, ?int $storeId = null): array
    {
        $today = now()->toDateString();
        $in30 = now()->addDays(30)->toDateString();
        $in60 = now()->addDays(60)->toDateString();
        $in90 = now()->addDays(90)->toDateString();

        $query = DB::table('stock_batches as sb')
            ->join('stock_items as si', 'sb.stock_item_id', '=', 'si.id')
            ->leftJoin('procurement_items as pi', 'si.procurement_item_id', '=', 'pi.id')
            ->leftJoin('medications as m', 'si.medication_id', '=', 'm.id')
            ->leftJoin('stores as s', 'sb.store_id', '=', 's.id')
            ->where('si.hospital_id', $hospitalId)
            ->where('sb.quantity', '>', 0)
            ->where('sb.expiry_date', '<=', $in90);

        if ($storeId) {
            $query->where('sb.store_id', $storeId);
        }

        $batches = $query->select([
            'sb.id',
            'sb.batch_number',
            'sb.expiry_date',
            'sb.quantity',
            'sb.unit_cost',
            'sb.status',
            's.name as store_name',
            DB::raw('COALESCE(pi.name, m.generic_name, "Unknown Item") as item_name'),
            DB::raw('COALESCE(pi.category, "medicine") as category'),
            DB::raw("CASE
                WHEN sb.expiry_date < '{$today}' THEN 'EXPIRED'
                WHEN sb.expiry_date <= '{$in30}' THEN 'EXPIRING_30_DAYS'
                WHEN sb.expiry_date <= '{$in60}' THEN 'EXPIRING_60_DAYS'
                ELSE 'EXPIRING_90_DAYS'
            END as expiry_risk"),
        ])
            ->orderBy('sb.expiry_date', 'asc')
            ->get();

        return [
            'batches' => $batches,
            'summary' => [
                'expired_count' => $batches->where('expiry_risk', 'EXPIRED')->count(),
                'expired_qty' => $batches->where('expiry_risk', 'EXPIRED')->sum('quantity'),
                'expiring_30_days_count' => $batches->where('expiry_risk', 'EXPIRING_30_DAYS')->count(),
                'expiring_60_days_count' => $batches->where('expiry_risk', 'EXPIRING_60_DAYS')->count(),
                'expiring_90_days_count' => $batches->where('expiry_risk', 'EXPIRING_90_DAYS')->count(),
            ],
        ];
    }

    /**
     * Low Stock / Reorder Alerts Report
     */
    public function getReorderAlertsReport(int $hospitalId, ?int $storeId = null): array
    {
        $sub = DB::table('stock_batches')
            ->select('stock_item_id', DB::raw('SUM(quantity) as current_stock'))
            ->where('status', StockBatch::STATUS_ACTIVE)
            ->groupBy('stock_item_id');

        $query = DB::table('stock_items as si')
            ->leftJoinSub($sub, 'batches', function ($join) {
                $join->on('si.id', '=', 'batches.stock_item_id');
            })
            ->leftJoin('procurement_items as pi', 'si.procurement_item_id', '=', 'pi.id')
            ->leftJoin('medications as m', 'si.medication_id', '=', 'm.id')
            ->leftJoin('stores as s', 'si.store_id', '=', 's.id')
            ->where('si.hospital_id', $hospitalId)
            ->where('si.status', StockItem::STATUS_ACTIVE)
            ->whereRaw('COALESCE(batches.current_stock, 0) <= COALESCE(si.reorder_level, pi.reorder_level, 0)');

        if ($storeId) {
            $query->where('si.store_id', $storeId);
        }

        $items = $query->select([
            'si.id as stock_item_id',
            's.name as store_name',
            DB::raw('COALESCE(pi.name, m.generic_name, "Unknown") as item_name'),
            DB::raw('COALESCE(pi.category, "medicine") as category'),
            DB::raw('COALESCE(pi.unit_of_measure, m.unit, "unit") as unit_of_measure'),
            DB::raw('COALESCE(si.reorder_level, pi.reorder_level, 0) as reorder_level'),
            DB::raw('COALESCE(batches.current_stock, 0) as current_stock'),
            DB::raw('(COALESCE(si.reorder_level, pi.reorder_level, 0) - COALESCE(batches.current_stock, 0)) as deficit'),
        ])
            ->orderByDesc('deficit')
            ->get();

        return [
            'alerts' => $items,
            'critical_count' => $items->where('current_stock', 0)->count(),
            'reorder_count' => $items->count(),
        ];
    }

    /**
     * 3-Way Matching Discrepancy Register
     */
    public function getInvoiceDiscrepancyReport(int $hospitalId): array
    {
        $invoices = VendorInvoice::with(['vendor', 'purchaseOrder', 'goodsReceiptNote', 'items'])
            ->where('hospital_id', $hospitalId)
            ->where('match_status', VendorInvoice::MATCH_STATUS_DISCREPANCY)
            ->orderBy('created_at', 'desc')
            ->get();

        return [
            'invoices' => $invoices,
            'count' => $invoices->count(),
            'total_amount' => $invoices->sum('grand_total'),
        ];
    }

    /**
     * Department Consumption Register
     */
    public function getDepartmentConsumptionReport(int $hospitalId, ?int $departmentId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $query = DB::table('store_issue_items as sii')
            ->join('store_issues as si', 'sii.store_issue_id', '=', 'si.id')
            ->join('departments as d', 'si.department_id', '=', 'd.id')
            ->leftJoin('procurement_items as pi', 'sii.procurement_item_id', '=', 'pi.id')
            ->where('si.hospital_id', $hospitalId)
            ->where('si.status', '!=', StoreIssue::STATUS_CANCELLED);

        if ($departmentId) {
            $query->where('si.department_id', $departmentId);
        }

        if ($startDate) {
            $query->whereDate('si.issued_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->whereDate('si.issued_at', '<=', $endDate);
        }

        $records = $query->select([
            'd.id as department_id',
            'd.name as department_name',
            'sii.procurement_item_id',
            'sii.item_name',
            DB::raw('COALESCE(pi.category, "general") as category'),
            DB::raw('SUM(sii.quantity_issued) as total_quantity_issued'),
            DB::raw('SUM(sii.quantity_issued * COALESCE(sii.unit_cost, 0)) as total_cost'),
        ])
            ->groupBy('d.id', 'd.name', 'sii.procurement_item_id', 'sii.item_name', 'pi.category')
            ->orderBy('d.name')
            ->orderByDesc('total_cost')
            ->get();

        return [
            'records' => $records,
            'total_consumption_value' => $records->sum('total_cost'),
            'total_items_issued' => $records->sum('total_quantity_issued'),
        ];
    }
}
