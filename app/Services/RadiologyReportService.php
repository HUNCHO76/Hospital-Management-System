<?php

namespace App\Services;

use App\Models\RadiologyAppointment;
use App\Models\RadiologyReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Radiology findings & results (Phase 5, Step 42): DRAFT -> SUBMITTED for
 * one COMPLETED RadiologyAppointment. Mirrors the transaction + row-lock +
 * audited-transition-helper shape every other Radiology service already
 * uses (RadiologySchedulingService/RadiologyReceptionService/
 * RadiologyExaminationService).
 *
 * Patient/order/procedure consistency is enforced by construction, the same
 * way every other Radiology write path already is: a report only ever
 * resolves to one RadiologyAppointment, which only ever resolves to one
 * RadiologyOrderItem/RadiologyOrder/ClinicalEncounter/patient — there is no
 * input path here that accepts a patient_id, encounter_id, order_id, or
 * facility_id from the caller. The reporting practitioner is always
 * resolved from the authenticated user's own practitioner profile, never
 * trusted from the client (Part 14).
 */
class RadiologyReportService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Create the (single) draft report for a completed examination. Locks
     * the appointment row so two reporting practitioners racing to start a
     * report on the same examination can never both succeed — the second
     * sees the first's row already inserted and is rejected (Part 30).
     *
     * @param  array{technique?: string|null, findings?: string|null, impression?: string|null, recommendations?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function createDraft(RadiologyAppointment $appointment, array $data, Request $request): RadiologyReport
    {
        $practitionerId = $request->user()->staff?->practitioner?->id;

        if ($practitionerId === null) {
            throw ValidationException::withMessages([
                'reporting_practitioner_id' => 'Your user account has no active practitioner profile, so you cannot author a radiology report.',
            ]);
        }

        $report = DB::transaction(function () use ($appointment, $data, $request, $practitionerId) {
            $appointment = RadiologyAppointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            $this->assertEligibleForReporting($appointment);

            if (RadiologyReport::query()->where('radiology_appointment_id', $appointment->id)->exists()) {
                throw ValidationException::withMessages([
                    'radiology_appointment_id' => 'A report already exists for this examination.',
                ]);
            }

            return RadiologyReport::create([
                'radiology_appointment_id' => $appointment->id,
                'reporting_practitioner_id' => $practitionerId,
                'status' => RadiologyReport::STATUS_DRAFT,
                'technique' => $this->validateText($data['technique'] ?? null, 'technique', 2000),
                'findings' => $this->validateText($data['findings'] ?? null, 'findings', 8000),
                'impression' => $this->validateText($data['impression'] ?? null, 'impression', 4000),
                'recommendations' => $this->validateText($data['recommendations'] ?? null, 'recommendations', 2000),
                'updated_by' => $request->user()->id,
            ]);
        });

        $this->auditLog->record(
            'radiology_report_created',
            $report,
            null,
            $report->only(['radiology_appointment_id', 'reporting_practitioner_id', 'status']),
            $request,
        );

        return $report;
    }

    /**
     * Save (or continue editing) a draft report. Reachable while DRAFT, or
     * while RETURNED (Step 43 Part 12 — correcting a returned report
     * re-enters ordinary draft editing rather than a separate resubmission
     * mechanism, mirroring LaboratoryResultService::saveDraft()'s identical
     * shape). A correction made to a RETURNED report is normalized back to
     * DRAFT here; the original return metadata (returned_by/at/reason/
     * comments) is deliberately never cleared — it remains visible as
     * history even after the report moves on (Part 12).
     *
     * @param  array{technique?: string|null, findings?: string|null, impression?: string|null, recommendations?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function updateDraft(RadiologyReport $report, array $data, Request $request): RadiologyReport
    {
        $fromStatus = null;

        $report = DB::transaction(function () use ($report, $data, $request, &$fromStatus) {
            $report = RadiologyReport::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();

            $editableFrom = [RadiologyReport::STATUS_DRAFT, RadiologyReport::STATUS_RETURNED];

            if (! in_array($report->status, $editableFrom, true)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot edit a report with status \"{$report->status}\".",
                ]);
            }

            $fromStatus = $report->status;

            $report->update([
                'technique' => $this->validateText($data['technique'] ?? null, 'technique', 2000),
                'findings' => $this->validateText($data['findings'] ?? null, 'findings', 8000),
                'impression' => $this->validateText($data['impression'] ?? null, 'impression', 4000),
                'recommendations' => $this->validateText($data['recommendations'] ?? null, 'recommendations', 2000),
                'status' => RadiologyReport::STATUS_DRAFT,
                'updated_by' => $request->user()->id,
            ]);

            return $report;
        });

        $this->auditLog->record(
            'radiology_report_updated',
            $report,
            null,
            $report->only(['technique', 'findings', 'impression', 'recommendations', 'updated_by']),
            $request,
        );

        if ($fromStatus === RadiologyReport::STATUS_RETURNED) {
            $this->auditLog->record(
                'radiology_report_status_changed',
                $report,
                ['status' => RadiologyReport::STATUS_RETURNED],
                ['status' => RadiologyReport::STATUS_DRAFT],
                $request,
            );
        }

        return $report;
    }

    /**
     * Submit a draft report for verification (Step 43). Requires findings
     * and impression to both be present (Part 13/27 — this step's own
     * baked-in "hospital policy" default) and re-verifies the underlying
     * examination is still COMPLETED, defense-in-depth mirroring
     * RadiologyExaminationService's own re-verification pattern.
     *
     * @throws ValidationException
     */
    public function submit(RadiologyReport $report, Request $request): RadiologyReport
    {
        $report = DB::transaction(function () use ($report, $request) {
            $report = RadiologyReport::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();

            if (! $report->canTransitionTo(RadiologyReport::STATUS_SUBMITTED)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot submit a report with status \"{$report->status}\".",
                ]);
            }

            $errors = [];

            if (trim((string) $report->findings) === '') {
                $errors['findings'] = 'Findings are required before this report can be submitted.';
            }

            if (trim((string) $report->impression) === '') {
                $errors['impression'] = 'An impression is required before this report can be submitted.';
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $appointment = $report->radiologyAppointment()->lockForUpdate()->first();
            $this->assertEligibleForReporting($appointment);

            $report->update([
                'status' => RadiologyReport::STATUS_SUBMITTED,
                'submitted_by' => $request->user()->id,
                'submitted_at' => now(),
            ]);

            return $report;
        });

        $this->auditLog->record(
            'radiology_report_submitted',
            $report,
            ['status' => RadiologyReport::STATUS_DRAFT],
            $report->only(['status', 'submitted_by', 'submitted_at']),
            $request,
        );

        // A report that has previously been returned is a resubmission
        // (Step 43, Part 28) — a distinct, additionally-audited event on top
        // of the ordinary submission event above, never a duplicate report.
        if ($report->returned_at !== null) {
            $this->auditLog->record(
                'radiology_report_resubmitted',
                $report,
                null,
                $report->only(['status', 'submitted_by', 'submitted_at']),
                $request,
            );
        }

        return $report;
    }

    /**
     * Record that a verifier has opened a submitted report for review
     * (Phase 5, Step 43, Part 9/22) — audit-only, no status transition. Only
     * fired for a report that is actually pending a decision, so re-opening
     * an already-verified/returned report for reference does not spam the
     * audit log.
     */
    public function recordReviewStarted(RadiologyReport $report, Request $request): void
    {
        if ($report->status !== RadiologyReport::STATUS_SUBMITTED) {
            return;
        }

        $this->auditLog->record(
            'radiology_report_review_started',
            $report,
            null,
            ['reviewed_by' => $request->user()->id],
            $request,
        );
    }

    /**
     * Verify (approve) a submitted report (Part 10). Self-verification
     * (reporting_practitioner_id === verifying_practitioner_id) is already
     * blocked by RadiologyReportPolicy::verify() before this is ever
     * reached, but is re-checked here as well — the backend must enforce
     * this regardless of which path a request took (Part 5).
     *
     * @param  array{verification_notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function verify(RadiologyReport $report, array $data, Request $request): RadiologyReport
    {
        $verifyingPractitionerId = $request->user()->staff?->practitioner?->id;

        if ($verifyingPractitionerId === null) {
            throw ValidationException::withMessages([
                'verifying_practitioner_id' => 'Your user account has no active practitioner profile, so you cannot verify a radiology report.',
            ]);
        }

        $report = DB::transaction(function () use ($report, $data, $request, $verifyingPractitionerId) {
            $report = RadiologyReport::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();

            if (! $report->canTransitionTo(RadiologyReport::STATUS_VERIFIED)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot verify a report with status \"{$report->status}\".",
                ]);
            }

            if ($verifyingPractitionerId === $report->reporting_practitioner_id && ! config('radiology.allow_self_verification')) {
                throw ValidationException::withMessages([
                    'self_verification' => 'Independent verification is required. You cannot verify a report you authored yourself.',
                ]);
            }

            $appointment = $report->radiologyAppointment()->lockForUpdate()->first();
            $this->assertEligibleForReporting($appointment);

            $verificationNotes = $this->validateText($data['verification_notes'] ?? null, 'verification_notes', 1000);

            $report->update([
                'status' => RadiologyReport::STATUS_VERIFIED,
                'verifying_practitioner_id' => $verifyingPractitionerId,
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
                'verification_notes' => $verificationNotes,
            ]);

            return $report;
        });

        $this->auditLog->record(
            'radiology_report_verified',
            $report,
            ['status' => RadiologyReport::STATUS_SUBMITTED],
            $report->only(['status', 'verifying_practitioner_id', 'verified_by', 'verified_at']),
            $request,
        );

        if (filled($report->verification_notes)) {
            $this->auditLog->record(
                'radiology_verification_note_added',
                $report,
                null,
                ['verification_notes' => $report->verification_notes],
                $request,
            );
        }

        return $report;
    }

    /**
     * Return a submitted report to the reporting practitioner for
     * correction (Part 11-12). The report's own content is never modified
     * here — only the return metadata is recorded, and the reporting
     * practitioner must correct it themselves through updateDraft().
     *
     * @param  array{return_reason: string, return_comments?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function returnForCorrection(RadiologyReport $report, array $data, Request $request): RadiologyReport
    {
        $returningPractitionerId = $request->user()->staff?->practitioner?->id;

        $report = DB::transaction(function () use ($report, $data, $request, $returningPractitionerId) {
            $report = RadiologyReport::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();

            if (! $report->canTransitionTo(RadiologyReport::STATUS_RETURNED)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot return a report with status \"{$report->status}\".",
                ]);
            }

            if ($returningPractitionerId !== null
                && $returningPractitionerId === $report->reporting_practitioner_id
                && ! config('radiology.allow_self_verification')) {
                throw ValidationException::withMessages([
                    'self_verification' => 'Independent verification is required. You cannot return a report you authored yourself.',
                ]);
            }

            $report->update([
                'status' => RadiologyReport::STATUS_RETURNED,
                'returned_by' => $request->user()->id,
                'returned_at' => now(),
                'return_reason' => $data['return_reason'],
                'return_comments' => $this->validateText($data['return_comments'] ?? null, 'return_comments', 1000),
            ]);

            return $report;
        });

        $this->auditLog->record(
            'radiology_report_returned',
            $report,
            ['status' => RadiologyReport::STATUS_SUBMITTED],
            $report->only(['status', 'returned_by', 'returned_at', 'return_reason', 'return_comments']),
            $request,
        );

        return $report;
    }

    /**
     * Release a verified report as the official clinical result (Phase 5,
     * Step 44, Part 3/11). The minimum valid transition — VERIFIED ->
     * RELEASED — since Radiology's own architecture has no AUTHORIZED
     * intermediate status to reuse (Part 3). Report content is never
     * touched here (Part 34/38 — release changes workflow state, not
     * clinical interpretation).
     *
     * @param  array{release_notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function release(RadiologyReport $report, array $data, Request $request): RadiologyReport
    {
        $report = DB::transaction(function () use ($report, $data, $request) {
            $report = RadiologyReport::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();

            if (! $report->canTransitionTo(RadiologyReport::STATUS_RELEASED)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot release a report with status \"{$report->status}\". Only a verified report may be released.",
                ]);
            }

            // Defense in depth, mirroring submit()/verify()'s own
            // re-verification pattern — a report should never remain
            // releasable if its underlying examination was somehow reopened
            // or reset after verification.
            $appointment = $report->radiologyAppointment()->lockForUpdate()->first();
            $this->assertEligibleForReporting($appointment);

            $report->update([
                'status' => RadiologyReport::STATUS_RELEASED,
                'released_by' => $request->user()->id,
                'released_at' => now(),
                'release_notes' => $this->validateText($data['release_notes'] ?? null, 'release_notes', 1000),
            ]);

            return $report;
        });

        $this->auditLog->record(
            'radiology_result_released',
            $report,
            ['status' => RadiologyReport::STATUS_VERIFIED],
            $report->only(['status', 'released_by', 'released_at']),
            $request,
        );

        return $report;
    }

    /**
     * @throws ValidationException
     */
    protected function assertEligibleForReporting(RadiologyAppointment $appointment): void
    {
        if ($appointment->status !== RadiologyAppointment::STATUS_COMPLETED) {
            throw ValidationException::withMessages([
                'status' => "Cannot report on an examination with status \"{$appointment->status}\". Only completed examinations are eligible for reporting.",
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
