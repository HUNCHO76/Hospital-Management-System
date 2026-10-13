<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceiptNote;
use App\Models\Hospital;
use App\Models\Indent;
use App\Models\PurchaseOrder;
use App\Models\VendorInvoice;
use App\Models\VendorQuotation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProcurementDashboardController extends Controller
{
    protected function getHospitalId(Request $request): int
    {
        return $request->integer('hospital_id')
            ?: ($request->user()->staff?->hospital_id ?? Hospital::query()->value('id') ?? 1);
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('procurement.view'), 403);

        $hospitalId = $this->getHospitalId($request);

        $pendingIndents = Indent::where('hospital_id', $hospitalId)
            ->where('status', Indent::STATUS_SUBMITTED)
            ->count();

        $activePurchaseOrders = PurchaseOrder::where('hospital_id', $hospitalId)
            ->whereIn('status', [
                PurchaseOrder::STATUS_APPROVED,
                PurchaseOrder::STATUS_SENT_TO_VENDOR,
                PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            ])
            ->count();

        $pendingGrnInspections = GoodsReceiptNote::where('hospital_id', $hospitalId)
            ->where('status', GoodsReceiptNote::STATUS_INSPECTION_PENDING)
            ->count();

        $invoiceDiscrepancies = VendorInvoice::where('hospital_id', $hospitalId)
            ->where('match_status', VendorInvoice::MATCH_STATUS_DISCREPANCY)
            ->count();

        $monthlySpend = (float) PurchaseOrder::where('hospital_id', $hospitalId)
            ->whereIn('status', [
                PurchaseOrder::STATUS_APPROVED,
                PurchaseOrder::STATUS_SENT_TO_VENDOR,
                PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
                PurchaseOrder::STATUS_FULLY_RECEIVED,
            ])
            ->whereYear('order_date', now()->year)
            ->whereMonth('order_date', now()->month)
            ->sum('grand_total');

        $recentIndents = Indent::with(['department', 'requestedBy'])
            ->where('hospital_id', $hospitalId)
            ->latest()
            ->limit(5)
            ->get();

        $recentOrders = PurchaseOrder::with(['vendor', 'receivingStore'])
            ->where('hospital_id', $hospitalId)
            ->latest()
            ->limit(5)
            ->get();

        $recentQuotations = VendorQuotation::with(['vendor', 'indent'])
            ->where('hospital_id', $hospitalId)
            ->latest()
            ->limit(5)
            ->get();

        return Inertia::render('procurement/dashboard', [
            'metrics' => [
                'pending_indents' => $pendingIndents,
                'active_pos' => $activePurchaseOrders,
                'pending_grns' => $pendingGrnInspections,
                'invoice_discrepancies' => $invoiceDiscrepancies,
                'monthly_spend' => $monthlySpend,
            ],
            'recent_indents' => $recentIndents,
            'recent_orders' => $recentOrders,
            'recent_quotations' => $recentQuotations,
        ]);
    }
}
