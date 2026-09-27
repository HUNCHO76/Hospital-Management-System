<?php

namespace App\Services;

use App\Models\ClinicalEncounter;
use App\Models\TriageAssessment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TriageService
{
    public function __construct(
        protected AuditLogService $auditLog,
        protected FinancialAuthorizationService $financialAuth,
    ) {}

    /**
     * Start a new triage assessment for an encounter — the first step of
     * the triage workflow (spec §15). Safely serialized against a
     * concurrent duplicate for the same encounter.
     *
     * @throws ValidationException
     */
    public function create(ClinicalEncounter $encounter, Request $request): TriageAssessment
    {
        $triage = DB::transaction(function () use ($encounter, $request) {
            // Locking the encounter row is what makes this concurrency-safe:
            // two simultaneous attempts to start triage for the same
            // encounter serialize on this lock, so the duplicate check below
            // and the insert that follows it are never interleaved with
            // another request's.
            $encounter = ClinicalEncounter::query()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();

            $this->assertEncounterEligible($encounter);
            $this->assertNoExistingTriage($encounter);

            // Financial gate: the consultation charge (if any) must be paid
            // before triage begins. No-charge rule applies: when no charge was
            // captured (practitioner has no consultation service), the gate
            // passes automatically — free consultations never block triage.
            $paymentStatus = $this->financialAuth->statusForSource(
                ClinicalEncounter::class,
                $encounter->id,
                FinancialAuthorizationService::STATUS_AUTHORIZED,
            );

            if ($paymentStatus !== FinancialAuthorizationService::STATUS_AUTHORIZED) {
                throw ValidationException::withMessages([
                    'financial_authorization' => 'Payment is required before triage can begin.',
                ]);
            }

            return TriageAssessment::create([
                'clinical_encounter_id' => $encounter->id,
                'assessed_by' => $request->user()->id,
                'assessment_time' => now(),
                'status' => TriageAssessment::STATUS_PENDING,
            ]);
        });

        $this->auditLog->record('triage_created', $triage, null, $triage->only(['clinical_encounter_id', 'assessed_by', 'status']), $request);

        return $triage;
    }

    /**
     * Update the assessment's clinical fields (category, chief complaint,
     * pain score, notes). Only allowed while the assessment is still open —
     * a completed/cancelled triage is immutable (spec §19).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(TriageAssessment $triage, array $data, Request $request): TriageAssessment
    {
        $this->assertMutable($triage);

        $oldValues = $triage->only(array_keys($data));

        $triage->update($data);

        $this->auditLog->record('triage_updated', $triage, $oldValues, $triage->only(array_keys($data)), $request);

        return $triage;
    }

    /**
     * Move the assessment from pending to in_progress.
     *
     * @throws ValidationException
     */
    public function start(TriageAssessment $triage, Request $request): TriageAssessment
    {
        return $this->transition($triage, TriageAssessment::STATUS_IN_PROGRESS, 'triage_started', $request);
    }

    /**
     * Complete the assessment. Requires a triage category to have been
     * assigned and at least one vital-sign observation to have been
     * recorded (spec §35) — the patient cannot be marked ready for
     * consultation on an empty assessment.
     *
     * @throws ValidationException
     */
    public function complete(TriageAssessment $triage, Request $request): TriageAssessment
    {
        $this->assertTransitionAllowed($triage, TriageAssessment::STATUS_COMPLETED);

        if (! $triage->triage_category) {
            throw ValidationException::withMessages([
                'triage_category' => 'A triage category must be assigned before completing the assessment.',
            ]);
        }

        if (! $triage->vitalSigns()->exists()) {
            throw ValidationException::withMessages([
                'vital_signs' => 'At least one vital-sign observation must be recorded before completing the assessment.',
            ]);
        }

        $oldValues = $triage->only(['status', 'completed_at', 'completed_by']);

        $triage->update([
            'status' => TriageAssessment::STATUS_COMPLETED,
            'completed_at' => now(),
            'completed_by' => $request->user()->id,
        ]);

        $this->auditLog->record('triage_completed', $triage, $oldValues, $triage->only(['status', 'completed_at', 'completed_by']), $request);

        return $triage;
    }

    /**
     * Cancel the assessment, recording who/when/why.
     *
     * @throws ValidationException
     */
    public function cancel(TriageAssessment $triage, string $reason, Request $request): TriageAssessment
    {
        $this->assertTransitionAllowed($triage, TriageAssessment::STATUS_CANCELLED);

        $oldValues = $triage->only(['status', 'cancelled_at', 'cancellation_reason']);

        $triage->update([
            'status' => TriageAssessment::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        $this->auditLog->record('triage_cancelled', $triage, $oldValues, $triage->only(['status', 'cancelled_at', 'cancellation_reason']), $request);

        return $triage;
    }

    /**
     * @throws ValidationException
     */
    protected function assertEncounterEligible(ClinicalEncounter $encounter): void
    {
        if (! in_array($encounter->status, ClinicalEncounter::ACTIVE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'clinical_encounter_id' => "Cannot start triage on an encounter with status \"{$encounter->status}\".",
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function assertNoExistingTriage(ClinicalEncounter $encounter): void
    {
        if (TriageAssessment::query()->where('clinical_encounter_id', $encounter->id)->exists()) {
            throw ValidationException::withMessages([
                'clinical_encounter_id' => 'This encounter already has a triage assessment.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function assertMutable(TriageAssessment $triage): void
    {
        if (in_array($triage->status, [TriageAssessment::STATUS_COMPLETED, TriageAssessment::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages([
                'status' => "Cannot modify a triage assessment with status \"{$triage->status}\".",
            ]);
        }
    }

    /**
     * Apply a validated status transition and audit it.
     *
     * @throws ValidationException
     */
    protected function transition(TriageAssessment $triage, string $newStatus, string $auditAction, Request $request): TriageAssessment
    {
        $oldStatus = $triage->status;

        $this->assertTransitionAllowed($triage, $newStatus);

        $triage->update(['status' => $newStatus]);

        $this->auditLog->record($auditAction, $triage, ['status' => $oldStatus], ['status' => $newStatus], $request);

        return $triage;
    }

    /**
     * @throws ValidationException
     */
    protected function assertTransitionAllowed(TriageAssessment $triage, string $newStatus): void
    {
        if (! $triage->canTransitionTo($newStatus)) {
            throw ValidationException::withMessages([
                'status' => "Cannot change triage status from \"{$triage->status}\" to \"{$newStatus}\".",
            ]);
        }
    }
}
