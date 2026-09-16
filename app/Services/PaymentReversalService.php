<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentReversal;
use App\Services\Concerns\ValidatesMoney;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the payment reversal lifecycle (Phase 7, Step 58, spec §3) — the
 * controlled negation of an entire posted payment. The original payment is
 * never modified; only a new PaymentReversal row is created, which
 * PaymentService::calculateOutstanding() subtracts from the net balance.
 *
 * A reversal is always full (the entire payment amount) — partial returns
 * are refunds, not reversals. No PENDING state: a reversal is an immediate,
 * immutable financial fact once created.
 */
class PaymentReversalService
{
    use ValidatesMoney;

    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Create a reversal for the given posted payment (spec §3) — locks the
     * payment first, verifies no reversal and no refunds exist yet, then
     * creates the reversal atomically. Requires a non-blank reason.
     *
     * @param  array{reason: string, notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function create(Payment $payment, array $data, Request $request): PaymentReversal
    {
        $reason = trim($data['reason'] ?? '');

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        $reversal = DB::transaction(function () use ($payment, $reason, $data, $request) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPosted()) {
                throw ValidationException::withMessages([
                    'payment_id' => "Cannot reverse a payment with status \"{$locked->status}\".",
                ]);
            }

            if ($locked->reversal()->exists()) {
                throw ValidationException::withMessages([
                    'payment_id' => 'This payment has already been reversed.',
                ]);
            }

            if ($locked->refunds()->exists()) {
                throw ValidationException::withMessages([
                    'payment_id' => 'This payment already has refunds recorded against it. Issue another refund instead.',
                ]);
            }

            return PaymentReversal::create([
                'original_payment_id' => $locked->id,
                'invoice_id' => $locked->invoice_id,
                'hospital_id' => $locked->hospital_id,
                'patient_id' => $locked->patient_id,
                'patient_account_id' => $locked->patient_account_id,
                'amount' => $locked->amount,
                'currency' => $locked->currency,
                'reason' => $reason,
                'reversed_by' => $request->user()->id,
                'reversed_at' => now(),
                'notes' => filled($data['notes'] ?? null) ? $data['notes'] : null,
            ]);
        });

        $this->auditLog->record(
            'payment_reversed',
            $reversal,
            null,
            [
                'reversal_number' => $reversal->reversal_number,
                'original_payment_id' => $payment->id,
                'original_payment_number' => $payment->payment_number,
                'amount' => $reversal->amount,
                'reason' => $reversal->reason,
            ],
            $request,
        );

        return $reversal->fresh(['originalPayment', 'reversedBy']);
    }
}
