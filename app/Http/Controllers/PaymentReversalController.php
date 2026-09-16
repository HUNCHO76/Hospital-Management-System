<?php

namespace App\Http\Controllers;

use App\Http\Requests\Billing\StorePaymentReversalRequest;
use App\Models\Payment;
use App\Models\PaymentReversal;
use App\Services\PaymentReversalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payment reversal lifecycle (Phase 7, Step 58) — kept thin: every write
 * lives in PaymentReversalService, mirroring PaymentController's own shape.
 */
class PaymentReversalController extends Controller
{
    /**
     * Create a reversal for the given payment — the single write action this
     * controller exposes. The reversal itself is not an addressable resource
     * (no show/index routes needed beyond what the payment.show page already
     * provides inline).
     */
    public function store(StorePaymentReversalRequest $request, Payment $payment, PaymentReversalService $reversals): RedirectResponse
    {
        $this->authorize('createAgainst', [PaymentReversal::class, $payment]);

        $reversals->create($payment, $request->validated(), $request);

        return to_route('billing.payments.show', $payment)->with('success', 'Payment reversed.');
    }

    /**
     * Detail view of a single reversal — linked from the payment.show
     * correction history (spec §7).
     */
    public function show(Request $request, PaymentReversal $reversal): Response
    {
        $this->authorize('view', $reversal);

        $reversal->load(['originalPayment', 'reversedBy', 'invoice', 'patient', 'hospital']);

        return Inertia::render('billing/reversals/show', [
            'reversal' => [
                'id' => $reversal->id,
                'reversal_number' => $reversal->reversal_number,
                'amount' => $reversal->amount,
                'currency' => $reversal->currency,
                'reason' => $reversal->reason,
                'notes' => $reversal->notes,
                'reversed_at' => optional($reversal->reversed_at)->toIso8601String(),
                'original_payment' => $reversal->originalPayment ? [
                    'id' => $reversal->originalPayment->id,
                    'payment_number' => $reversal->originalPayment->payment_number,
                    'amount' => $reversal->originalPayment->amount,
                    'payment_method' => $reversal->originalPayment->payment_method,
                    'payment_date' => optional($reversal->originalPayment->payment_date)->toDateString(),
                ] : null,
                'invoice' => $reversal->invoice ? [
                    'id' => $reversal->invoice->id,
                    'invoice_number' => $reversal->invoice->invoice_number,
                ] : null,
                'patient' => $reversal->patient ? [
                    'id' => $reversal->patient->id,
                    'patient_number' => $reversal->patient->patient_number,
                    'first_name' => $reversal->patient->first_name,
                    'middle_name' => $reversal->patient->middle_name,
                    'last_name' => $reversal->patient->last_name,
                ] : null,
                'hospital' => $reversal->hospital ? ['id' => $reversal->hospital->id, 'name' => $reversal->hospital->name] : null,
                'reversed_by' => $reversal->reversedBy ? ['id' => $reversal->reversedBy->id, 'name' => $reversal->reversedBy->UserName] : null,
                'created_at' => optional($reversal->created_at)->toIso8601String(),
            ],
        ]);
    }
}
