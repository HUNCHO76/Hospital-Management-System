<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Procurement\VendorInvoiceRequest;
use App\Models\GoodsReceiptNote;
use App\Models\Hospital;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Services\Procurement\VendorInvoiceMatchingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VendorInvoiceController extends Controller
{
    protected function getHospitalId(Request $request): int
    {
        return $request->integer('hospital_id')
            ?: ($request->user()->staff?->hospital_id ?? Hospital::query()->value('id') ?? 1);
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('procurement-invoices.view'), 403);

        $hospitalId = $this->getHospitalId($request);
        $matchStatus = $request->string('match_status')->toString();
        $status = $request->string('status')->toString();
        $vendorId = $request->integer('vendor_id');
        $search = $request->string('search')->toString();

        $query = VendorInvoice::with(['vendor', 'purchaseOrder'])
            ->where('hospital_id', $hospitalId);

        if ($matchStatus) {
            $query->where('match_status', $matchStatus);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($vendorId) {
            $query->where('vendor_id', $vendorId);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('system_invoice_number', 'like', "%{$search}%")
                    ->orWhere('invoice_number', 'like', "%{$search}%");
            });
        }

        $invoices = $query->latest()->paginate(15)->withQueryString();
        $vendors = Vendor::where('hospital_id', $hospitalId)->get(['id', 'name']);

        return Inertia::render('procurement/invoices/index', [
            'invoices' => $invoices,
            'vendors' => $vendors,
            'filters' => [
                'match_status' => $matchStatus,
                'status' => $status,
                'vendor_id' => $vendorId,
                'search' => $search,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->can('procurement-invoices.create'), 403);

        $hospitalId = $this->getHospitalId($request);
        $purchaseOrderId = $request->integer('purchase_order_id');

        $purchaseOrders = PurchaseOrder::with(['vendor', 'items.procurementItem'])
            ->where('hospital_id', $hospitalId)
            ->whereIn('status', [
                PurchaseOrder::STATUS_APPROVED,
                PurchaseOrder::STATUS_SENT_TO_VENDOR,
                PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
                PurchaseOrder::STATUS_FULLY_RECEIVED,
            ])
            ->latest()
            ->get();

        $grns = GoodsReceiptNote::with(['items'])
            ->where('hospital_id', $hospitalId)
            ->whereIn('status', [GoodsReceiptNote::STATUS_ACCEPTED, GoodsReceiptNote::STATUS_PARTIALLY_ACCEPTED, GoodsReceiptNote::STATUS_POSTED])
            ->latest()
            ->get();

        $selectedPo = $purchaseOrderId ? $purchaseOrders->firstWhere('id', $purchaseOrderId) : null;

        return Inertia::render('procurement/invoices/create', [
            'purchase_orders' => $purchaseOrders,
            'grns' => $grns,
            'selected_po' => $selectedPo,
        ]);
    }

    public function store(VendorInvoiceRequest $request, VendorInvoiceMatchingService $service): RedirectResponse
    {
        abort_unless($request->user()->can('procurement-invoices.create'), 403);

        $data = $request->validated();
        $invoice = $service->create($data, $request);

        return redirect()->route('procurement.invoices.show', $invoice->id)
            ->with('success', 'Vendor invoice recorded and 3-way match verified.');
    }

    public function show(VendorInvoice $invoice, Request $request): Response
    {
        abort_unless($request->user()->can('procurement-invoices.view'), 403);

        $invoice->load([
            'vendor',
            'purchaseOrder.items',
            'goodsReceiptNote.items',
            'items.procurementItem',
            'verifiedBy',
            'approvedBy',
            'approvalHistories.user',
        ]);

        return Inertia::render('procurement/invoices/show', [
            'invoice' => $invoice,
        ]);
    }

    public function approve(VendorInvoice $invoice, Request $request, VendorInvoiceMatchingService $service): RedirectResponse
    {
        abort_unless($request->user()->can('procurement-invoices.approve'), 403);

        $validated = $request->validate([
            'resolution_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $service->approveForPayment($invoice, $validated['resolution_notes'] ?? null, $request);

        return redirect()->route('procurement.invoices.show', $invoice->id)
            ->with('success', 'Vendor invoice approved for accounts payable.');
    }
}
