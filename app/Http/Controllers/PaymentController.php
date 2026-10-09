<?php

namespace App\Http\Controllers;

use App\Http\Requests\Billing\StorePaymentRequest;
use App\Http\Requests\Billing\VoidPaymentRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\PaymentReversal;
use App\Services\PaymentSearchService;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payment recording and lifecycle management (Phase 7, Step 57) — kept
 * thin: every query lives in PaymentSearchService, every calculation and
 * write in PaymentService, mirroring InvoiceController/ChargeController's
 * own shape exactly.
 */
class PaymentController extends Controller
{
    public function index(Request $request, PaymentSearchService $search): Response
    {
        $this->authorize('viewAny', Payment::class);

        $filters = $request->only(['search', 'status', 'payment_method']);

        $query = $search->paymentsQuery($filters);
        $search->scopeToHospital($query, $this->scopedHospitalId($request));

        $payments = $query->paginate(20)->withQueryString()
            ->through(fn (Payment $payment) => (new PaymentResource($payment))->resolve($request));

        return Inertia::render('billing/payments/index', [
            'payments' => $payments,
            'filters' => $filters,
            'methods' => Payment::METHODS,
            'statuses' => Payment::STATUSES,
        ]);
    }

    /**
     * Optionally prefilled from an invoice/account's own "Record Payment"
     * link (?patient_id=X) — a plain query param, re-validated by
     * StorePaymentRequest/PaymentService on submit regardless, so a
     * tampered value here can only prefill the form, never bypass
     * authorization (mirrors ChargeController::create()'s identical
     * pattern). Every eligible invoice already carries its own
     * server-computed outstanding breakdown (spec §57.26) — the frontend
     * never computes one itself.
     */
    public function create(Request $request, PaymentSearchService $search): Response
    {
        abort_unless($request->user()->can('billing-payments.create'), 403);

        $initialPatient = null;
        $eligibleInvoices = collect();

        if ($request->filled('patient_id')) {
            $patient = Patient::query()->find($request->integer('patient_id'), ['id', 'patient_number', 'first_name', 'middle_name', 'last_name']);

            if ($patient) {
                $initialPatient = $patient->only(['id', 'patient_number', 'first_name', 'middle_name', 'last_name']);

                $eligibleInvoices = $search->eligibleInvoicesForPatient($patient, $this->scopedHospitalId($request))
                    ->map(fn (array $row) => [
                        'invoice' => (new InvoiceResource($row['invoice']))->resolve($request),
                        'outstanding' => $row['outstanding'],
                    ])
                    ->values();
            }
        }

        return Inertia::render('billing/payments/create', [
            'initialPatient' => $initialPatient,
            'eligibleInvoices' => $eligibleInvoices,
            'methods' => Payment::METHODS,
            'methodsRequiringReference' => Payment::METHODS_REQUIRING_REFERENCE,
        ]);
    }

    public function store(StorePaymentRequest $request, PaymentService $payments): RedirectResponse
    {
        $data = $request->validated();

        $invoice = Invoice::query()->findOrFail($data['invoice_id']);

        $this->authorize('createInto', [Payment::class, $invoice->hospital_id]);

        $payment = $payments->createPayment($data, $request);

        return to_route('billing.payments.show', $payment)->with('success', 'Payment recorded.');
    }

    public function show(Request $request, Payment $payment): Response
    {
        $this->authorize('view', $payment);

        $payment->load([
            'hospital', 'patient', 'patientAccount', 'invoice', 'allocations', 'receivedBy', 'failedBy',
            'reversal.reversedBy',
            'refunds.processedBy',
        ]);

        return Inertia::render('billing/payments/show', [
            'payment' => (new PaymentResource($payment))->resolve($request),
            'canVoid' => $request->user()->can('void', $payment),
            'canReverse' => $request->user()->can('createAgainst', [PaymentReversal::class, $payment]),
            'canRefund' => $request->user()->can('createAgainst', [PaymentRefund::class, $payment]),
        ]);
    }

    /**
     * A printable receipt of an already-posted payment (spec §57.17/§57.28)
     * — a distinct render of the same authorized Payment resource, never a
     * second financial record; generating/viewing it never modifies the
     * payment's own amount or any other field.
     */
    public function receipt(Request $request, Payment $payment): Response
    {
        $this->authorize('view', $payment);

        $payment->load(['hospital', 'patient', 'invoice', 'receivedBy']);

        return Inertia::render('billing/payments/receipt', [
            'payment' => (new PaymentResource($payment))->resolve($request),
        ]);
    }

    public function void(VoidPaymentRequest $request, Payment $payment, PaymentService $payments): RedirectResponse
    {
        $this->authorize('void', $payment);

        $payments->markFailed($payment, $request->validated()['reason'], $request);

        return back()->with('success', 'Payment marked as failed.');
    }

    /**
     * The hospital a hospital-admin is restricted to, or null when the user
     * is unrestricted (super-admin and any other unscoped role).
     */
    protected function scopedHospitalId(Request $request): ?int
    {
        if (! $request->user()->hasAnyRole(['hospital-admin', 'Manager', 'manager'])) {
            return null;
        }

        return $request->user()->staff?->hospital_id;
    }
}
