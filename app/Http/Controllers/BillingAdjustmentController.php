<?php

namespace App\Http\Controllers;

use App\Http\Requests\Billing\StoreBillingAdjustmentRequest;
use App\Http\Resources\BillingAdjustmentResource;
use App\Models\BillingAdjustment;
use App\Models\Invoice;
use App\Services\BillingAdjustmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Billing adjustment lifecycle (Phase 7, Step 58) — thin controller, mirrors
 * InvoiceController's shape. Adjustments are created from the invoice.show
 * page inline dialog, not a separate create page.
 */
class BillingAdjustmentController extends Controller
{
    public function index(Request $request, BillingAdjustmentService $service): Response
    {
        $this->authorize('viewAny', BillingAdjustment::class);

        $filters = $request->only(['search', 'type', 'direction']);

        $query = $service->adjustmentsQuery($filters);
        $service->scopeToHospital($query, $this->scopedHospitalId($request));

        $adjustments = $query->paginate(20)->withQueryString()
            ->through(fn (BillingAdjustment $adj) => (new BillingAdjustmentResource($adj))->resolve($request));

        return Inertia::render('billing/adjustments/index', [
            'adjustments' => $adjustments,
            'filters' => $filters,
            'types' => BillingAdjustment::TYPES,
            'directions' => BillingAdjustment::DIRECTIONS,
        ]);
    }

    public function store(StoreBillingAdjustmentRequest $request, Invoice $invoice, BillingAdjustmentService $service): RedirectResponse
    {
        $this->authorize('createAgainst', [BillingAdjustment::class, $invoice]);

        $service->create($invoice, $request->validated(), $request);

        return to_route('billing.invoices.show', $invoice)->with('success', 'Adjustment posted.');
    }

    public function show(Request $request, BillingAdjustment $adjustment): Response
    {
        $this->authorize('view', $adjustment);

        $adjustment->load(['invoice', 'hospital', 'patient', 'adjustedBy']);

        return Inertia::render('billing/adjustments/show', [
            'adjustment' => (new BillingAdjustmentResource($adjustment))->resolve($request),
        ]);
    }

    protected function scopedHospitalId(Request $request): ?int
    {
        if (! $request->user()->hasRole('hospital-admin')) {
            return null;
        }

        return $request->user()->staff?->hospital_id;
    }
}
