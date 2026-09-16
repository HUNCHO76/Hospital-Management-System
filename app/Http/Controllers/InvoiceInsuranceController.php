<?php

namespace App\Http\Controllers;

use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\InsuranceBillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * An invoice's insurance/coverage breakdown (Phase 7, Step 56, spec §31) —
 * kept as its own thin controller, separate from InvoiceController, mirroring
 * how ServicePricingController sits alongside ServiceCatalogController for a
 * distinct-but-related concern. Every calculation lives in
 * InsuranceBillingService; this controller only authorizes and shapes the
 * Inertia response.
 */
class InvoiceInsuranceController extends Controller
{
    public function show(Request $request, Invoice $invoice, InsuranceBillingService $insurance): Response
    {
        $this->authorize('viewInsurance', $invoice);

        $invoice->load(['patient', 'hospital', 'charges' => function ($query) {
            $query->with(['service:id,code,name,category', 'insuranceAllocation.insuranceCoverageRule', 'insuranceAllocation.calculatedBy']);
        }]);

        $eligibility = $insurance->eligibility($invoice->patient);

        return Inertia::render('billing/invoices/insurance', [
            'invoice' => (new InvoiceResource($invoice))->resolve($request),
            'eligibility' => [
                'eligible' => $eligibility['eligible'],
                'reason' => $eligibility['reason'],
                'coverage' => $eligibility['coverage'] ? [
                    'id' => $eligibility['coverage']->id,
                    'insurance_provider_id' => $eligibility['coverage']->insurance_provider_id,
                    'insurance_scheme_id' => $eligibility['coverage']->insurance_scheme_id,
                    'membership_number' => $eligibility['coverage']->membership_number,
                    'status' => $eligibility['coverage']->status,
                    'verification_status' => $eligibility['coverage']->verification_status,
                    'effective_from' => optional($eligibility['coverage']->effective_from)->toDateString(),
                    'effective_to' => optional($eligibility['coverage']->effective_to)->toDateString(),
                ] : null,
            ],
            'canCalculate' => $request->user()->can('calculateInsurance', $invoice),
        ]);
    }

    public function calculate(Request $request, Invoice $invoice, InsuranceBillingService $insurance): RedirectResponse
    {
        $this->authorize('calculateInsurance', $invoice);

        $insurance->applyToInvoice($invoice, $request);

        return back()->with('success', 'Insurance coverage calculated.');
    }
}
