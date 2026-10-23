<?php

namespace App\Services;

use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderItem;
use App\Models\LaboratoryResult;
use App\Models\LaboratoryResultValue;
use App\Models\LaboratorySample;
use App\Models\LaboratoryTestParameter;
use App\Services\NHIF\NHIFClinicalUtilizationService;
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
    public function __construct(
        protected AuditLogService $auditLog,
        protected NHIFClinicalUtilizationService $nhifUtilization,
        protected LaboratoryReferenceRangeService $referenceRangeService,
        protected LaboratoryFlagCalculator $flagCalculator,
    ) {}

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

        $result = LaboratoryResult::firstOrCreate(
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

        // When the test has parameters configured, pre-create empty value
        // rows for each active parameter so the result entry UI can render
        // them immediately without requiring the technician to add them one
        // by one.
        if ($result->wasRecentlyCreated && $test !== null) {
            $parameters = $test->parameters()->where('active', true)->orderBy('display_order')->get();

            foreach ($parameters as $parameter) {
                LaboratoryResultValue::firstOrCreate([
                    'laboratory_result_id' => $result->id,
                    'laboratory_test_parameter_id' => $parameter->id,
                ], ['unit' => $parameter->unit]);
            }
        }
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

            $parameterValues = $data['parameter_values'] ?? null;

            if (is_array($parameterValues) && count($parameterValues) > 0) {
                // Multi-parameter path: sync per-parameter values and derive
                // aggregate flag from them.
                $aggregateFlag = $this->syncParameterValues($result, $parameterValues, $request);

                $result->update([
                    'abnormal_flag' => $aggregateFlag,
                    'result_comment' => $this->validateComment($data),
                    'status' => LaboratoryResult::STATUS_DRAFT,
                    'entered_by' => $result->entered_by ?? $request->user()->id,
                    'entered_at' => $result->entered_at ?? now(),
                ]);
            } else {
                // Legacy single-value path — unchanged behavior.
                $values = $this->validateValue($result, $data, requirePresence: false);

                $result->update([
                    ...$values,
                    'abnormal_flag' => $this->validateFlag($data),
                    'result_comment' => $this->validateComment($data),
                    'status' => LaboratoryResult::STATUS_DRAFT,
                    'entered_by' => $result->entered_by ?? $request->user()->id,
                    'entered_at' => $result->entered_at ?? now(),
                ]);
            }

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

            // Re-validate the already-saved value(s) against the "must be
            // present" rule — a draft may have been saved blank.
            if ($result->resultValues()->exists()) {
                $this->validateParameterValuesPresent($result);
            } else {
                $this->validateValue($result, $result->only(['value_numeric', 'value_text', 'value_code']), requirePresence: true);
            }

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

            if ($result->resultValues()->exists()) {
                $this->validateParameterValuesPresent($result);
            } else {
                $this->validateValue($result, $result->only(['value_numeric', 'value_text', 'value_code']), requirePresence: true);
            }

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

        $this->recomputeOrderCompletion($result->item->laboratoryOrder, $request);

        // Phase 8, Step 71: RELEASED is this module's completion event. Report
        // the performed service to NHIF (post-commit; never throws, never
        // blocks or reverses the release — a NHIF problem only affects the
        // utilization record's own status).
        $this->nhifUtilization->captureLaboratoryResult($result, $request);

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

            $this->revertOrderCompletionIfNeeded($result->item->laboratoryOrder, $request);
        }

        return $result;
    }

    /**
     * Sync per-parameter result values for a multi-component test, snapshot the
     * matching reference range for each parameter, calculate per-parameter flags,
     * and return the aggregate worst flag across all parameters.
     *
     * @param  list<array{parameter_id: int, value_numeric?: string|null, value_text?: string|null, value_code?: string|null, result_comment?: string|null}>  $parameterValues
     *
     * @throws ValidationException
     */
    public function syncParameterValues(LaboratoryResult $result, array $parameterValues, ?Request $request = null): ?string
    {
        $patient = $result->item?->laboratoryOrder?->clinicalEncounter?->patient;
        $sample = $result->sample;
        $item = $result->item;
        $specimenSource = $sample?->specimen_source ?? $item?->specimen_source;

        if (! $specimenSource) {
            $contextText = strtolower(($sample?->collection_site ?? '').' '.($sample?->collection_notes ?? '').' '.($item?->clinical_note ?? ''));
            if (str_contains($contextText, 'venous') || str_contains($contextText, 'vbg')) {
                $specimenSource = 'venous';
            } elseif (str_contains($contextText, 'arterial') || str_contains($contextText, 'abg')) {
                $specimenSource = 'arterial';
            }
        }

        foreach ($parameterValues as $index => $entry) {
            $parameterId = $entry['parameter_id'] ?? null;

            if (! $parameterId) {
                continue;
            }

            $parameter = LaboratoryTestParameter::find($parameterId);

            if (! $parameter || $parameter->laboratory_test_id !== $result->laboratory_test_id) {
                throw ValidationException::withMessages([
                    "parameter_values.{$index}.parameter_id" => 'The parameter does not belong to this test.',
                ]);
            }

            $valueNumeric = null;
            $valueText = null;
            $valueCode = null;
            $flag = null;

            switch ($parameter->data_type) {
                case LaboratoryTestParameter::DATA_TYPE_NUMERIC:
                    $raw = $entry['value_numeric'] ?? null;

                    if ($raw !== null && $raw !== '') {
                        if (! is_numeric($raw)) {
                            throw ValidationException::withMessages([
                                "parameter_values.{$index}.value_numeric" => "The value for \"{$parameter->name}\" must be a valid number.",
                            ]);
                        }

                        $valueNumeric = (string) $raw;
                    }

                    break;

                case LaboratoryTestParameter::DATA_TYPE_TEXT:
                    $raw = $entry['value_text'] ?? null;
                    $valueText = ($raw !== null && trim((string) $raw) !== '') ? $raw : null;
                    break;

                case LaboratoryTestParameter::DATA_TYPE_OPTION:
                    $raw = $entry['value_code'] ?? null;
                    $valueCode = ($raw !== null && $raw !== '') ? $raw : null;

                    if ($valueCode !== null) {
                        $options = $parameter->options_json ?? [];

                        if ($options !== [] && ! in_array($valueCode, $options, true)) {
                            throw ValidationException::withMessages([
                                "parameter_values.{$index}.value_code" => "The value for \"{$parameter->name}\" is not a valid option.",
                            ]);
                        }
                    }

                    break;
            }

            // Select the best matching reference range for this parameter + patient
            // + specimen source combination, then snapshot it for historical immutability.
            $range = $patient ? $this->referenceRangeService->selectForPatient($parameter, $patient, $specimenSource) : null;

            $flag = $this->flagCalculator->flag($parameter, $valueNumeric, $valueCode, $range);

            LaboratoryResultValue::updateOrCreate(
                [
                    'laboratory_result_id' => $result->id,
                    'laboratory_test_parameter_id' => $parameter->id,
                ],
                [
                    'value_numeric' => $valueNumeric,
                    'value_text' => $valueText,
                    'value_code' => $valueCode,
                    'unit' => $parameter->unit,
                    'reference_low' => $range?->low,
                    'reference_high' => $range?->high,
                    'reference_critical_low' => $range?->critical_low,
                    'reference_critical_high' => $range?->critical_high,
                    'abnormal_flag' => $flag,
                    'result_comment' => $entry['result_comment'] ?? null,
                ],
            );
        }

        return $this->computeAggregateFlag($result);
    }

    /**
     * Return the most severe abnormal flag across all parameter values for
     * this result. Priority: critical > high > low > abnormal > normal.
     */
    protected function computeAggregateFlag(LaboratoryResult $result): ?string
    {
        $priority = [
            LaboratoryFlagCalculator::FLAG_CRITICAL_HIGH => 5,
            LaboratoryFlagCalculator::FLAG_CRITICAL_LOW => 5,
            LaboratoryFlagCalculator::FLAG_CRITICAL => 4,
            LaboratoryFlagCalculator::FLAG_HIGH => 3,
            LaboratoryFlagCalculator::FLAG_LOW => 2,
            LaboratoryFlagCalculator::FLAG_ABNORMAL => 1,
            LaboratoryFlagCalculator::FLAG_NORMAL => 0,
            LaboratoryFlagCalculator::FLAG_UNCONFIGURED => -1,
        ];

        $flags = LaboratoryResultValue::where('laboratory_result_id', $result->id)
            ->whereNotNull('abnormal_flag')
            ->pluck('abnormal_flag');

        if ($flags->isEmpty()) {
            return null;
        }

        return $flags->sortByDesc(fn ($f) => $priority[$f] ?? -1)->first();
    }

    /**
     * Validate that every parameter value for a multi-component result has
     * a value entered (called from submit() and verifyResult()).
     *
     * @throws ValidationException
     */
    protected function validateParameterValuesPresent(LaboratoryResult $result): void
    {
        $missing = LaboratoryResultValue::where('laboratory_result_id', $result->id)
            ->whereNull('value_numeric')
            ->where(fn ($q) => $q->whereNull('value_text')->orWhere('value_text', ''))
            ->where(fn ($q) => $q->whereNull('value_code')->orWhere('value_code', ''))
            ->with('parameter:id,name')
            ->get();

        if ($missing->isEmpty()) {
            return;
        }

        $names = $missing->map(fn ($rv) => $rv->parameter?->name ?? 'Unknown')->implode(', ');

        throw ValidationException::withMessages([
            'parameter_values' => "The following parameters have no result entered: {$names}.",
        ]);
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

    /**
     * Roll the parent order up to COMPLETED once every one of its
     * non-cancelled items has a released result — the only order-level
     * signal that a whole order's clinical work is genuinely finished,
     * distinct from RESULT_PENDING (which only means processing finished,
     * not that results have since been reviewed and released).
     * LaboratoryOrderItem::status has no transition beyond RESULT_PENDING
     * (Steps 33-35 track that progress entirely on LaboratoryResult
     * instead), so this reads each item's linked result directly rather
     * than the item's own status. Mirrors the exact bypass-canTransitionTo()
     * pattern LaboratorySampleService/LaboratoryProcessingService's own
     * order-rollup helpers use — this is derived state, never a
     * user-facing transition. Symmetrically reverted by
     * revertOrderCompletionIfNeeded() below when a released result is
     * later amended.
     */
    protected function recomputeOrderCompletion(LaboratoryOrder $order, Request $request): void
    {
        if (in_array($order->status, [LaboratoryOrder::STATUS_COMPLETED, LaboratoryOrder::STATUS_CANCELLED], true)) {
            return;
        }

        $items = $order->items()->where('status', '!=', LaboratoryOrderItem::STATUS_CANCELLED)->with('result')->get();

        if ($items->isEmpty() || $items->contains(fn (LaboratoryOrderItem $item) => $item->result?->status !== LaboratoryResult::STATUS_RELEASED)) {
            return;
        }

        $oldStatus = $order->status;
        $order->update(['status' => LaboratoryOrder::STATUS_COMPLETED]);

        $this->auditLog->record(
            'laboratory_order_status_changed',
            $order,
            ['status' => $oldStatus],
            ['status' => LaboratoryOrder::STATUS_COMPLETED],
            $request,
        );
    }

    /**
     * Revert a COMPLETED order back to its item-progress rollup once one of
     * its released results is amended — a completed order can no longer
     * claim every result is final once one of them has been reopened.
     */
    protected function revertOrderCompletionIfNeeded(LaboratoryOrder $order, Request $request): void
    {
        if ($order->status !== LaboratoryOrder::STATUS_COMPLETED) {
            return;
        }

        $itemStatuses = $order->items()->where('status', '!=', LaboratoryOrderItem::STATUS_CANCELLED)->pluck('status');
        $newStatus = LaboratoryOrder::statusFromItemProgress($itemStatuses);

        $order->update(['status' => $newStatus]);

        $this->auditLog->record(
            'laboratory_order_status_changed',
            $order,
            ['status' => LaboratoryOrder::STATUS_COMPLETED],
            ['status' => $newStatus],
            $request,
        );
    }
}
