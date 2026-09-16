<?php

namespace App\Http\Controllers;

use App\Http\Requests\Stock\AdjustStockRequest;
use App\Http\Requests\Stock\QuarantineStockRequest;
use App\Http\Requests\Stock\ReceiveStockRequest;
use App\Http\Resources\StockItemResource;
use App\Http\Resources\StockTransactionResource;
use App\Models\Hospital;
use App\Models\Medication;
use App\Models\StockBatch;
use App\Models\StockItem;
use App\Models\StockTransaction;
use App\Services\StockSearchService;
use App\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The pharmacy stock (inventory) module (Phase 6, Step 49) — dashboard,
 * searchable stock list, per-item detail (batches + ledger), receiving, and
 * batch corrections (adjust/quarantine/release). Every mutating action is
 * kept thin: validation lives in the Form Request, authorization in
 * StockItemPolicy, and every actual write in StockService — mirrors
 * DispensingController's own shape exactly.
 */
class StockController extends Controller
{
    public function index(Request $request, StockSearchService $search): Response
    {
        $this->authorize('viewAny', StockItem::class);

        $hospitalId = $this->scopedHospitalId($request);

        $lowStockItems = $search->itemsQuery(['status' => 'low_stock']);
        $search->scopeToHospital($lowStockItems, $hospitalId);

        $expiringItems = $search->itemsQuery(['status' => 'expiring_soon']);
        $search->scopeToHospital($expiringItems, $hospitalId);

        $recentTransactions = StockTransaction::query()
            ->whereHas('stockItem', fn ($query) => $search->scopeToHospital($query, $hospitalId))
            ->with(['stockItem.medication:id,generic_name', 'stockBatch:id,batch_number', 'performedBy:id,UserName'])
            ->latest('occurred_at')
            ->limit(10)
            ->get();

        return Inertia::render('pharmacy/stock/index', [
            'counts' => [
                'total_stock_items' => $search->count([], $hospitalId),
                'total_medications' => Medication::query()->where('status', Medication::STATUS_ACTIVE)->count(),
                'low_stock' => $search->count(['status' => 'low_stock'], $hospitalId),
                'out_of_stock' => $search->count(['status' => 'out_of_stock'], $hospitalId),
                'expiring_soon' => $search->count(['status' => 'expiring_soon'], $hospitalId),
                'expired' => $search->count(['status' => 'expired'], $hospitalId),
                'quarantined' => $search->count(['status' => 'quarantined'], $hospitalId),
            ],
            'lowStockItems' => StockItemResource::collection($lowStockItems->limit(10)->get())->resolve($request),
            'expiringItems' => StockItemResource::collection($expiringItems->limit(10)->get())->resolve($request),
            'recentTransactions' => StockTransactionResource::collection($recentTransactions)->resolve($request),
        ]);
    }

    public function items(Request $request, StockSearchService $search): Response
    {
        $this->authorize('viewAny', StockItem::class);

        $filters = $request->only(['search', 'status']);

        $query = $search->itemsQuery($filters);
        $search->scopeToHospital($query, $this->scopedHospitalId($request));

        $items = $query->paginate(20)->withQueryString()
            ->through(fn (StockItem $item) => (new StockItemResource($item))->resolve($request));

        return Inertia::render('pharmacy/stock/items/index', [
            'items' => $items,
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, StockItem $stockItem): Response
    {
        $this->authorize('view', $stockItem);

        $stockItem->load(['hospital:id,name', 'medication']);
        $stockItem->load(['batches' => fn ($query) => $query->orderBy('expiry_date')]);

        $canViewTransactions = $request->user()->can('viewTransactions', $stockItem);

        $transactions = $canViewTransactions
            ? StockTransaction::query()
                ->where('stock_item_id', $stockItem->id)
                ->with(['stockBatch:id,batch_number', 'performedBy:id,UserName'])
                ->latest('occurred_at')
                ->limit(100)
                ->get()
            : collect();

        return Inertia::render('pharmacy/stock/items/show', [
            'item' => (new StockItemResource($stockItem))->resolve($request),
            'transactions' => StockTransactionResource::collection($transactions)->resolve($request),
            'canAdjust' => $request->user()->can('adjust', $stockItem),
            'canQuarantine' => $request->user()->can('quarantine', $stockItem),
            'canRelease' => $request->user()->can('release', $stockItem),
            'canViewTransactions' => $canViewTransactions,
        ]);
    }

    public function receiveForm(Request $request): Response
    {
        abort_unless($request->user()->can('pharmacy-stock.receive'), 403);

        $hospitals = $request->user()->hasRole('hospital-admin')
            ? Hospital::query()->whereKey($this->scopedHospitalId($request))->select('id', 'name')->get()
            : Hospital::query()->where('status', Hospital::STATUS_ACTIVE)->select('id', 'name')->orderBy('name')->get();

        return Inertia::render('pharmacy/stock/receive/index', [
            'hospitals' => $hospitals,
        ]);
    }

    public function receive(ReceiveStockRequest $request, StockService $stock): RedirectResponse
    {
        $data = $request->validated();

        $this->authorize('receiveInto', [StockItem::class, (int) $data['hospital_id']]);

        $batch = $stock->receive((int) $data['hospital_id'], $data, $request);

        return redirect()->route('pharmacy-stock.items.show', $batch->stock_item_id)->with('success', 'Stock received.');
    }

    public function adjust(AdjustStockRequest $request, StockBatch $stockBatch, StockService $stock): RedirectResponse
    {
        $stockBatch->load('stockItem');
        $this->authorize('adjust', $stockBatch->stockItem);

        $data = $request->validated();
        $stock->adjust($stockBatch, $data['type'], (int) $data['quantity'], $data['reason'], $request);

        return redirect()->route('pharmacy-stock.items.show', $stockBatch->stock_item_id)->with('success', 'Stock adjusted.');
    }

    public function quarantine(QuarantineStockRequest $request, StockBatch $stockBatch, StockService $stock): RedirectResponse
    {
        $stockBatch->load('stockItem');
        $this->authorize('quarantine', $stockBatch->stockItem);

        $stock->quarantine($stockBatch, $request->validated()['reason'], $request);

        return redirect()->route('pharmacy-stock.items.show', $stockBatch->stock_item_id)->with('success', 'Batch quarantined.');
    }

    public function release(Request $request, StockBatch $stockBatch, StockService $stock): RedirectResponse
    {
        $stockBatch->load('stockItem');
        $this->authorize('release', $stockBatch->stockItem);

        $stock->release($stockBatch, $request->string('note')->value() ?: null, $request);

        return redirect()->route('pharmacy-stock.items.show', $stockBatch->stock_item_id)->with('success', 'Batch released.');
    }

    /**
     * The hospital a hospital-admin is restricted to, or null when the user
     * is unrestricted (super-admin and any other unscoped role).
     */
    protected function scopedHospitalId(Request $request): ?int
    {
        if (! $request->user()->hasRole('hospital-admin')) {
            return null;
        }

        return $request->user()->staff?->hospital_id;
    }
}
