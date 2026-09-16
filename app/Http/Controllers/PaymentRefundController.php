<?php

namespace App\Http\Controllers;

use App\Http\Requests\Billing\StorePaymentRefundRequest;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Services\PaymentRefundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payment refund lifecycle (Phase 7, Step 58) — kept thin, mirrors
 * PaymentReversalController's shape.
 */
class PaymentRefundController extends Controller
{
    public function store(StorePaymentRefundRequest $request, Payment $payment, PaymentRefundService $refunds): RedirectResponse
    {
        $this->authorize('createAgainst', [PaymentRefund::class, $payment]);

        $refunds->create($payment, $request->validated(), $request);

        return to_route('billing.payments.show', $payment)->with('success', 'Refund issued.');
    }

    public function show(Request $request, PaymentRefund $refund): Response
    {
        $this->authorize('view', $refund);

        $refund->load(['payment', 'processedBy', 'invoice', 'patient', 'hospital']);

        return Inertia::render('billing/refunds/show', [
            'refund' => [
                'id' => $refund->id,
                'refund_number' => $refund->refund_number,
                'amount' => $refund->amount,
                'currency' => $refund->currency,
                'refund_method' => $refund->refund_method,
                'refund_reference' => $refund->refund_reference,
                'reason' => $refund->reason,
                'notes' => $refund->notes,
                'processed_at' => optional($refund->processed_at)->toIso8601String(),
                'payment' => $refund->payment ? [
                    'id' => $refund->payment->id,
                    'payment_number' => $refund->payment->payment_number,
                    'amount' => $refund->payment->amount,
                    'payment_method' => $refund->payment->payment_method,
                    'payment_date' => optional($refund->payment->payment_date)->toDateString(),
                ] : null,
                'invoice' => $refund->invoice ? [
                    'id' => $refund->invoice->id,
                    'invoice_number' => $refund->invoice->invoice_number,
                ] : null,
                'patient' => $refund->patient ? [
                    'id' => $refund->patient->id,
                    'patient_number' => $refund->patient->patient_number,
                    'first_name' => $refund->patient->first_name,
                    'middle_name' => $refund->patient->middle_name,
                    'last_name' => $refund->patient->last_name,
                ] : null,
                'hospital' => $refund->hospital ? ['id' => $refund->hospital->id, 'name' => $refund->hospital->name] : null,
                'processed_by' => $refund->processedBy ? ['id' => $refund->processedBy->id, 'name' => $refund->processedBy->UserName] : null,
                'created_at' => optional($refund->created_at)->toIso8601String(),
            ],
        ]);
    }
}
