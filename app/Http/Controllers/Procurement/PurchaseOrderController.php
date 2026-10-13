<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\PurchaseOrderRequest;
use App\Models\Hospital;
use App\Models\Indent;
use App\Models\ProcurementItem;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Models\Vendor;
use App\Models\VendorQuotation;
use App\Services\Procurement\PurchaseOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    protected function getHospitalId(Request $request): int
    {
        return $request->integer('hospital_id')
            ?: ($request->user()->staff?->hospital_id ?? Hospital::query()->value('id') ?? 1);
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('procurement-purchase-orders.view'), 403);

        $hospitalId = $this->getHospitalId($request);
        $status = $request->string('status')->toString();
        $vendorId = $request->integer('vendor_id');
        $search = $request->string('search')->toString();

        $query = PurchaseOrder::with(['vendor', 'receivingStore', 'createdBy'])
            ->where('hospital_id', $hospitalId);

        if ($status) {
            $query->where('status', $status);
        }

        if ($vendorId) {
            $query->where('vendor_id', $vendorId);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $orders = $query->latest()->paginate(15)->withQueryString();
        $vendors = Vendor::where('hospital_id', $hospitalId)->where('status', 'active')->get(['id', 'name']);

        return Inertia::render('procurement/orders/index', [
            'orders' => $orders,
            'vendors' => $vendors,
            'filters' => [
                'status' => $status,
                'vendor_id' => $vendorId,
                'search' => $search,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can('procurement-purchase-orders.create'), 403);

        $hospitalId = $this->getHospitalId($request);
        $vendors = Vendor::where('hospital_id', $hospitalId)->where('status', 'active')->get(['id', 'name', 'payment_terms']);
        $stores = Store::where('hospital_id', $hospitalId)->where('status', 'active')->get(['id', 'name', 'type']);
        $items = ProcurementItem::where('status', 'active')->get();

        $quotationId = $request->integer('quotation_id');
        $quotation = $quotationId ? VendorQuotation::with(['items.procurementItem', 'vendor'])->find($quotationId) : null;

        $indentId = $request->integer('indent_id');
        $indent = $indentId ? Indent::with(['items.procurementItem'])->find($indentId) : null;

        return Inertia::render('procurement/orders/create', [
            'vendors' => $vendors,
            'stores' => $stores,
            'items' => $items,
            'quotation' => $quotation,
            'indent' => $indent,
        ]);
    }

    public function store(PurchaseOrderRequest $request, PurchaseOrderService $service): RedirectResponse
    {
        abort_unless($request->user()->can('procurement-purchase-orders.create'), 403);

        $data = $request->validated();
        $data['hospital_id'] = $this->getHospitalId($request);

        $order = $service->create($data, $request);

        return redirect()->route('procurement.orders.show', $order->id)
            ->with('success', 'Purchase order created successfully.');
    }

    public function show(PurchaseOrder $order, Request $request): Response
    {
        abort_unless($request->user()->can('procurement-purchase-orders.view'), 403);

        $order->load([
            'vendor',
            'receivingStore',
            'items.procurementItem',
            'goodsReceiptNotes',
            'invoices',
            'createdBy',
            'approvedBy',
            'approvalHistories.user',
        ]);

        return Inertia::render('procurement/orders/show', [
            'order' => $order,
        ]);
    }

    public function edit(PurchaseOrder $order, Request $request): Response|RedirectResponse
    {
        abort_unless($request->user()->can('procurement-purchase-orders.update'), 403);

        if ($order->status !== PurchaseOrder::STATUS_DRAFT) {
            return redirect()->route('procurement.orders.show', $order->id)
                ->with('error', 'Only draft purchase orders can be edited.');
        }

        $hospitalId = $this->getHospitalId($request);
        $vendors = Vendor::where('hospital_id', $hospitalId)->where('status', 'active')->get(['id', 'name']);
        $stores = Store::where('hospital_id', $hospitalId)->where('status', 'active')->get(['id', 'name', 'type']);
        $items = ProcurementItem::where('status', 'active')->get();

        $order->load('items.procurementItem');

        return Inertia::render('procurement/orders/edit', [
            'order' => $order,
            'vendors' => $vendors,
            'stores' => $stores,
            'items' => $items,
        ]);
    }

    public function update(PurchaseOrder $order, PurchaseOrderRequest $request, PurchaseOrderService $service): RedirectResponse
    {
        abort_unless($request->user()->can('procurement-purchase-orders.update'), 403);

        $service->update($order, $request->validated(), $request);

        return redirect()->route('procurement.orders.show', $order->id)
            ->with('success', 'Purchase order updated successfully.');
    }

    public function submit(PurchaseOrder $order, Request $request, PurchaseOrderService $service): RedirectResponse
    {
        abort_unless($request->user()->can('procurement-purchase-orders.submit'), 403);

        $service->submit($order, $request);

        return redirect()->route('procurement.orders.show', $order->id)
            ->with('success', 'Purchase order submitted for approval.');
    }

    public function approve(PurchaseOrder $order, Request $request, PurchaseOrderService $service): RedirectResponse
    {
        abort_unless($request->user()->can('procurement-purchase-orders.approve'), 403);

        $validated = $request->validate([
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        $service->approve($order, $validated['comments'] ?? null, $request);

        return redirect()->route('procurement.orders.show', $order->id)
            ->with('success', 'Purchase order approved.');
    }

    public function send(PurchaseOrder $order, Request $request, PurchaseOrderService $service): RedirectResponse
    {
        abort_unless($request->user()->can('procurement-purchase-orders.send'), 403);

        $service->sendToVendor($order, $request);

        return redirect()->route('procurement.orders.show', $order->id)
            ->with('success', 'Purchase order marked as dispatched to vendor.');
    }

    public function cancel(PurchaseOrder $order, Request $request, PurchaseOrderService $service): RedirectResponse
    {
        abort_unless($request->user()->can('procurement-purchase-orders.cancel'), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $service->cancel($order, $validated['reason'], $request);

        return redirect()->route('procurement.orders.show', $order->id)
            ->with('success', 'Purchase order cancelled.');
    }
}
