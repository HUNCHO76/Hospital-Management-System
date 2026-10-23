<?php

namespace App\Services;

use App\Models\PrescriptionOrder;
use App\Models\PrescriptionOrderItem;
use App\Models\PrescriptionVerification;
use App\Services\NHIF\NhifOrderPreApprovalService;
use App\Services\NHIF\NHIFPreauthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
/**
 * Owns the prescription verification workflow's write path (Phase 6, Step
 * 47) — mirrors RadiologyReportService's verify()/returnForCorrection()/
 * recordReviewStarted() shape exactly (transaction + row-lock + re-checked
 * transition + audited-event), applied to the new pharmacy-owned
 * PrescriptionVerification entity instead of retrofitting a status column
 * onto PrescriptionOrder itself.
 */
use Illuminate\Validation\ValidationException;

class PrescriptionVerificationService
{
    protected NhifOrderPreApprovalService $nhifOrderPreApproval;

    public function __construct(
        protected AuditLogService $auditLog,
        protected FinancialAuthorizationService $financialAuth,
        protected NHIFPreauthorizationService $nhifPreauth,
        ?NhifOrderPreApprovalService $nhifOrderPreApproval = null,
    ) {
        $this->nhifOrderPreApproval = $nhifOrderPreApproval ?? app(NhifOrderPreApprovalService::class);
    }

    /**
     * Create the verification row the moment a PrescriptionOrder is created
     * — called from PrescriptionOrderService::create() inside its own
     * transaction. There is no separate "submit" action in this
     * architecture (PrescriptionOrder::STATUS_ACTIVE's own docblock: an
     * order is created complete in one atomic step), so creation and
     * submission are the same moment.
     */
    public function initiate(PrescriptionOrder $order, Request $request): PrescriptionVerification
    {
        $verification = PrescriptionVerification::create([
            'prescription_order_id' => $order->id,
            'status' => PrescriptionVerification::STATUS_PENDING_VERIFICATION,
            'submitted_by' => $request->user()->id,
            'submitted_at' => now(),
        ]);

        $this->auditLog->record(
            'prescription_submitted',
            $verification,
            null,
            $verification->only(['prescription_order_id', 'status', 'submitted_by', 'submitted_at']),
            $request,
        );

        return $verification;
    }

    /**
     * Record that a verifier has opened a pending prescription for review
     * (spec §8/§15) — audit-only, no status transition, mirroring
     * RadiologyReportService::recordReviewStarted() exactly. Only fired
     * while the review is actually pending a decision, so re-opening an
     * already-decided order for reference does not spam the audit log.
     */
    public function recordReviewStarted(PrescriptionVerification $verification, Request $request): void
    {
        if ($verification->status !== PrescriptionVerification::STATUS_PENDING_VERIFICATION) {
            return;
        }

        $this->auditLog->record(
            'prescription_review_started',
            $verification,
            null,
            ['reviewed_by' => $request->user()->id],
            $request,
        );
    }

    /**
     * Verify (approve) a pending prescription (spec §15). Self-verification
     * is already blocked by PrescriptionOrderPolicy::verify() before this is
     * ever reached, but is re-checked here as well — the backend must
     * enforce this regardless of which path a request took (spec §5).
     *
     * @param  array{verification_notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function verify(PrescriptionOrder $order, array $data, Request $request): PrescriptionVerification
    {
        $verification = DB::transaction(function () use ($order, $data, $request) {
            $verification = PrescriptionVerification::query()->where('prescription_order_id', $order->id)->lockForUpdate()->firstOrFail();

            if (! $verification->canTransitionTo(PrescriptionVerification::STATUS_VERIFIED)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot verify a prescription with status \"{$verification->status}\".",
                ]);
            }

            if ($request->user()->id === $order->prescriber_id && ! config('pharmacy.allow_self_verification')) {
                throw ValidationException::withMessages([
                    'self_verification' => 'Independent verification is required. You cannot verify a prescription you wrote yourself.',
                ]);
            }

            $order = PrescriptionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertEligibleForVerification($order);

            $activeItemIds = $order->items()
                ->where('status', '!=', PrescriptionOrderItem::STATUS_CANCELLED)
                ->pluck('id')
                ->all();
            // Step 69: NHIF preauthorization gate
            $this->nhifPreauth->assertPreauthorizedForSources(PrescriptionOrderItem::class, $activeItemIds);
            $this->nhifOrderPreApproval->assertPrescriptionOrderCanVerify($order);

            // No-charge rule: when a medication has no configured price the
            // charge capture is skipped (audited). That billing gap is never a
            // reason to block clinical verification; only items that DO have a
            // charge require it to be fully authorized before proceeding.
            $this->financialAuth->assertAuthorizedForSources(
                PrescriptionOrderItem::class,
                $activeItemIds,
                FinancialAuthorizationService::STATUS_AUTHORIZED,
            );

            $verification->update([
                'status' => PrescriptionVerification::STATUS_VERIFIED,
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
                'verification_notes' => $this->validateText($data['verification_notes'] ?? null, 'verification_notes', 1000),
            ]);

            return $verification;
        });

        $this->auditLog->record(
            'prescription_verified',
            $verification,
            ['status' => PrescriptionVerification::STATUS_PENDING_VERIFICATION],
            $verification->only(['status', 'verified_by', 'verified_at']),
            $request,
        );

        if (filled($verification->verification_notes)) {
            $this->auditLog->record(
                'prescription_verification_note_added',
                $verification,
                null,
                ['verification_notes' => $verification->verification_notes],
                $request,
            );
        }

        return $verification;
    }

    /**
     * Return a pending prescription to the prescriber for correction (spec
     * §13). The order's own content is never modified here — only the
     * return metadata is recorded; the prescriber must correct it themselves
     * through PrescriptionOrderService::update(), then call resubmit().
     *
     * @param  array{return_reason: string}  $data
     *
     * @throws ValidationException
     */
    public function returnForCorrection(PrescriptionOrder $order, array $data, Request $request): PrescriptionVerification
    {
        $verification = DB::transaction(function () use ($order, $data, $request) {
            $verification = PrescriptionVerification::query()->where('prescription_order_id', $order->id)->lockForUpdate()->firstOrFail();

            if (! $verification->canTransitionTo(PrescriptionVerification::STATUS_RETURNED)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot return a prescription with status \"{$verification->status}\".",
                ]);
            }

            if ($request->user()->id === $order->prescriber_id && ! config('pharmacy.allow_self_verification')) {
                throw ValidationException::withMessages([
                    'self_verification' => 'Independent verification is required. You cannot return a prescription you wrote yourself.',
                ]);
            }

            $order = PrescriptionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertEligibleForVerification($order);

            $verification->update([
                'status' => PrescriptionVerification::STATUS_RETURNED,
                'returned_by' => $request->user()->id,
                'returned_at' => now(),
                'return_reason' => $data['return_reason'],
            ]);

            return $verification;
        });

        $this->auditLog->record(
            'prescription_returned',
            $verification,
            ['status' => PrescriptionVerification::STATUS_PENDING_VERIFICATION],
            $verification->only(['status', 'returned_by', 'returned_at', 'return_reason']),
            $request,
        );

        return $verification;
    }

    /**
     * Resubmit a returned prescription for verification (spec §14) — an
     * explicit prescriber action distinct from the correction edit itself
     * (PrescriptionOrderService::update()), so "corrected" and "resubmitted"
     * remain two separately-auditable events, matching spec §14's own
     * PRESCRIPTION_UPDATED / PRESCRIPTION_RESUBMITTED distinction.
     * Ownership (only the prescriber, or a hospital-admin) is enforced by
     * PrescriptionOrderPolicy::update() in the controller before this is
     * reached.
     *
     * @throws ValidationException
     */
    public function resubmit(PrescriptionOrder $order, Request $request): PrescriptionVerification
    {
        $verification = DB::transaction(function () use ($order, $request) {
            $verification = PrescriptionVerification::query()->where('prescription_order_id', $order->id)->lockForUpdate()->firstOrFail();

            if (! $verification->canTransitionTo(PrescriptionVerification::STATUS_PENDING_VERIFICATION)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot resubmit a prescription with status \"{$verification->status}\".",
                ]);
            }

            $order = PrescriptionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertEligibleForVerification($order);

            $verification->update([
                'status' => PrescriptionVerification::STATUS_PENDING_VERIFICATION,
                'submitted_by' => $request->user()->id,
                'submitted_at' => now(),
            ]);

            return $verification;
        });

        $this->auditLog->record(
            'prescription_resubmitted',
            $verification,
            ['status' => PrescriptionVerification::STATUS_RETURNED],
            $verification->only(['status', 'submitted_by', 'submitted_at']),
            $request,
        );

        return $verification;
    }

    /**
     * Defense-in-depth re-check mirroring RadiologyReportService::
     * assertEligibleForReporting() — a prescription should never remain
     * verifiable if its underlying order was cancelled after entering the
     * verification queue.
     *
     * @throws ValidationException
     */
    protected function assertEligibleForVerification(PrescriptionOrder $order): void
    {
        if ($order->status !== PrescriptionOrder::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'status' => "Cannot act on a prescription order with status \"{$order->status}\".",
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function validateText(?string $value, string $field, int $maxLength): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            throw ValidationException::withMessages([
                $field => ucfirst($field)." may not exceed {$maxLength} characters.",
            ]);
        }

        return $value;
    }
}
