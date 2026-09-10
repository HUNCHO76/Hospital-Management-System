<?php

namespace App\Services;

use App\Models\ClinicalEncounter;
use App\Models\Consultation;
use App\Models\Practitioner;
use App\Models\TriageAssessment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConsultationService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Start a new consultation for an encounter — requires triage to have
     * been completed first (spec §7). Safely serialized against a
     * concurrent duplicate for the same encounter.
     *
     * @throws ValidationException
     */
    public function create(ClinicalEncounter $encounter, Request $request): Consultation
    {
        $consultation = DB::transaction(function () use ($encounter, $request) {
            // Locking the encounter row is what makes this concurrency-safe:
            // two simultaneous attempts to start a consultation for the same
            // encounter serialize on this lock, so the duplicate check below
            // and the insert that follows it are never interleaved with
            // another request's.
            $encounter = ClinicalEncounter::query()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();

            $this->assertEncounterEligible($encounter);
            $this->assertTriageCompleted($encounter);
            $this->assertNoExistingConsultation($encounter);

            $practitioner = $this->assertPractitionerEligible($request);

            return Consultation::create([
                'clinical_encounter_id' => $encounter->id,
                'practitioner_id' => $practitioner->id,
                'created_by' => $request->user()->id,
                'status' => Consultation::STATUS_PENDING,
            ]);
        });

        $this->auditLog->record(
            'consultation_created',
            $consultation,
            null,
            $consultation->only(['clinical_encounter_id', 'practitioner_id', 'created_by', 'status']),
            $request,
        );

        return $consultation;
    }

    /**
     * Update the consultation's narrative clinical fields. Only allowed
     * while the consultation is still open — a completed/cancelled
     * consultation is immutable outside the correction workflow (spec §24).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(Consultation $consultation, array $data, Request $request): Consultation
    {
        $this->assertMutable($consultation);

        $oldValues = $consultation->only(array_keys($data));

        $consultation->update($data);

        $this->auditLog->record('consultation_updated', $consultation, $oldValues, $consultation->only(array_keys($data)), $request);

        return $consultation;
    }

    /**
     * Move the consultation from pending to in_progress.
     *
     * @throws ValidationException
     */
    public function start(Consultation $consultation, Request $request): Consultation
    {
        $this->assertTransitionAllowed($consultation, Consultation::STATUS_IN_PROGRESS);

        $oldValues = $consultation->only(['status', 'started_at']);

        $consultation->update([
            'status' => Consultation::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);

        $this->auditLog->record('consultation_started', $consultation, $oldValues, $consultation->only(['status', 'started_at']), $request);

        return $consultation;
    }

    /**
     * Complete the consultation. Requires the core clinical documentation
     * fields to have been recorded (spec §17) — the encounter cannot be
     * marked consulted on an empty assessment.
     *
     * @throws ValidationException
     */
    public function complete(Consultation $consultation, Request $request): Consultation
    {
        $this->assertTransitionAllowed($consultation, Consultation::STATUS_COMPLETED);

        $required = [
            'chief_complaint' => 'A chief complaint must be recorded before completing the consultation.',
            'history_presenting_illness' => 'A history of presenting illness must be recorded before completing the consultation.',
            'assessment_summary' => 'A clinical assessment must be recorded before completing the consultation.',
            'plan_summary' => 'A plan summary must be recorded before completing the consultation.',
        ];

        foreach ($required as $field => $message) {
            if (! filled($consultation->{$field})) {
                throw ValidationException::withMessages([$field => $message]);
            }
        }

        $oldValues = $consultation->only(['status', 'completed_at', 'completed_by']);

        $consultation->update([
            'status' => Consultation::STATUS_COMPLETED,
            'completed_at' => now(),
            'completed_by' => $request->user()->id,
        ]);

        $this->auditLog->record(
            'consultation_completed',
            $consultation,
            $oldValues,
            $consultation->only(['status', 'completed_at', 'completed_by']),
            $request,
        );

        return $consultation;
    }

    /**
     * Cancel the consultation, recording who/when/why.
     *
     * @throws ValidationException
     */
    public function cancel(Consultation $consultation, string $reason, Request $request): Consultation
    {
        $this->assertTransitionAllowed($consultation, Consultation::STATUS_CANCELLED);

        $oldValues = $consultation->only(['status', 'cancelled_at', 'cancellation_reason']);

        $consultation->update([
            'status' => Consultation::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        $this->auditLog->record(
            'consultation_cancelled',
            $consultation,
            $oldValues,
            $consultation->only(['status', 'cancelled_at', 'cancellation_reason']),
            $request,
        );

        return $consultation;
    }

    /**
     * Amend a completed consultation's clinical documentation. This is the
     * controlled correction workflow required by spec §24 — a completed
     * consultation is otherwise immutable. The full old/new diff recorded
     * via AuditLogService is the "what changed" record; no separate
     * correction-log table is needed.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function correct(Consultation $consultation, array $data, string $reason, Request $request): Consultation
    {
        if ($consultation->status !== Consultation::STATUS_COMPLETED) {
            throw ValidationException::withMessages([
                'status' => 'Only a completed consultation can be corrected.',
            ]);
        }

        $fieldsChanged = array_intersect_key($data, array_flip(Consultation::NARRATIVE_FIELDS));

        $oldValues = $consultation->only([...array_keys($fieldsChanged), 'corrected_at', 'corrected_by', 'correction_reason']);

        $consultation->update([
            ...$fieldsChanged,
            'corrected_at' => now(),
            'corrected_by' => $request->user()->id,
            'correction_reason' => $reason,
        ]);

        $this->auditLog->record(
            'consultation_corrected',
            $consultation,
            $oldValues,
            $consultation->only([...array_keys($fieldsChanged), 'corrected_at', 'corrected_by', 'correction_reason']),
            $request,
        );

        return $consultation;
    }

    /**
     * @throws ValidationException
     */
    protected function assertEncounterEligible(ClinicalEncounter $encounter): void
    {
        if (! in_array($encounter->status, ClinicalEncounter::ACTIVE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'clinical_encounter_id' => "Cannot start a consultation on an encounter with status \"{$encounter->status}\".",
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function assertTriageCompleted(ClinicalEncounter $encounter): void
    {
        $triage = $encounter->triageAssessment;

        if (! $triage || $triage->status !== TriageAssessment::STATUS_COMPLETED) {
            throw ValidationException::withMessages([
                'clinical_encounter_id' => 'Triage must be completed before a consultation can be started for this encounter.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function assertNoExistingConsultation(ClinicalEncounter $encounter): void
    {
        if (Consultation::query()->where('clinical_encounter_id', $encounter->id)->exists()) {
            throw ValidationException::withMessages([
                'clinical_encounter_id' => 'This encounter already has a consultation.',
            ]);
        }
    }

    /**
     * Resolve the acting user's own practitioner profile and confirm they
     * are an active, clinically eligible practitioner (spec §6 — "doctor or
     * authorized clinician").
     *
     * @throws ValidationException
     */
    protected function assertPractitionerEligible(Request $request): Practitioner
    {
        $practitioner = $request->user()->staff?->practitioner;

        if (! $practitioner) {
            throw ValidationException::withMessages([
                'practitioner_id' => 'Only a user with a practitioner profile can start a consultation.',
            ]);
        }

        if ($practitioner->status !== Practitioner::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'practitioner_id' => 'Only an active practitioner can start a consultation.',
            ]);
        }

        if (! in_array($practitioner->practitioner_type, Consultation::ELIGIBLE_PRACTITIONER_TYPES, true)) {
            throw ValidationException::withMessages([
                'practitioner_id' => 'Only a doctor, clinical officer, or dentist can start a consultation.',
            ]);
        }

        return $practitioner;
    }

    /**
     * @throws ValidationException
     */
    protected function assertMutable(Consultation $consultation): void
    {
        if (in_array($consultation->status, [Consultation::STATUS_COMPLETED, Consultation::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages([
                'status' => "Cannot modify a consultation with status \"{$consultation->status}\".",
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function assertTransitionAllowed(Consultation $consultation, string $newStatus): void
    {
        if (! $consultation->canTransitionTo($newStatus)) {
            throw ValidationException::withMessages([
                'status' => "Cannot change consultation status from \"{$consultation->status}\" to \"{$newStatus}\".",
            ]);
        }
    }
}
