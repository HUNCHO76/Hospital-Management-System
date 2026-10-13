<?php

namespace App\Http\Controllers\Stores;

use App\Http\Controllers\Controller;
use App\Models\DepartmentStockRequest;
use App\Models\GoodsReceiptNote;
use App\Models\Hospital;
use App\Models\StockBatch;
use App\Models\StockItem;
use App\Models\Store;
use App\Models\StoreIssue;
use App\Models\StoreTransfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StoreDashboardController extends Controller
{
    protected function getHospitalId(Request $request): int
    {
        return $request->integer('hospital_id')
            ?: ($request->user()->staff?->hospital_id ?? Hospital::query()->value('id') ?? 1);
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('stores.view'), 403);

        $hospitalId = $this->getHospitalId($request);

        $totalValuation = (float) DB::table('stock_batches as sb')
            ->join('stock_items as si', 'sb.stock_item_id', '=', 'si.id')
            ->where('si.hospital_id', $hospitalId)
            ->where('sb.status', StockBatch::STATUS_ACTIVE)
            ->where('sb.quantity', '>', 0)
            ->sum(DB::raw('sb.quantity * COALESCE(sb.unit_cost, 0)'));

        $totalStockItems = StockItem::where('hospital_id', $hospitalId)->count();

        $pendingGrnInspections = GoodsReceiptNote::where('hospital_id', $hospitalId)
            ->where('status', GoodsReceiptNote::STATUS_INSPECTION_PENDING)
            ->count();

        $pendingRequests = DepartmentStockRequest::where('hospital_id', $hospitalId)
            ->where('status', DepartmentStockRequest::STATUS_SUBMITTED)
            ->count();

        $expiringBatchesCount = DB::table('stock_batches as sb')
            ->join('stock_items as si', 'sb.stock_item_id', '=', 'si.id')
            ->where('si.hospital_id', $hospitalId)
            ->where('sb.quantity', '>', 0)
            ->where('sb.expiry_date', '<=', now()->addDays(60)->toDateString())
            ->count();

        $stores = Store::where('hospital_id', $hospitalId)->get();

        $recentGrns = GoodsReceiptNote::with(['vendor', 'store'])
            ->where('hospital_id', $hospitalId)
            ->latest()
            ->limit(5)
            ->get();

        $recentIssues = StoreIssue::with(['department', 'fromStore'])
            ->where('hospital_id', $hospitalId)
            ->latest()
            ->limit(5)
            ->get();

        $recentTransfers = StoreTransfer::with(['fromStore', 'toStore'])
            ->where('hospital_id', $hospitalId)
            ->latest()
            ->limit(5)
            ->get();

        return Inertia::render('stores/dashboard', [
            'metrics' => [
                'total_valuation' => $totalValuation,
                'total_items' => $totalStockItems,
                'pending_grns' => $pendingGrnInspections,
                'pending_requests' => $pendingRequests,
                'expiring_batches' => $expiringBatchesCount,
            ],
            'stores' => $stores,
            'recent_grns' => $recentGrns,
            'recent_issues' => $recentIssues,
            'recent_transfers' => $recentTransfers,
        ]);
    }
}
