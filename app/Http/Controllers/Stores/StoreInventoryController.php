<?php

namespace App\Http\Controllers\Stores;

use App\Http\Controllers\Controller;
use App\Models\Hospital;
use App\Models\ProcurementItem;
use App\Models\StockBatch;
use App\Models\StockItem;
use App\Models\StockTransaction;
use App\Models\Store;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StoreInventoryController extends Controller
{
    public function __construct(protected AuditLogService $auditLog) {}

    protected function getHospitalId(Request $request): int
    {
        return $request->integer('hospital_id')
            ?: ($request->user()->staff?->hospital_id ?? Hospital::query()->value('id') ?? 1);
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('stores-inventory.view'), 403);

        $hospitalId = $this->getHospitalId($request);
        $storeId = $request->integer('store_id');
        $category = $request->string('category')->toString();
        $search = $request->string('search')->toString();

        $sub = DB::table('stock_batches')
            ->select('stock_item_id', DB::raw('SUM(quantity) as current_stock'), DB::raw('SUM(quantity * COALESCE(unit_cost, 0)) as total_value'))
            ->where('status', StockBatch::STATUS_ACTIVE)
            ->groupBy('stock_item_id');

        $query = StockItem::with(['store', 'procurementItem', 'medication'])
            ->leftJoinSub($sub, 'batches', function ($join) {
                $join->on('stock_items.id', '=', 'batches.stock_item_id');
            })
            ->where('stock_items.hospital_id', $hospitalId)
            ->select('stock_items.*', DB::raw('COALESCE(batches.current_stock, 0) as current_stock'), DB::raw('COALESCE(batches.total_value, 0) as total_value'));

        if ($storeId) {
            $query->where('stock_items.store_id', $storeId);
        }

        if ($category) {
            $query->whereHas('procurementItem', function ($q) use ($category) {
                $q->where('category', $category);
            });
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('procurementItem', function ($p) use ($search) {
                    $p->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                })->orWhereHas('medication', function ($m) use ($search) {
                    $m->where('generic_name', 'like', "%{$search}%")
                        ->orWhere('brand_name', 'like', "%{$search}%");
                });
            });
        }

        $items = $query->paginate(15)->withQueryString();
        $stores = Store::where('hospital_id', $hospitalId)->get(['id', 'name']);

        return Inertia::render('stores/inventory/index', [
            'items' => $items,
            'stores' => $stores,
            'categories' => ProcurementItem::CATEGORIES,
            'filters' => [
                'store_id' => $storeId,
                'category' => $category,
                'search' => $search,
            ],
        ]);
    }

    public function batches(Request $request): Response
    {
        abort_unless($request->user()->can('stores-batches.view'), 403);

        $hospitalId = $this->getHospitalId($request);
        $storeId = $request->integer('store_id');
        $status = $request->string('status')->toString();
        $search = $request->string('search')->toString();

        $query = StockBatch::with(['stockItem.procurementItem', 'stockItem.medication', 'store', 'vendor'])
            ->whereHas('stockItem', function ($q) use ($hospitalId) {
                $q->where('hospital_id', $hospitalId);
            });

        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('batch_number', 'like', "%{$search}%")
                    ->orWhereHas('stockItem.procurementItem', function ($pi) use ($search) {
                        $pi->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('stockItem.medication', function ($m) use ($search) {
                        $m->where('generic_name', 'like', "%{$search}%");
                    });
            });
        }

        $batches = $query->orderBy('expiry_date')->paginate(15)->withQueryString();
        $stores = Store::where('hospital_id', $hospitalId)->get(['id', 'name']);

        return Inertia::render('stores/inventory/batches', [
            'batches' => $batches,
            'stores' => $stores,
            'filters' => [
                'store_id' => $storeId,
                'status' => $status,
                'search' => $search,
            ],
        ]);
    }

    public function adjust(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('stores-inventory.adjust'), 403);

        $validated = $request->validate([
            'batch_id' => ['required', 'integer', 'exists:stock_batches,id'],
            'adjustment_type' => ['required', 'string', 'in:ADD,SUBTRACT,DAMAGE_WRITEOFF,EXPIRED_WRITEOFF'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $batch = StockBatch::lockForUpdate()->findOrFail($validated['batch_id']);
            $user = $request->user();

            $before = $batch->quantity;
            $delta = (int) $validated['quantity'];
            $type = $validated['adjustment_type'];

            if (in_array($type, ['SUBTRACT', 'DAMAGE_WRITEOFF', 'EXPIRED_WRITEOFF'], true)) {
                if ($before < $delta) {
                    return back()->with('error', "Cannot adjust below zero. Current batch stock is {$before}.");
                }
                $after = $before - $delta;
                $transQty = -$delta;
            } else {
                $after = $before + $delta;
                $transQty = $delta;
            }

            $newBatchStatus = $batch->status;
            if ($type === 'DAMAGE_WRITEOFF' && $after === 0) {
                $newBatchStatus = StockBatch::STATUS_DAMAGED;
            } elseif ($type === 'EXPIRED_WRITEOFF' && $after === 0) {
                $newBatchStatus = StockBatch::STATUS_EXPIRED;
            }

            $batch->update([
                'quantity' => $after,
                'status' => $newBatchStatus,
            ]);

            StockTransaction::create([
                'stock_item_id' => $batch->stock_item_id,
                'stock_batch_id' => $batch->id,
                'transaction_type' => StockTransaction::TYPE_ADJUSTMENT,
                'quantity' => $transQty,
                'balance_before' => $before,
                'balance_after' => $after,
                'reason' => "{$type}: ".$validated['reason'],
                'performed_by' => $user->id,
                'occurred_at' => now(),
            ]);

            $this->auditLog->record('stock_adjusted', $batch, ['quantity' => $before], ['quantity' => $after, 'reason' => $validated['reason']], $request);

            return back()->with('success', 'Stock adjustment recorded successfully.');
        });
    }
}
