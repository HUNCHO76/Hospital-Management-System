<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\PatientAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the invoice lifecycle (Phase 7, Step 55): create (always DRAFT),
 * add/remove charges while still DRAFT, post (DRAFT -> POSTED, the moment
 * it becomes a real financial document), void (DRAFT -> VOIDED, abandoned
 * before ever becoming one), cancel (POSTED -> CANCELLED, a controlled
 * correction after the fact). Mirrors ChargeService's own shape closely —
 * every transition row-locked and audited, financial values never editable
 * once posted.
 *
 * No separate "invoice item" write path exists: `Charge` already carries
 * every value an invoice line needs, so "adding a charge to an invoice" is
 * simply setting `charges.invoice_id` under a lock, never copying data into
 * a second table (see the create_invoices_table migration's own docblock).
 */
class InvoiceService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Start a new draft invoice for a patient account, optionally seeded
     * with an initial set of eligible charges (spec §27's own builder
     * flow). An empty draft is valid — charges may also be added later via
     * addCharge() while still DRAFT; only post() requires at least one.
     *
     * The whole operation — the invoice row plus every requested charge
     * link — is one outer transaction: if any requested charge turns out
     * to be ineligible, nothing is created at all, never a half-built,
     * orphaned empty draft (spec §40's "no partial" principle, applied
     * here to creation as well as posting).
     *
     * @param  list<int>  $chargeIds
     *
     * @throws ValidationException
     */
    public function createDraft(PatientAccount $account, array $chargeIds, ?string $notes, Request $request): Invoice
    {
        $invoice = DB::transaction(function () use ($account, $chargeIds, $notes, $request) {
            $invoice = Invoice::create([
                'hospital_id' => $account->hospital_id,
                'patient_id' => $account->patient_id,
                'patient_account_id' => $account->id,
                'status' => Invoice::STATUS_DRAFT,
                'currency' => $account->currency,
                'notes' => $notes,
                'created_by' => $request->user()->id,
            ]);

            foreach (array_unique($chargeIds) as $chargeId) {
                $charge = Charge::query()->whereKey($chargeId)->firstOrFail();
                $this->addCharge($invoice, $charge, $request);
            }

            return $invoice;
        });

        $this->auditLog->record(
            'invoice_created',
            $invoice,
            null,
            $invoice->only(['invoice_number', 'patient_account_id', 'status']),
            $request,
        );

        return $invoice->fresh('charges');
    }

    /**
     * Link one eligible charge to a still-DRAFT invoice (spec §10/§16/§17/
     * §18/§20): the charge must be POSTED, not already invoiced, and belong
     * to this invoice's own patient account — re-validated here regardless
     * of whatever list the frontend showed (spec §16's own "never rely on
     * the frontend list"). Row-locks the charge and re-checks its
     * `invoice_id` under that lock, which is what makes a concurrent
     * duplicate-invoicing attempt on the same charge impossible (spec §20/
     * §21) — the loser's lock wait ends with the row already claimed, so
     * its own re-check simply fails.
     *
     * @throws ValidationException
     */
    public function addCharge(Invoice $invoice, Charge $charge, Request $request): Charge
    {
        $charge = DB::transaction(function () use ($invoice, $charge) {
            $lockedInvoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (! $lockedInvoice->isDraft()) {
                throw ValidationException::withMessages([
                    'status' => "Cannot add a charge to an invoice with status \"{$lockedInvoice->status}\".",
                ]);
            }

            $lockedCharge = Charge::query()->whereKey($charge->id)->lockForUpdate()->firstOrFail();

            if ($lockedCharge->status !== Charge::STATUS_POSTED) {
                throw ValidationException::withMessages([
                    'charge_id' => "Charge \"{$lockedCharge->charge_number}\" is not eligible for invoicing (status: {$lockedCharge->status}).",
                ]);
            }

            if ($lockedCharge->invoice_id !== null) {
                throw ValidationException::withMessages([
                    'charge_id' => "Charge \"{$lockedCharge->charge_number}\" is already included in another invoice.",
                ]);
            }

            if ($lockedCharge->patient_account_id !== $lockedInvoice->patient_account_id) {
                throw ValidationException::withMessages([
                    'charge_id' => 'This charge does not belong to the same patient account as the invoice.',
                ]);
            }

            if ($lockedCharge->hospital_id !== $lockedInvoice->hospital_id || $lockedCharge->patient_id !== $lockedInvoice->patient_id) {
                throw ValidationException::withMessages([
                    'charge_id' => 'This charge does not belong to the same patient/facility as the invoice.',
                ]);
            }

            $lockedCharge->update(['invoice_id' => $lockedInvoice->id]);

            return $lockedCharge;
        });

        $this->auditLog->record(
            'charge_added_to_invoice',
            $charge,
            ['invoice_id' => null],
            ['invoice_id' => $charge->invoice_id],
            $request,
        );

        return $charge;
    }

    /**
     * Unlink a charge from a still-DRAFT invoice (spec §11's own "remove
     * charges" before posting). The charge itself is never touched beyond
     * this — its own POSTED status is unaffected, it simply becomes
     * eligible for a different invoice again.
     *
     * @throws ValidationException
     */
    public function removeChargeBeforePosting(Invoice $invoice, Charge $charge, Request $request): void
    {
        DB::transaction(function () use ($invoice, $charge) {
            $lockedInvoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (! $lockedInvoice->isDraft()) {
                throw ValidationException::withMessages([
                    'status' => "Cannot remove a charge from an invoice with status \"{$lockedInvoice->status}\".",
                ]);
            }

            $lockedCharge = Charge::query()->whereKey($charge->id)->lockForUpdate()->firstOrFail();

            if ($lockedCharge->invoice_id !== $lockedInvoice->id) {
                throw ValidationException::withMessages([
                    'charge_id' => 'This charge is not part of this invoice.',
                ]);
            }

            $lockedCharge->update(['invoice_id' => null]);
        });

        $this->auditLog->record(
            'charge_removed_from_invoice',
            $charge,
            ['invoice_id' => $invoice->id],
            ['invoice_id' => null],
            $request,
        );
    }

    /**
     * The invoice's current subtotal/total, computed live from its
     * currently-linked charges — always fresh, never stored, while the
     * invoice is still a DRAFT (mirrors PatientAccountService::balance()'s
     * own "never derive financial truth from the UI, always recompute"
     * principle). Once POSTED, `Invoice.subtotal`/`total_amount` are the
     * frozen, authoritative values instead — see post().
     */
    public function currentTotal(Invoice $invoice): float
    {
        return (float) $invoice->charges()->sum('net_amount');
    }

    /**
     * Finalize a DRAFT invoice (spec §12/§40) — the critical transition.
     * Locks the invoice and every one of its linked charges, re-validates
     * each charge is still POSTED/unlinked-elsewhere/consistent (a charge
     * could theoretically have been cancelled between being added and now),
     * recalculates and freezes `subtotal`/`total_amount` server-side, and
     * requires at least one charge — an empty invoice is never posted.
     *
     * @throws ValidationException
     */
    public function post(Invoice $invoice, Request $request): Invoice
    {
        $invoice = DB::transaction(function () use ($invoice, $request) {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (! $locked->canTransitionTo(Invoice::STATUS_POSTED)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot post an invoice with status \"{$locked->status}\".",
                ]);
            }

            $charges = Charge::query()->where('invoice_id', $locked->id)->lockForUpdate()->get();

            if ($charges->isEmpty()) {
                throw ValidationException::withMessages([
                    'charges' => 'At least one charge must be included before posting an invoice.',
                ]);
            }

            foreach ($charges as $charge) {
                if ($charge->status !== Charge::STATUS_POSTED) {
                    throw ValidationException::withMessages([
                        'charges' => "Charge \"{$charge->charge_number}\" is no longer eligible (status: {$charge->status}) and must be removed before posting.",
                    ]);
                }

                if ($charge->patient_account_id !== $locked->patient_account_id) {
                    throw ValidationException::withMessages([
                        'charges' => "Charge \"{$charge->charge_number}\" does not belong to this invoice's patient account.",
                    ]);
                }
            }

            $subtotal = round((float) $charges->sum('net_amount'), 2);

            $locked->update([
                'status' => Invoice::STATUS_POSTED,
                'invoice_date' => $locked->invoice_date ?? now()->toDateString(),
                'subtotal' => $subtotal,
                'total_amount' => $subtotal,
                'posted_at' => now(),
                'updated_by' => $request->user()->id,
            ]);

            return $locked;
        });

        $this->auditLog->record(
            'invoice_posted',
            $invoice,
            ['status' => Invoice::STATUS_DRAFT],
            $invoice->only(['status', 'subtotal', 'total_amount', 'posted_at']),
            $request,
        );

        return $invoice->fresh('charges');
    }

    /**
     * Void a DRAFT invoice — it never became a financial document, so this
     * requires no more than the actor's own reason (mirrors
     * ChargeService::void()'s identical precedent). Every linked charge is
     * released back to eligible.
     *
     * @throws ValidationException
     */
    public function void(Invoice $invoice, string $reason, Request $request): Invoice
    {
        return $this->transitionToCancelledState($invoice, Invoice::STATUS_VOIDED, $reason, $request, 'invoice_voided');
    }

    /**
     * Cancel a POSTED invoice — a controlled correction after the fact
     * (spec §13/§30): the row is never edited or deleted, only its status
     * changes, with a mandatory reason. Every linked charge is released
     * (`invoice_id` cleared) so it can be included on a corrected invoice —
     * the charges themselves are not touched, only the grouping is undone.
     *
     * @throws ValidationException
     */
    public function cancel(Invoice $invoice, string $reason, Request $request): Invoice
    {
        return $this->transitionToCancelledState($invoice, Invoice::STATUS_CANCELLED, $reason, $request, 'invoice_cancelled');
    }

    /**
     * @throws ValidationException
     */
    protected function transitionToCancelledState(Invoice $invoice, string $targetStatus, string $reason, Request $request, string $auditAction): Invoice
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        $invoice = DB::transaction(function () use ($invoice, $targetStatus, $reason, $request) {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (! $locked->canTransitionTo($targetStatus)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot change an invoice with status \"{$locked->status}\" to \"{$targetStatus}\".",
                ]);
            }

            Charge::query()->where('invoice_id', $locked->id)->update(['invoice_id' => null]);

            $locked->update([
                'status' => $targetStatus,
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()->id,
                'cancellation_reason' => $reason,
            ]);

            return $locked;
        });

        $this->auditLog->record($auditAction, $invoice, null, $invoice->only(['status', 'cancellation_reason']), $request);

        return $invoice->fresh('charges');
    }
}
