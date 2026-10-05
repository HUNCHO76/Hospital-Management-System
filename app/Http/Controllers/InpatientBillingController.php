<?php

namespace App\Http\Controllers;

use App\Http\Resources\ChargeResource;
use App\Models\Admission;
use App\Services\InpatientBillingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inpatient billing view (Phase 9, Step 83) — the admission-scoped financial
 * summary that shows every charge generated during this admission stay along
 * with the NHIF/cash breakdown and payment status. All financial write actions
 * (invoice creation, payment recording, adjustments) use the existing billing
 * controllers; this controller is read-only.
 */
class InpatientBillingController extends Controller
{
    /**
     * Show the inpatient financial summary for one admission.
     *
     * Two gates are required:
     *  - `admission.view` (via the Admission policy) — the user must be able
     *    to see this admission at all.
     *  - `billing.view` — the user must have billing access; clinical staff
     *    without billing permissions cannot reach this page.
     */
    public function show(Request $request, Admission $admission, InpatientBillingService $billingService): Response
    {
        $this->authorize('view', $admission);
        abort_unless($request->user()->can('billing.view'), 403);

        $admission->load([
            'patient:id,patient_number,first_name,middle_name,last_name',
            'hospital:id,name',
            'department:id,name',
            'patientInsurance.insuranceProvider:id,name',
            'service:id,code,name',
        ]);

        $charges = $billingService->chargesForAdmission($admission);
        $summary = $billingService->summary($admission);

        return Inertia::render('inpatient/billing', [
            'admission' => $admission,
            'summary' => $summary,
            'charges' => ChargeResource::collection($charges)->resolve($request),
            'canViewBilling' => true,
        ]);
    }
}
