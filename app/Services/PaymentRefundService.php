<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Services\Concerns\ValidatesMoney;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the payment refund lifecycle (Phase 7, Step 58, spec §4) — recording
 * money returned to the patient. Multiple refunds per payment are allowed;
 * their sum is validated under a row lock on every create() call to prevent
 * over-refunding. The original payment is never modified.
 *
 * `refund_method`/`refund_reference` mirror Payment's own method/reference
 * vocabulary; the duplicate-reference protection (UNIQUE DB constraint +
 * row-locked re-check) mirrors PaymentService::createPayment()'s own pattern.
 */
class PaymentRefundService
{
    use ValidatesMoney;

    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Create a refund against the given posted payment (spec §4) — locks the
     * payment first, recomputes refundable amount under the lock (server-side,
     * never trusted from the client), rejects an amount exceeding it.
     *
     * @param  array{amount: float|string, refund_method: string, refund_reference?: string|null, reason: string, notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function create(Payment $payment, array $data, Request $request): PaymentRefund
    {
        $this->validateMethodAndReference($data);
        $amount = $this->validateMoney($data['amount'] ?? null, 'amount', allowZero: false);

        $reason = trim($data['reason'] ?? '');
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        $refund = DB::transaction(function () use ($payment, $amount, $data, $reason, $request) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPosted()) {
                throw ValidationException::withMessages([
                    'payment_id' => "Cannot refund a payment with status \"{$locked->status}\".",
                ]);
            }

            if ($locked->reversal()->exists()) {
                throw ValidationException::withMessages([
                    'payment_id' => 'This payment has been reversed. Issue a new payment instead.',
                ]);
            }

            $alreadyRefunded = round((float) $locked->refunds()->lockForUpdate()->sum('amount'), 2);
            $refundable = round((float) $locked->amount - $alreadyRefunded, 2);

            if ($refundable <= 0.0) {
                throw ValidationException::withMessages([
                    'amount' => 'This payment has been fully refunded.',
                ]);
            }

            if (round($amount, 2) > $refundable) {
                throw ValidationException::withMessages([
                    'amount' => "Refund amount exceeds the refundable balance ({$refundable}).",
                ]);
            }

            $this->assertReferenceNotAlreadyUsed(
                $locked->hospital_id,
                $data['refund_method'],
                $data['refund_reference'] ?? null
            );

            return PaymentRefund::create([
                'payment_id' => $locked->id,
                'invoice_id' => $locked->invoice_id,
                'hospital_id' => $locked->hospital_id,
                'patient_id' => $locked->patient_id,
                'patient_account_id' => $locked->patient_account_id,
                'amount' => $amount,
                'currency' => $locked->currency,
                'refund_method' => $data['refund_method'],
                'refund_reference' => filled($data['refund_reference'] ?? null) ? $data['refund_reference'] : null,
                'reason' => $reason,
                'processed_by' => $request->user()->id,
                'processed_at' => now(),
                'notes' => filled($data['notes'] ?? null) ? $data['notes'] : null,
            ]);
        });

        $this->auditLog->record(
            'payment_refunded',
            $refund,
            null,
            [
                'refund_number' => $refund->refund_number,
                'payment_id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'amount' => $refund->amount,
                'refund_method' => $refund->refund_method,
                'reason' => $refund->reason,
            ],
            $request,
        );

        return $refund->fresh(['payment', 'processedBy']);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    protected function validateMethodAndReference(array $data): void
    {
        $method = $data['refund_method'] ?? null;

        if (! in_array($method, Payment::METHODS, true)) {
            throw ValidationException::withMessages(['refund_method' => 'Invalid refund method.']);
        }

        if (in_array($method, Payment::METHODS_REQUIRING_REFERENCE, true)
            && trim((string) ($data['refund_reference'] ?? '')) === '') {
            throw ValidationException::withMessages(['refund_reference' => 'A reference number is required for this refund method.']);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function assertReferenceNotAlreadyUsed(int $hospitalId, string $method, ?string $referenceNumber): void
    {
        if (! filled($referenceNumber)) {
            return;
        }

        $exists = PaymentRefund::query()
            ->where('hospital_id', $hospitalId)
            ->where('refund_method', $method)
            ->where('refund_reference', $referenceNumber)
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'refund_reference' => 'This reference number has already been used for a refund at this facility.',
            ]);
        }
    }
}
