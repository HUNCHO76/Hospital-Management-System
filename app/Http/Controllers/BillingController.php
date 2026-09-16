<?php

namespace App\Http\Controllers;

use App\Http\Resources\ChargeResource;
use App\Http\Resources\PatientAccountResource;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\PatientAccount;
use App\Services\BillingReportService;
use App\Services\BillingSearchService;
use App\Services\PatientAccountService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The billing foundation module (Phase 7, Step 52) — a dashboard with real
 * counts, the searchable patient-account list, and a single account's
 * detail (balance + financial timeline, plus a "Create Invoice" entry point
 * into Step 55's own InvoiceController). Kept thin: every query lives in
 * BillingSearchService/PatientAccountService, mirroring
 * PharmacyController/StockController's own shape exactly.
 */
class BillingController extends Controller
{
    public function index(Request $request, BillingSearchService $search, PatientAccountService $accounts, BillingReportService $reports): Response
    {
        abort_unless($request->user()->can('billing.view'), 403);

        $hospitalId = $this->scopedHospitalId($request);

        $accountsQuery = $search->accountsQuery([]);
        $search->scopeToHospital($accountsQuery, $hospitalId);

        $recentAccounts = (clone $accountsQuery)->limit(5)->get();

        $chargesQuery = Charge::query();

        if ($hospitalId !== null) {
            $chargesQuery->where('hospital_id', $hospitalId);
        }

        $kpis = $reports->dashboardMetrics($hospitalId);

        return Inertia::render('billing/index', [
            'totalAccounts' => (clone $accountsQuery)->count(),
            'pendingChargeCount' => (clone $chargesQuery)->where('status', Charge::STATUS_PENDING)->count(),
            'postedChargeTotal' => (float) (clone $chargesQuery)->where('status', Charge::STATUS_POSTED)->sum('net_amount'),
            'currency' => config('billing.currency'),
            'recentAccounts' => PatientAccountResource::collection($recentAccounts)->resolve($request),
            'kpis' => $kpis,
            'canViewReports' => $request->user()->can('billing-reports.view'),
            'canViewCashier' => $request->user()->can('billing-cashier.view'),
        ]);
    }

    public function accounts(Request $request, BillingSearchService $search): Response
    {
        $this->authorize('viewAny', PatientAccount::class);

        $filters = $request->only(['search', 'status']);

        $query = $search->accountsQuery($filters);
        $search->scopeToHospital($query, $this->scopedHospitalId($request));

        $accounts = $query->paginate(20)->withQueryString()
            ->through(fn (PatientAccount $account) => (new PatientAccountResource($account))->resolve($request));

        return Inertia::render('billing/accounts/index', [
            'accounts' => $accounts,
            'filters' => $filters,
        ]);
    }

    public function showAccount(Request $request, PatientAccount $patientAccount, PatientAccountService $accounts): Response
    {
        $this->authorize('view', $patientAccount);

        $patientAccount->load(['patient', 'hospital']);

        $charges = $patientAccount->charges()
            ->with(['encounter:id,encounter_number', 'service:id,code,name', 'createdBy:id,UserName', 'cancelledBy:id,UserName'])
            ->latest('charged_at')
            ->limit(100)
            ->get();

        return Inertia::render('billing/accounts/show', [
            'account' => (new PatientAccountResource($patientAccount))->resolve($request),
            'balance' => $accounts->balance($patientAccount),
            'pendingTotal' => $accounts->pendingTotal($patientAccount),
            'charges' => ChargeResource::collection($charges)->resolve($request),
            'canCreateCharge' => $request->user()->can('createInto', [Charge::class, $patientAccount->hospital_id]),
            'canManageCharges' => $request->user()->can('manage', Charge::make(['hospital_id' => $patientAccount->hospital_id])),
            'canCreateInvoice' => $request->user()->can('createInto', [Invoice::class, $patientAccount->hospital_id]),
        ]);
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
