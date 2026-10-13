<?php

namespace App\Http\Controllers\Stores;

use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\GoodsReceiptNoteRequest;
use App\Models\GoodsReceiptNote;
use App\Models\Hospital;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Services\Procurement\GoodsReceiptNoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GoodsReceiptNoteController extends Controller
{
    protected function getHospitalId(Request $request): int
    {
        return $request->integer('hospital_id')
            ?: ($request->user()->staff?->hospital_id ?? Hospital::query()->value('id') ?? 1);
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('stores-grn.view'), 403);

        $hospitalId = $this->getHospitalId($request);
        $status = $request->string('status')->toString();
        $storeId = $request->integer('store_id');
        $search = $request->string('search')->toString();

        $query = GoodsReceiptNote::with(['purchaseOrder', 'vendor', 'store', 'receivedBy'])
            ->where('hospital_id', $hospitalId);

        if ($status) {
            $query->where('status', $status);
        }

        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('grn_number', 'like', "%{$search}%")
                    ->orWhere('delivery_note_number', 'like', "%{$search}%")
                    ->orWhere('invoice_reference', 'like', "%{$search}%");
            });
        }

        $grns = $query->latest()->paginate(15)->withQueryString();
        $stores = Store::where('hospital_id', $hospitalId)->get(['id', 'name']);

        return Inertia::render('stores/grn/index', [
            'grns' => $grns,
            'stores' => $stores,
            'filters' => [
                'status' => $status,
                'store_id' => $storeId,
                'search' => $search,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can('stores-grn.create'), 403);

        $hospitalId = $this->getHospitalId($request);
        $purchaseOrderId = $request->integer('purchase_order_id');

        $purchaseOrders = PurchaseOrder::with(['vendor', 'receivingStore', 'items.procurementItem'])
            ->where('hospital_id', $hospitalId)
            ->whereIn('status', [
                PurchaseOrder::STATUS_APPROVED,
                PurchaseOrder::STATUS_SENT_TO_VENDOR,
                PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            ])
            ->latest()
            ->get();

        $stores = Store::where('hospital_id', $hospitalId)->where('status', 'active')->get(['id', 'name']);
        $selectedPo = $purchaseOrderId ? $purchaseOrders->firstWhere('id', $purchaseOrderId) : null;

        return Inertia::render('stores/grn/create', [
            'purchase_orders' => $purchaseOrders,
            'stores' => $stores,
            'selected_po' => $selectedPo,
        ]);
    }

    public function store(GoodsReceiptNoteRequest $request, GoodsReceiptNoteService $service): RedirectResponse
    {
        abort_unless($request->user()->can('stores-grn.create'), 403);

        $data = $request->validated();
        $grn = $service->create($data, $request);

        return redirect()->route('stores.grn.show', $grn->id)
            ->with('success', 'Goods Receipt Note created. Stock is held pending inspection.');
    }

    public function show(GoodsReceiptNote $grn, Request $request): Response
    {
        abort_unless($request->user()->can('stores-grn.view'), 403);

        $grn->load([
            'purchaseOrder.items',
            'vendor',
            'store',
            'items.procurementItem',
            'items.stockBatch',
            'receivedBy',
            'inspectedBy',
            'postedBy',
            'approvalHistories.user',
        ]);

        return Inertia::render('stores/grn/show', [
            'grn' => $grn,
        ]);
    }

    public function inspect(GoodsReceiptNote $grn, Request $request, GoodsReceiptNoteService $service): RedirectResponse
    {
        abort_unless($request->user()->can('stores-grn.inspect'), 403);

        $validated = $request->validate([
            'inspection_notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity_accepted' => ['required', 'integer', 'min:0'],
            'items.*.quantity_rejected' => ['required', 'integer', 'min:0'],
            'items.*.rejection_reason' => ['nullable', 'string', 'max:500'],
            'items.*.batch_number' => ['nullable', 'string', 'max:100'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'items.*.storage_location' => ['nullable', 'string', 'max:100'],
        ]);

        $service->inspect($grn, $validated['items'], $validated['inspection_notes'] ?? null, $request);

        return redirect()->route('stores.grn.show', $grn->id)
            ->with('success', 'Goods inspection completed.');
    }

    public function post(GoodsReceiptNote $grn, Request $request, GoodsReceiptNoteService $service): RedirectResponse
    {
        abort_unless($request->user()->can('stores-grn.post'), 403);

        $service->post($grn, $request);

        return redirect()->route('stores.grn.show', $grn->id)
            ->with('success', 'Goods successfully posted to store stock ledger.');
    }
}
