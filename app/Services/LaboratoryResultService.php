<?php

namespace App\Services;

use App\Models\LaboratoryOrderItem;
use App\Models\LaboratoryResult;
use App\Models\LaboratorySample;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the result-entry, verification/authorization, and release lifecycle
 * (Phase 4, Steps 33-35): a result is created automatically the moment its
 * test's processing reaches RESULT_PENDING (see initializeForItem(), called
 * from LaboratoryProcessingController::complete() — mirrors Step 32's own
 * controller-orchestrated auto-initialization, since LaboratoryProcessingService
 * must not depend on this downstream service). Ends at RELEASED. Report
 * generation itself is LaboratoryReportService's job, not this class's —
 * see that service's own docblock for why the two are kept separate.
 */
class LaboratoryResultService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Create the result record for a single test the moment its processing
     * reaches RESULT_PENDING, snapshotting the catalog's current result
     * configuration (spec §17/§34's historical-integrity concern — the
     * catalog's own result_type/unit/reference_range/result_options remain
     * editable after a test has been referenced, so a later configuration
     * change must never retroactively reinterpret an existing result).
     * Idempotent: never creates a duplicate row for the same item.
     */
    public function initializeForItem(LaboratoryOrderItem $item, LaboratorySample $sample, Request $request): void
    {
        $test = $item->laboratoryTest;

        LaboratoryResult::firstOrCreate(
            ['laboratory_order_item_id' => $item->id],
            [
                'laboratory_sample_id' => $sample->id,
                'laboratory_test_id' => $item->laboratory_test_id,
                'status' => LaboratoryResult::STATUS_RESULT_PENDING,
                'result_type' => $test?->result_type,
                'unit' => $test?->unit,
                'reference_range' => $test?->reference_range,
                'result_options' => $test?->result_options,
            ],
        );
    }

    /**
     * Save (or continue editing) a draft result. Reachable from
     * RESULT_PENDING (first save), DRAFT (subsequent saves), or
     * RETURNED_FOR_CORRECTION (Step 34 spec §21 — a verifier's return sends
     * a result straight back into ordinary draft editing, rejoining the
     * exact same submit() path every other draft uses rather than a second
     * resubmission mechanism). A draft may be saved with an incomplete/
     * blank value (spec §23); only submit() requires a fully valid, present
     * value.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function saveDraft(LaboratoryResult $result, array $data, Request $request): LaboratoryResult
    {
        $fromStatus = null;

        $result = DB::transaction(function () use ($result, $data, $request, &$fromStatus) {
            $result = LaboratoryResult::query()->whereKey($result->id)->lockForUpdate()->firstOrFail();

            $editableFrom = [
                LaboratoryResult::STATUS_RESULT_PENDING,
                LaboratoryResult::STATUS_DRAFT,
                LaboratoryResult::STATUS_RETURNED_FOR_CORRECTION,
            ];

            if (! in_array($result->status, $editableFrom, true)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot edit a result with status \"{$result->status}\".",
                ]);
            }

            $fromStatus = $result->status;

            $values = $this->validateValue($result, $data, requirePresence: false);

            $result->update([
                ...$values,
                'abnormal_flag' => $this->validateFlag($data),
                'result_comment' => $this->validateComment($data),
                'status' => LaboratoryResult::STATUS_DRAFT,
                'entered_by' => $result->entered_by ?? $request->user()->id,
                'entered_at' => $result->entered_at ?? now(),
            ]);

            return $result;
        });

        $this->auditLog->record(
            $fromStatus === LaboratoryResult::STATUS_RESULT_PENDING ? 'laboratory_result_created' : 'laboratory_result_updated',
            $result,
            null,
            $result->only(['value_numeric', 'value_text', 'value_code', 'abnormal_flag', 'result_comment']),
            $request,
        );

        if ($fromStatus !== LaboratoryResult::STATUS_DRAFT) {
            $this->auditLog->record(
                'laboratory_result_status_changed',
                $result,
                ['status' => $fromStatus],
                ['status' => LaboratoryResult::STATUS_DRAFT],
                $request,
            );
        }

        return $result;
    }

    /**
     * Submit a completed draft for verification (or re-submit one that was
     * returned for correction and re-edited). Requires a fully valid,
     * present value — draft's own looser validation does not apply here
     * (spec §24).
     *
     * @throws ValidationException
     */
    public function submit(LaboratoryResult $result, Request $request): LaboratoryResult
    {
        $result = DB::transaction(function () use ($result, $request) {
            $result = LaboratoryResult::query()->whereKey($result->id)->lockForUpdate()->firstOrFail();

            // Deliberately checked against the exact DRAFT status rather
            // than the generic canTransitionTo() — PENDING_VERIFICATION is
            // also reachable from VERIFIED/AUTHORIZED via amend()'s own
            // controlled reset, but that path is not "submission" and must
            // never be reachable through this action.
            if ($result->status !== LaboratoryResult::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => "Cannot submit a result with status \"{$result->status}\".",
                ]);
            }

            // Re-validate the already-saved value against the "must be
            // present" rule — a draft may have been saved blank.
            $this->validateValue($result, $result->only(['value_numeric', 'value_text', 'value_code']), requirePresence: true);

            $result->update([
                'status' => LaboratoryResult::STATUS_PENDING_VERIFICATION,
                'submitted_by' => $request->user()->id,
                'submitted_at' => now(),
            ]);

            return $result;
        });

        $this->auditLog->record(
            'laboratory_result_submitted',
            $result,
            null,
            $result->only(['submitted_by', 'submitted_at']),
            $request,
        );

        $this->auditLog->record(
            'laboratory_result_status_changed',
            $result,
            ['status' => LaboratoryResult::STATUS_DRAFT],
            ['status' => LaboratoryResult::STATUS_PENDING_VERIFICATION],
            $request,
        );

        return $result;
    }

    /**
     * Begin a verifier's review of a submitted result (Step 34 spec §16/§25
     * — "review started" is its own distinct, audited event, not folded
     * silently into verify()). Role/self-review separation (spec §6-7) is
     * enforced by LaboratoryResultPolicy before this is ever called; this
     * only enforces the status transition itself.
     *
     * @throws ValidationException
     */
    public function startReview(LaboratoryResult $result, Request $request): LaboratoryResult
    {
        $result = DB::transaction(function () use ($result, $request) {
            $result = LaboratoryResult::query()->whereKey($result->id)->lockForUpdate()->firstOrFail();

            if (! $result->canTransitionTo(LaboratoryResult::STATUS_UNDER_REVIEW)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot start review on a result with status \"{$result->status}\".",
                ]);
            }

            $result->update([
                'status' => LaboratoryResult::STATUS_UNDER_REVIEW,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            return $result;
        });

        $this->auditLog->record(
            'laboratory_result_review_started',
            $result,
            null,
            $result->only(['reviewed_by', 'reviewed_at']),
            $request,
        );

        $this->auditLog->record(
            'laboratory_result_status_changed',
            $result,
            ['status' => LaboratoryResult::STATUS_PENDING_VERIFICATION],
            ['status' => LaboratoryResult::STATUS_UNDER_REVIEW],
            $request,
        );

        return $result;
    }

    /**
     * Verify a result under review (spec §16). Re-validates the stored
     * value against the same rules result entry itself enforces (spec §13)
     * — defensive re-confirmation, since a value already had to pass this
     * exact check to ever be submitted; sample/processing-completeness
     * eligibility (spec §8) is enforced by construction (a LaboratoryResult
     * row cannot exist unless its processing already reached RESULT_PENDING,
     * a terminal LaboratoryProcessing state — see LaboratoryProcessing::
     * TRANSITIONS), so there is nothing further to re-check there.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function verifyResult(LaboratoryResult $result, array $data, Request $request): LaboratoryResult
    {
        $result = DB::transaction(function () use ($result, $data, $request) {
            $result = LaboratoryResult::query()->whereKey($result->id)->lockForUpdate()->firstOrFail();

            if (! $result->canTransitionTo(LaboratoryResult::STATUS_VERIFIED)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot verify a result with status \"{$result->status}\".",
                ]);
            }

            $this->validateValue($result, $result->only(['value_numeric', 'value_text', 'value_code']), requirePresence: true);

            $result->update([
                'status' => LaboratoryResult::STATUS_VERIFIED,
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
                'verification_comment' => $this->validateComment(['result_comment' => $data['verification_comment'] ?? null]),
            ]);

            return $result;
        });

        $this->auditLog->record(
            'laboratory_result_verified',
            $result,
            null,
            $result->only(['verified_by', 'verified_at', 'verification_comment']),
            $request,
        );

        $this->auditLog->record(
            'laboratory_result_status_changed',
            $result,
            ['status' => LaboratoryResult::STATUS_UNDER_REVIEW],
            ['status' => LaboratoryResult::STATUS_VERIFIED],
            $request,
        );

        return $result;
    }

    /**
     * Authorize (sign off) a verified result — the final gate before it is
     * "ready for release" (spec §17/§27). The same user may both verify and
     * authorize a result (only entry-vs-review separation is enforced by
     * policy, spec §39); authorization is nonetheless always its own
     * distinct, audited action, never folded into verify().
     *
     * @throws ValidationException
     */
    public function authorizeResult(LaboratoryResult $result, Request $request): LaboratoryResult
    {
        $result = DB::transaction(function () use ($result, $request) {
            $result = LaboratoryResult::query()->whereKey($result->id)->lockForUpdate()->firstOrFail();

            if (! $result->canTransitionTo(LaboratoryResult::STATUS_AUTHORIZED)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot authorize a result with status \"{$result->status}\".",
                ]);
            }

            $result->update([
                'status' => LaboratoryResult::STATUS_AUTHORIZED,
                'authorized_by' => $request->user()->id,
                'authorized_at' => now(),
            ]);

            return $result;
        });

        $this->auditLog->record(
            'laboratory_result_authorized',
            $result,
            null,
            $result->only(['authorized_by', 'authorized_at']),
            $request,
        );

        $this->auditLog->record(
            'laboratory_result_status_changed',
            $result,
            ['status' => LaboratoryResult::STATUS_VERIFIED],
            ['status' => LaboratoryResult::STATUS_AUTHORIZED],
            $request,
        );

        return $result;
    }

    /**
     * Release an authorized result (Step 35 Part 4/6) — the final,
     * server-authoritative gate before a result becomes an official,
     * printable clinical document. Only ever reachable from AUTHORIZED;
     * every earlier eligibility condition (sample validity, processing
     * completion, correct patient/order/test relationships) is enforced by
     * construction throughout this result's entire lifecycle, so nothing
     * further needs re-checking here beyond the status itself. Report
     * generation is a deliberately separate, controller-orchestrated step
     * (LaboratoryReportService::generateForOrder()) — see this class's own
     * docblock for why.
     *
     * @param  array{release_comment?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function release(LaboratoryResult $result, array $data, Request $request): LaboratoryResult
    {
        $result = DB::transaction(function () use ($result, $data, $request) {
            $result = LaboratoryResult::query()->whereKey($result->id)->lockForUpdate()->firstOrFail();

            if ($result->status === LaboratoryResult::STATUS_RELEASED) {
                throw ValidationException::withMessages([
                    'status' => 'This result has already been released.',
                ]);
            }

            if (! $result->canTransitionTo(LaboratoryResult::STATUS_RELEASED)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot release a result with status \"{$result->status}\".",
                ]);
            }

            $result->update([
                'status' => LaboratoryResult::STATUS_RELEASED,
                'released_by' => $request->user()->id,
                'released_at' => now(),
                'release_comment' => $this->validateComment(['result_comment' => $data['release_comment'] ?? null]),
            ]);

            return $result;
        });

        $this->auditLog->record(
            'laboratory_result_released',
            $result,
            null,
            $result->only(['released_by', 'released_at', 'release_comment']),
            $request,
        );

        $this->auditLog->record(
            'laboratory_result_status_changed',
            $result,
            ['status' => LaboratoryResult::STATUS_AUTHORIZED],
            ['status' => LaboratoryResult::STATUS_RELEASED],
            $request,
        );

        return $result;
    }

    /**
     * Return a result under review back to result-entry staff for
     * correction (spec §20-21) — a reason is always required; the result
     * re-enters ordinary draft editing via saveDraft()/submit() rather than
     * a separate resubmission mechanism.
     *
     * @param  array{return_reason: string, return_notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function returnForCorrection(LaboratoryResult $result, array $data, Request $request): LaboratoryResult
    {
        $result = DB::transaction(function () use ($result, $data, $request) {
            $result = LaboratoryResult::query()->whereKey($result->id)->lockForUpdate()->firstOrFail();

            if (! $result->canTransitionTo(LaboratoryResult::STATUS_RETURNED_FOR_CORRECTION)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot return a result with status \"{$result->status}\".",
                ]);
            }

            $result->update([
                'status' => LaboratoryResult::STATUS_RETURNED_FOR_CORRECTION,
                'returned_by' => $request->user()->id,
                'returned_at' => now(),
                'return_reason' => $data['return_reason'],
                'return_notes' => $data['return_notes'] ?? null,
            ]);

            return $result;
        });

        $this->auditLog->record(
            'laboratory_result_returned_for_correction',
            $result,
            null,
            $result->only(['returned_by', 'returned_at', 'return_reason', 'return_notes']),
            $request,
        );

        $this->auditLog->record(
            'laboratory_result_status_changed',
            $result,
            ['status' => LaboratoryResult::STATUS_UNDER_REVIEW],
            ['status' => LaboratoryResult::STATUS_RETURNED_FOR_CORRECTION],
            $request,
        );

        return $result;
    }

    /**
     * Correct a result's value. Before it has ever been verified
     * (PENDING_VERIFICATION), this is the original Step 33 behavior — the
     * value is corrected and status stays at PENDING_VERIFICATION. Once
     * VERIFIED, AUTHORIZED, or RELEASED, a correction is a controlled
     * amendment (Step 34 spec §22, extended by Step 35 Part 17): the
     * original value is never silently overwritten (every branch audits
     * old/new values and the reason), but a post-verification amendment
     * additionally forces the result all the way back to
     * PENDING_VERIFICATION — clearing verification/authorization/release
     * metadata from the live row — so it must be fully re-reviewed,
     * re-verified, re-authorized, and re-released. The original
     * verification/release is never deleted: it remains fully recoverable
     * from the audit log (and, for an already-released result, from the
     * report version that was already generated — see
     * LaboratoryResultController::amend() for the accompanying
     * supersedeActiveForOrder() call).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function amend(LaboratoryResult $result, array $data, Request $request): LaboratoryResult
    {
        $oldValues = null;
        $fromStatus = null;

        $result = DB::transaction(function () use ($result, $data, $request, &$oldValues, &$fromStatus) {
            $result = LaboratoryResult::query()->whereKey($result->id)->lockForUpdate()->firstOrFail();

            $amendableFrom = [
                LaboratoryResult::STATUS_PENDING_VERIFICATION,
                LaboratoryResult::STATUS_VERIFIED,
                LaboratoryResult::STATUS_AUTHORIZED,
                LaboratoryResult::STATUS_RELEASED,
            ];

            if (! in_array($result->status, $amendableFrom, true)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot amend a result with status \"{$result->status}\".",
                ]);
            }

            $fromStatus = $result->status;
            $oldValues = $result->only(['value_numeric', 'value_text', 'value_code', 'abnormal_flag', 'result_comment']);

            $values = $this->validateValue($result, $data, requirePresence: true);

            $attributes = [
                ...$values,
                'abnormal_flag' => $this->validateFlag($data),
                'result_comment' => $this->validateComment($data),
                'amended_by' => $request->user()->id,
                'amended_at' => now(),
            ];

            $requiresReverification = in_array($fromStatus, [
                LaboratoryResult::STATUS_VERIFIED,
                LaboratoryResult::STATUS_AUTHORIZED,
                LaboratoryResult::STATUS_RELEASED,
            ], true);

            if ($requiresReverification) {
                $attributes['status'] = LaboratoryResult::STATUS_PENDING_VERIFICATION;
                $attributes['verified_by'] = null;
                $attributes['verified_at'] = null;
                $attributes['verification_comment'] = null;
                $attributes['authorized_by'] = null;
                $attributes['authorized_at'] = null;
                $attributes['released_by'] = null;
                $attributes['released_at'] = null;
                $attributes['release_comment'] = null;
            }

            $result->update($attributes);

            return $result;
        });

        $requiresReverification = in_array($fromStatus, [
            LaboratoryResult::STATUS_VERIFIED,
            LaboratoryResult::STATUS_AUTHORIZED,
            LaboratoryResult::STATUS_RELEASED,
        ], true);

        if ($requiresReverification) {
            $this->auditLog->record('laboratory_result_amendment_requested', $result, null, ['from_status' => $fromStatus], $request);
        }

        $this->auditLog->record(
            'laboratory_result_amended',
            $result,
            null,
            ['reason' => $data['amendment_reason'], 'amended_by' => $result->amended_by, 'amended_at' => $result->amended_at],
            $request,
        );

        $this->auditLog->record(
            'laboratory_result_value_changed',
            $result,
            $oldValues,
            $result->only(['value_numeric', 'value_text', 'value_code', 'abnormal_flag', 'result_comment']),
            $request,
        );

        if ($requiresReverification) {
            $this->auditLog->record('laboratory_result_reverification_required', $result, ['status' => $fromStatus], ['status' => LaboratoryResult::STATUS_PENDING_VERIFICATION], $request);

            $this->auditLog->record(
                'laboratory_result_status_changed',
                $result,
                ['status' => $fromStatus],
                ['status' => LaboratoryResult::STATUS_PENDING_VERIFICATION],
                $request,
            );
        }

        if ($fromStatus === LaboratoryResult::STATUS_RELEASED) {
            $this->auditLog->record(
                'laboratory_result_amended_after_release',
                $result,
                $oldValues,
                $result->only(['value_numeric', 'value_text', 'value_code', 'abnormal_flag', 'result_comment']),
                $request,
            );
        }

        return $result;
    }

    /**
     * Validate and normalize the raw value for this result's snapshotted
     * result_type (spec §12-16) — never trust a browser-supplied value
     * blindly, and never force a numeric test's value through a text column
     * or vice versa. Returns the exactly-one-populated value column to set.
     *
     * @param  array<string, mixed>  $data
     * @return array{value_numeric: string|null, value_text: string|null, value_code: string|null}
     *
     * @throws ValidationException
     */
    protected function validateValue(LaboratoryResult $result, array $data, bool $requirePresence): array
    {
        $attributes = ['value_numeric' => null, 'value_text' => null, 'value_code' => null];

        switch ($result->result_type) {
            case 'numeric':
                $raw = $data['value_numeric'] ?? null;

                if ($raw === null || $raw === '') {
                    if ($requirePresence) {
                        throw ValidationException::withMessages(['value_numeric' => 'A numeric result is required.']);
                    }

                    break;
                }

                if (! is_numeric($raw)) {
                    throw ValidationException::withMessages(['value_numeric' => 'The result must be a valid number.']);
                }

                $attributes['value_numeric'] = (string) $raw;
                break;

            case 'text':
                $raw = $data['value_text'] ?? null;

                if ($raw === null || trim((string) $raw) === '') {
                    if ($requirePresence) {
                        throw ValidationException::withMessages(['value_text' => 'A text result is required.']);
                    }

                    break;
                }

                if (mb_strlen($raw) > 4000) {
                    throw ValidationException::withMessages(['value_text' => 'The result text may not exceed 4000 characters.']);
                }

                $attributes['value_text'] = $raw;
                break;

            case 'boolean':
            case 'positive_negative':
            case 'qualitative':
            case 'enum':
                $raw = $data['value_code'] ?? null;

                if ($raw === null || $raw === '') {
                    if ($requirePresence) {
                        throw ValidationException::withMessages(['value_code' => 'A result selection is required.']);
                    }

                    break;
                }

                $options = $this->optionsFor($result);

                if ($options !== [] && ! in_array($raw, $options, true)) {
                    throw ValidationException::withMessages([
                        'value_code' => 'The selected value is not a configured option for this test.',
                    ]);
                }

                $attributes['value_code'] = $raw;
                break;

            default:
                // No result_type configured on the catalog for this test —
                // fall back to free text rather than blocking entry outright,
                // but never invent a numeric/coded interpretation it hasn't
                // been configured for.
                $raw = $data['value_text'] ?? null;
                $attributes['value_text'] = $raw !== null && trim((string) $raw) !== '' ? $raw : null;

                if ($requirePresence && $attributes['value_text'] === null) {
                    throw ValidationException::withMessages(['value_text' => 'A result value is required.']);
                }
        }

        return $attributes;
    }

    /**
     * The valid value_code choices for this result's type — the test's own
     * configured result_options where set (spec §14-15's "let the test
     * configuration define valid choices"), otherwise the only sensible
     * fixed default for the two inherently two-valued types. qualitative/
     * enum with no configured options accept any bounded string rather than
     * blocking entry while the catalog is still being configured.
     *
     * @return list<string>
     */
    protected function optionsFor(LaboratoryResult $result): array
    {
        if (! empty($result->result_options)) {
            return $result->result_options;
        }

        return match ($result->result_type) {
            'boolean' => ['true', 'false'],
            'positive_negative' => ['positive', 'negative'],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    protected function validateFlag(array $data): ?string
    {
        $flag = $data['abnormal_flag'] ?? null;

        if ($flag === null || $flag === '') {
            return null;
        }

        if (! in_array($flag, LaboratoryResult::ABNORMAL_FLAGS, true)) {
            throw ValidationException::withMessages(['abnormal_flag' => 'The selected flag is not recognized.']);
        }

        return $flag;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    protected function validateComment(array $data): ?string
    {
        $comment = $data['result_comment'] ?? null;

        if ($comment === null || trim((string) $comment) === '') {
            return null;
        }

        if (mb_strlen($comment) > 1000) {
            throw ValidationException::withMessages(['result_comment' => 'The comment may not exceed 1000 characters.']);
        }

        return $comment;
    }
}
