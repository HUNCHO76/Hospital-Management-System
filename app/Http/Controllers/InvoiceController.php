<?php

namespace App\Http\Controllers;

use App\Http\Requests\Billing\AddInvoiceChargeRequest;
use App\Http\Requests\Billing\CancelInvoiceRequest;
use App\Http\Requests\Billing\CreateInvoiceRequest;
use App\Http\Requests\Billing\VoidInvoiceRequest;
use App\Http\Resources\BillingAdjustmentResource;
use App\Http\Resources\ChargeResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Models\BillingAdjustment;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\PatientAccount;
use App\Models\Payment;
use App\Services\InvoiceSearchService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Invoice creation and lifecycle management (Phase 7, Step 55) — kept thin:
 * every query lives in InvoiceSearchService, every write in InvoiceService,
 * mirroring ChargeController/ServicePricingController's own shape exactly.
 */
class InvoiceController extends Controller
{
    public function index(Request $request, InvoiceSearchService $search): Response
    {
        $this->authorize('viewAny', Invoice::class);

        $filters = $request->only(['search', 'status']);

        $query = $search->invoicesQuery($filters);
        $search->scopeToHospital($query, $this->scopedHospitalId($request));

        $invoices = $query->paginate(20)->withQueryString()
            ->through(fn (Invoice $invoice) => (new InvoiceResource($invoice))->resolve($request));

        return Inertia::render('billing/invoices/index', [
            'invoices' => $invoices,
            'filters' => $filters,
        ]);
    }

    public function create(Request $request, InvoiceSearchService $search): Response
    {
        abort_unless($request->user()->can('billing-invoices.create'), 403);

        $account = PatientAccount::query()->with(['patient', 'hospital'])->findOrFail($request->integer('patient_account_id'));

        $this->authorize('createInto', [Invoice::class, $account->hospital_id]);

        $eligibleCharges = $search->eligibleChargesQuery($account)->get();

        return Inertia::render('billing/invoices/create', [
            'account' => [
                'id' => $account->id,
                'account_number' => $account->account_number,
                'currency' => $account->currency,
                'patient' => $account->patient ? [
                    'id' => $account->patient->id,
                    'patient_number' => $account->patient->patient_number,
                    'first_name' => $account->patient->first_name,
                    'middle_name' => $account->patient->middle_name,
                    'last_name' => $account->patient->last_name,
                ] : null,
                'hospital' => $account->hospital ? ['id' => $account->hospital->id, 'name' => $account->hospital->name] : null,
            ],
            'eligibleCharges' => ChargeResource::collection($eligibleCharges)->resolve($request),
        ]);
    }

    public function store(CreateInvoiceRequest $request, InvoiceService $invoices): RedirectResponse
    {
        $data = $request->validated();

        $account = PatientAccount::query()->findOrFail($data['patient_account_id']);

        $this->authorize('createInto', [Invoice::class, $account->hospital_id]);

        $invoice = $invoices->createDraft($account, $data['charge_ids'] ?? [], $data['notes'] ?? null, $request);

        return to_route('billing.invoices.show', $invoice)->with('success', 'Draft invoice created.');
    }

    public function show(Request $request, Invoice $invoice, InvoiceSearchService $search, PaymentService $payments): Response
    {
        $this->authorize('view', $invoice);

        $invoice->load(['patient', 'hospital', 'patientAccount', 'createdBy', 'cancelledBy', 'charges' => function ($query) {
            $query->with(['service:id,code,name', 'encounter:id,encounter_number']);
        }, 'payments' => function ($query) {
            $query->with('receivedBy:id,UserName')->latest('payment_date');
        }, 'adjustments' => function ($query) {
            $query->with('adjustedBy:id,UserName')->latest();
        }]);

        $eligibleCharges = $invoice->isDraft()
            ? $search->eligibleChargesQuery($invoice->patientAccount)->get()
            : collect();

        return Inertia::render('billing/invoices/show', [
            'invoice' => (new InvoiceResource($invoice))->resolve($request),
            'currentTotal' => (float) $invoice->charges->sum('net_amount'),
            'eligibleCharges' => ChargeResource::collection($eligibleCharges)->resolve($request),
            'outstanding' => $invoice->status === Invoice::STATUS_POSTED ? $payments->calculateOutstanding($invoice) : null,
            'payments' => PaymentResource::collection($invoice->payments)->resolve($request),
            'adjustments' => BillingAdjustmentResource::collection($invoice->adjustments)->resolve($request),
            'canRecordPayment' => $request->user()->can('createInto', [Payment::class, $invoice->hospital_id]),
            'canAdjust' => $request->user()->can('createAgainst', [BillingAdjustment::class, $invoice]),
            'canManage' => $request->user()->can('manage', $invoice),
            'canPost' => $request->user()->can('post', $invoice),
            'canVoid' => $request->user()->can($invoice->isDraft() ? 'void' : 'cancel', $invoice),
            'canViewInsurance' => $request->user()->can('viewInsurance', $invoice),
        ]);
    }

    public function addCharge(AddInvoiceChargeRequest $request, Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        $this->authorize('manage', $invoice);

        $charge = Charge::query()->findOrFail($request->validated()['charge_id']);

        $invoices->addCharge($invoice, $charge, $request);

        return back()->with('success', 'Charge added to invoice.');
    }

    public function removeCharge(Request $request, Invoice $invoice, Charge $charge, InvoiceService $invoices): RedirectResponse
    {
        $this->authorize('manage', $invoice);

        $invoices->removeChargeBeforePosting($invoice, $charge, $request);

        return back()->with('success', 'Charge removed from invoice.');
    }

    public function post(Request $request, Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        $this->authorize('post', $invoice);

        $invoices->post($invoice, $request);

        return back()->with('success', 'Invoice posted.');
    }

    public function void(VoidInvoiceRequest $request, Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        $this->authorize('void', $invoice);

        $invoices->void($invoice, $request->validated()['reason'], $request);

        return back()->with('success', 'Invoice voided.');
    }

    public function cancel(CancelInvoiceRequest $request, Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        $this->authorize('cancel', $invoice);

        $invoices->cancel($invoice, $request->validated()['reason'], $request);

        return back()->with('success', 'Invoice cancelled.');
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
