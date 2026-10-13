<?php

namespace App\Http\Controllers\Stores;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stores\StoreTransferRequest;
use App\Models\Hospital;
use App\Models\StockBatch;
use App\Models\Store;
use App\Models\StoreTransfer;
use App\Services\Procurement\StoreTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StoreTransferController extends Controller
{
    protected function getHospitalId(Request $request): int
    {
        return $request->integer('hospital_id')
            ?: ($request->user()->staff?->hospital_id ?? Hospital::query()->value('id') ?? 1);
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('stores-transfers.view'), 403);

        $hospitalId = $this->getHospitalId($request);
        $status = $request->string('status')->toString();
        $fromStoreId = $request->integer('from_store_id');
        $toStoreId = $request->integer('to_store_id');
        $search = $request->string('search')->toString();

        $query = StoreTransfer::with(['fromStore', 'toStore', 'requestedBy', 'approvedBy', 'receivedBy'])
            ->where('hospital_id', $hospitalId);

        if ($status) {
            $query->where('status', $status);
        }

        if ($fromStoreId) {
            $query->where('from_store_id', $fromStoreId);
        }

        if ($toStoreId) {
            $query->where('to_store_id', $toStoreId);
        }

        if ($search) {
            $query->where('transfer_number', 'like', "%{$search}%");
        }

        $transfers = $query->latest()->paginate(15)->withQueryString();
        $stores = Store::where('hospital_id', $hospitalId)->get(['id', 'name']);

        return Inertia::render('stores/transfers/index', [
            'transfers' => $transfers,
            'stores' => $stores,
            'filters' => [
                'status' => $status,
                'from_store_id' => $fromStoreId,
                'to_store_id' => $toStoreId,
                'search' => $search,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can('stores-transfers.create'), 403);

        $hospitalId = $this->getHospitalId($request);
        $stores = Store::where('hospital_id', $hospitalId)->where('status', 'active')->get(['id', 'name']);

        // Load available batches in store
        $fromStoreId = $request->integer('from_store_id');
        $batches = [];
        if ($fromStoreId) {
            $batches = StockBatch::with(['stockItem.procurementItem', 'stockItem.medication'])
                ->where('store_id', $fromStoreId)
                ->where('status', StockBatch::STATUS_ACTIVE)
                ->where('quantity', '>', 0)
                ->orderBy('expiry_date')
                ->get();
        }

        return Inertia::render('stores/transfers/create', [
            'stores' => $stores,
            'selected_from_store_id' => $fromStoreId,
            'available_batches' => $batches,
        ]);
    }

    public function store(StoreTransferRequest $request, StoreTransferService $service): RedirectResponse
    {
        abort_unless($request->user()->can('stores-transfers.create'), 403);

        $data = $request->validated();
        $data['hospital_id'] = $this->getHospitalId($request);

        $transfer = $service->create($data, $request);

        return redirect()->route('stores.transfers.show', $transfer->id)
            ->with('success', 'Store transfer created in draft.');
    }

    public function show(StoreTransfer $transfer, Request $request): Response
    {
        abort_unless($request->user()->can('stores-transfers.view'), 403);

        $transfer->load([
            'fromStore',
            'toStore',
            'items.procurementItem',
            'items.fromStockBatch',
            'items.toStockBatch',
            'requestedBy',
            'approvedBy',
            'receivedBy',
            'approvalHistories.user',
        ]);

        return Inertia::render('stores/transfers/show', [
            'transfer' => $transfer,
        ]);
    }

    public function submit(StoreTransfer $transfer, Request $request, StoreTransferService $service): RedirectResponse
    {
        abort_unless($request->user()->can('stores-transfers.submit'), 403);

        $service->submit($transfer, $request);

        return redirect()->route('stores.transfers.show', $transfer->id)
            ->with('success', 'Store transfer submitted for approval.');
    }

    public function dispatch(StoreTransfer $transfer, Request $request, StoreTransferService $service): RedirectResponse
    {
        abort_unless($request->user()->can('stores-transfers.dispatch'), 403);

        $service->dispatch($transfer, $request);

        return redirect()->route('stores.transfers.show', $transfer->id)
            ->with('success', 'Supplies dispatched and in transit to destination store.');
    }

    public function receive(StoreTransfer $transfer, Request $request, StoreTransferService $service): RedirectResponse
    {
        abort_unless($request->user()->can('stores-transfers.receive'), 403);

        $service->receive($transfer, $request);

        return redirect()->route('stores.transfers.show', $transfer->id)
            ->with('success', 'Store transfer completed and received.');
    }
}
