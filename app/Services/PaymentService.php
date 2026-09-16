<?php

namespace App\Services;

use App\Models\BillingAdjustment;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\PatientAccount;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentRefund;
use App\Models\PaymentReversal;
use App\Services\Concerns\ValidatesMoney;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the payment lifecycle and every authoritative outstanding-balance
 * calculation in the billing domain (Phase 7, Step 57, spec §57.13/§57.18)
 * — the one financial calculation service spec §57.13 asks for, so no
 * controller, service, or React component ever (re)derives "how much does
 * the patient still owe" independently.
 *
 * A payment is created directly as POSTED (spec §57.19's own transaction
 * pseudocode bundles creation, allocation, and receipt-number generation
 * into one block) — mirrors `StockService::receive()`'s own "a real,
 * immediate event, no meaningful draft state" precedent rather than
 * `ChargeService`/`InvoiceService`'s own build-then-finalize documents:
 * money is already in hand by the time anyone records it, so there is
 * nothing to "post" later. `markFailed()` is this step's one controlled
 * correction mechanism (mirrors `ChargeService::cancel()`'s own "minimal
 * correction, not a full Adjustment entity" — genuine reversal/refund is
 * Step 58's job).
 */
class PaymentService
{
    use ValidatesMoney;

    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * The authoritative "what does this invoice's patient responsibility
     * currently look like" calculation (spec §57.6/§57.12/§57.20) — every
     * figure the create-payment screen shows, and the one calculation
     * `createPayment()` itself revalidates against under a lock. Never
     * trusts a stored value: the invoice total and insurance responsibility
     * are recomputed live from currently-POSTED charges and their own
     * ChargeInsuranceAllocation (Step 56) — exactly `InvoiceService::
     * currentTotal()`'s own "never derive financial truth from a stored
     * field" principle, extended one layer further.
     *
     * A charge with no insurance allocation at all is self-pay — its full
     * `net_amount` is patient responsibility, never zero (spec §57.7's own
     * "insurance allocation remains a separate financial concept").
     *
     * @return array{invoice_total: float, insurance_responsibility: float, patient_responsibility: float, patient_paid: float, reversals_total: float, refunds_total: float, credit_adjustments: float, debit_adjustments: float, patient_outstanding: float, status: string}
     */
    public function calculateOutstanding(Invoice $invoice): array
    {
        $charges = $invoice->charges()->where('status', Charge::STATUS_POSTED)->with('insuranceAllocation')->get();

        $invoiceTotal = round((float) $charges->sum('net_amount'), 2);

        $patientResponsibility = round($charges->sum(function (Charge $charge) {
            return $charge->insuranceAllocation
                ? (float) $charge->insuranceAllocation->patient_responsibility
                : (float) $charge->net_amount;
        }), 2);

        $insuranceResponsibility = round($invoiceTotal - $patientResponsibility, 2);

        // Gross cash allocations from POSTED payments (unchanged from Step 57).
        $patientPaid = round((float) PaymentAllocation::query()
            ->where('invoice_id', $invoice->id)
            ->whereHas('payment', fn ($query) => $query->where('status', Payment::STATUS_POSTED))
            ->sum('amount'), 2);

        // Step 58: reversals negate entire payments; refunds return a portion.
        $reversalsTotal = round((float) PaymentReversal::query()
            ->where('invoice_id', $invoice->id)
            ->sum('amount'), 2);

        $refundsTotal = round((float) PaymentRefund::query()
            ->where('invoice_id', $invoice->id)
            ->sum('amount'), 2);

        // Step 58: credit adjustments reduce outstanding; debit adjustments increase it.
        $creditAdjustments = round((float) BillingAdjustment::query()
            ->where('invoice_id', $invoice->id)
            ->where('direction', BillingAdjustment::DIRECTION_CREDIT)
            ->sum('amount'), 2);

        $debitAdjustments = round((float) BillingAdjustment::query()
            ->where('invoice_id', $invoice->id)
            ->where('direction', BillingAdjustment::DIRECTION_DEBIT)
            ->sum('amount'), 2);

        $patientOutstanding = round(
            $patientResponsibility - $patientPaid + $reversalsTotal + $refundsTotal - $creditAdjustments + $debitAdjustments,
            2
        );

        $status = match (true) {
            $patientResponsibility <= 0.0 => 'paid',
            $patientOutstanding <= 0.0 => 'paid',
            $patientOutstanding < $patientResponsibility => 'partially_paid',
            default => 'unpaid',
        };

        return [
            'invoice_total' => $invoiceTotal,
            'insurance_responsibility' => $insuranceResponsibility,
            'patient_responsibility' => $patientResponsibility,
            'patient_paid' => $patientPaid,
            'reversals_total' => $reversalsTotal,
            'refunds_total' => $refundsTotal,
            'credit_adjustments' => $creditAdjustments,
            'debit_adjustments' => $debitAdjustments,
            'patient_outstanding' => $patientOutstanding,
            'status' => $status,
        ];
    }

    /**
     * The account-wide outstanding total across every POSTED invoice (spec
     * §57.13) — sums calculateOutstanding()'s own per-invoice figure, never
     * a second, independently-derived formula.
     */
    public function outstandingBalanceForAccount(PatientAccount $account): float
    {
        $invoices = $account->invoices()->where('status', Invoice::STATUS_POSTED)->get();

        return round($invoices->sum(fn (Invoice $invoice) => $this->calculateOutstanding($invoice)['patient_outstanding']), 2);
    }

    /**
     * Record a payment against an invoice's outstanding patient
     * responsibility (spec §57.6/§57.9/§57.14/§57.19) — the full
     * transactional entry point. Locks the invoice first (the same
     * serialization point every other billing transition in this codebase
     * already uses), re-validates eligibility and re-computes outstanding
     * under that lock (never trusting whatever the create-screen last
     * showed), rejects an amount exceeding it (spec §57.9's own overpayment
     * protection — no unapplied-credit/floating-balance concept exists in
     * this architecture to accept the excess into), and creates the
     * Payment and its PaymentAllocation together.
     *
     * @param  array{invoice_id: int, amount: float|string, payment_date?: string|null, payment_method: string, reference_number?: string|null, notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function createPayment(array $data, Request $request): Payment
    {
        $this->validateMethodAndReference($data);
        $amount = $this->validateMoney($data['amount'] ?? null, 'amount', allowZero: false);
        $paymentDate = $this->validatePaymentDate($data['payment_date'] ?? null);

        $payment = DB::transaction(function () use ($data, $amount, $paymentDate, $request) {
            $invoice = Invoice::query()->whereKey($data['invoice_id'])->lockForUpdate()->firstOrFail();

            if ($invoice->status !== Invoice::STATUS_POSTED) {
                throw ValidationException::withMessages([
                    'invoice_id' => "Cannot record a payment against an invoice with status \"{$invoice->status}\".",
                ]);
            }

            $outstanding = $this->calculateOutstanding($invoice);

            if ($outstanding['patient_outstanding'] <= 0.0) {
                throw ValidationException::withMessages([
                    'amount' => 'This invoice has no outstanding patient balance.',
                ]);
            }

            if (round($amount, 2) > $outstanding['patient_outstanding']) {
                throw ValidationException::withMessages([
                    'amount' => "Payment amount exceeds the outstanding patient balance ({$outstanding['patient_outstanding']}).",
                ]);
            }

            $this->assertReferenceNotAlreadyUsed($invoice->hospital_id, $data['payment_method'], $data['reference_number'] ?? null);

            $payment = Payment::create([
                'hospital_id' => $invoice->hospital_id,
                'patient_id' => $invoice->patient_id,
                'patient_account_id' => $invoice->patient_account_id,
                'invoice_id' => $invoice->id,
                'payment_date' => $paymentDate,
                'amount' => $amount,
                'currency' => $invoice->currency,
                'payment_method' => $data['payment_method'],
                'reference_number' => filled($data['reference_number'] ?? null) ? $data['reference_number'] : null,
                'status' => Payment::STATUS_POSTED,
                'notes' => filled($data['notes'] ?? null) ? $data['notes'] : null,
                'received_by' => $request->user()->id,
                'posted_at' => now(),
            ]);

            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'hospital_id' => $invoice->hospital_id,
                'patient_id' => $invoice->patient_id,
                'amount' => $amount,
            ]);

            return $payment;
        });

        $this->auditLog->record(
            'payment_posted',
            $payment,
            null,
            $payment->only(['payment_number', 'receipt_number', 'invoice_id', 'amount', 'payment_method', 'reference_number', 'status']),
            $request,
        );

        $this->auditLog->record(
            'receipt_generated',
            $payment,
            null,
            ['receipt_number' => $payment->receipt_number],
            $request,
        );

        return $payment->fresh(['allocations', 'invoice', 'receivedBy']);
    }

    /**
     * The one controlled correction this step provides (spec §57.4/§57.16)
     * — a POSTED payment discovered to be invalid after the fact (a bounced
     * transfer, a reference later found fake). Never edits or deletes the
     * row; a mandatory reason is recorded. A failed payment's own
     * PaymentAllocation is left in place (spec's own "never delete a
     * financial record") but stops counting toward "patient paid" the
     * moment its parent payment's status changes — calculateOutstanding()
     * only sums allocations whose payment is still POSTED.
     *
     * @throws ValidationException
     */
    public function markFailed(Payment $payment, string $reason, Request $request): Payment
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        $payment = DB::transaction(function () use ($payment, $reason, $request) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! $locked->canTransitionTo(Payment::STATUS_FAILED)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot mark a payment with status \"{$locked->status}\" as failed.",
                ]);
            }

            $locked->update([
                'status' => Payment::STATUS_FAILED,
                'failed_at' => now(),
                'failed_by' => $request->user()->id,
                'failure_reason' => $reason,
            ]);

            return $locked;
        });

        $this->auditLog->record(
            'payment_failed',
            $payment,
            ['status' => Payment::STATUS_POSTED],
            $payment->only(['status', 'failure_reason']),
            $request,
        );

        return $payment->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    protected function validateMethodAndReference(array $data): void
    {
        $method = $data['payment_method'] ?? null;

        if (! in_array($method, Payment::METHODS, true)) {
            throw ValidationException::withMessages(['payment_method' => 'Invalid payment method.']);
        }

        if (in_array($method, Payment::METHODS_REQUIRING_REFERENCE, true) && trim((string) ($data['reference_number'] ?? '')) === '') {
            throw ValidationException::withMessages(['reference_number' => 'A reference number is required for this payment method.']);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function validatePaymentDate(?string $date): Carbon
    {
        $parsed = $date !== null ? Carbon::parse($date)->startOfDay() : now()->startOfDay();

        if ($parsed->isFuture()) {
            throw ValidationException::withMessages(['payment_date' => 'Payment date cannot be in the future.']);
        }

        return $parsed;
    }

    /**
     * Defense in depth beyond the database's own UNIQUE constraint (spec
     * §57.15) — a locked, explicit re-check inside the same transaction
     * that a losing concurrent insert would otherwise only discover via a
     * raw constraint-violation exception.
     *
     * @throws ValidationException
     */
    protected function assertReferenceNotAlreadyUsed(int $hospitalId, string $method, ?string $referenceNumber): void
    {
        if (! filled($referenceNumber)) {
            return;
        }

        $exists = Payment::query()
            ->where('hospital_id', $hospitalId)
            ->where('payment_method', $method)
            ->where('reference_number', $referenceNumber)
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'reference_number' => 'This reference number has already been used for a payment at this facility.',
            ]);
        }
    }
}
