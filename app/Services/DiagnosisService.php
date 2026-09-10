<?php

namespace App\Services;

use App\Models\ClinicalEncounter;
use App\Models\Consultation;
use App\Models\EncounterDiagnosis;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DiagnosisService
{
    public function __construct(protected AuditLogService $auditLog) {}

    /**
     * Record a new diagnosis against an encounter. Requires the encounter to
     * have an engaged consultation (spec §11); a diagnosis created as
     * "primary" while an active primary already exists is rejected outright
     * — the caller must add it as secondary and then explicitly promote it
     * via setPrimary(), so a primary is never silently replaced (spec §26).
     *
     * @param  array{diagnosis_code_id: int, diagnosis_type: string, clinical_note?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function create(ClinicalEncounter $encounter, array $data, Request $request): EncounterDiagnosis
    {
        $diagnosis = DB::transaction(function () use ($encounter, $data, $request) {
            // Locking the encounter row serializes concurrent attempts to
            // create a primary diagnosis for the same encounter, the same
            // way TriageService/ConsultationService serialize their own
            // create-time invariants.
            $encounter = ClinicalEncounter::query()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();

            $this->assertEncounterEligible($encounter);
            $consultation = $this->assertConsultationEligible($encounter);

            if ($data['diagnosis_type'] === EncounterDiagnosis::TYPE_PRIMARY && $this->activePrimary($encounter->id) !== null) {
                throw ValidationException::withMessages([
                    'diagnosis_type' => 'This encounter already has an active primary diagnosis. Add this as secondary, then use "Set as Primary".',
                ]);
            }

            return EncounterDiagnosis::create([
                'clinical_encounter_id' => $encounter->id,
                'consultation_id' => $consultation->id,
                'diagnosis_code_id' => $data['diagnosis_code_id'],
                'diagnosis_type' => $data['diagnosis_type'],
                'status' => EncounterDiagnosis::STATUS_PROVISIONAL,
                'clinical_note' => $data['clinical_note'] ?? null,
                'diagnosed_by' => $request->user()->id,
                'diagnosed_at' => now(),
            ]);
        });

        $this->auditLog->record(
            'diagnosis_created',
            $diagnosis,
            null,
            $diagnosis->only(['clinical_encounter_id', 'diagnosis_code_id', 'diagnosis_type', 'status']),
            $request,
        );

        return $diagnosis;
    }

    /**
     * Update the diagnosis's editable fields. Only allowed while still
     * provisional — a confirmed/resolved diagnosis is a committed clinical
     * record, amendable only through correct() (spec §22).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(EncounterDiagnosis $diagnosis, array $data, Request $request): EncounterDiagnosis
    {
        if ($diagnosis->status !== EncounterDiagnosis::STATUS_PROVISIONAL) {
            throw ValidationException::withMessages([
                'status' => "Cannot modify a diagnosis with status \"{$diagnosis->status}\".",
            ]);
        }

        $oldValues = $diagnosis->only(array_keys($data));

        $diagnosis->update($data);

        $this->auditLog->record('diagnosis_updated', $diagnosis, $oldValues, $diagnosis->only(array_keys($data)), $request);

        return $diagnosis;
    }

    /**
     * Promote this diagnosis to primary, demoting any other active primary
     * on the same encounter to secondary — the only way a primary changes
     * (spec §12). Fully transactional and lock-guarded so two simultaneous
     * promotions on the same encounter can never both win.
     *
     * @throws ValidationException
     */
    public function setPrimary(EncounterDiagnosis $diagnosis, Request $request): EncounterDiagnosis
    {
        return DB::transaction(function () use ($diagnosis, $request) {
            ClinicalEncounter::query()->whereKey($diagnosis->clinical_encounter_id)->lockForUpdate()->firstOrFail();

            if (! in_array($diagnosis->status, EncounterDiagnosis::ACTIVE_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot promote a diagnosis with status \"{$diagnosis->status}\" to primary.",
                ]);
            }

            $current = $this->activePrimary($diagnosis->clinical_encounter_id, excludeId: $diagnosis->id);

            if ($current !== null) {
                $current->update(['diagnosis_type' => EncounterDiagnosis::TYPE_SECONDARY]);
                $this->auditLog->record(
                    'diagnosis_set_primary',
                    $current,
                    ['diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY],
                    ['diagnosis_type' => EncounterDiagnosis::TYPE_SECONDARY],
                    $request,
                );
            }

            $oldType = $diagnosis->diagnosis_type;
            $diagnosis->update(['diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY]);

            $this->auditLog->record(
                'diagnosis_set_primary',
                $diagnosis,
                ['diagnosis_type' => $oldType],
                ['diagnosis_type' => EncounterDiagnosis::TYPE_PRIMARY],
                $request,
            );

            return $diagnosis;
        });
    }

    /**
     * Advance the diagnosis through provisional -> confirmed -> resolved.
     * Cancellation is deliberately not reachable here — see cancel().
     *
     * @throws ValidationException
     */
    public function changeStatus(EncounterDiagnosis $diagnosis, string $newStatus, Request $request): EncounterDiagnosis
    {
        if ($newStatus === EncounterDiagnosis::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'status' => 'Use the cancel action to cancel a diagnosis.',
            ]);
        }

        if (! $diagnosis->canTransitionTo($newStatus)) {
            throw ValidationException::withMessages([
                'status' => "Cannot change diagnosis status from \"{$diagnosis->status}\" to \"{$newStatus}\".",
            ]);
        }

        $oldStatus = $diagnosis->status;
        $diagnosis->update(['status' => $newStatus]);

        $this->auditLog->record('diagnosis_status_changed', $diagnosis, ['status' => $oldStatus], ['status' => $newStatus], $request);

        return $diagnosis;
    }

    /**
     * Cancel the diagnosis, recording who/when/why.
     *
     * @throws ValidationException
     */
    public function cancel(EncounterDiagnosis $diagnosis, string $reason, Request $request): EncounterDiagnosis
    {
        if (! $diagnosis->canTransitionTo(EncounterDiagnosis::STATUS_CANCELLED)) {
            throw ValidationException::withMessages([
                'status' => "Cannot cancel a diagnosis with status \"{$diagnosis->status}\".",
            ]);
        }

        $oldValues = $diagnosis->only(['status', 'cancelled_at', 'cancellation_reason']);

        $diagnosis->update([
            'status' => EncounterDiagnosis::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        $this->auditLog->record('diagnosis_cancelled', $diagnosis, $oldValues, $diagnosis->only(['status', 'cancelled_at', 'cancellation_reason']), $request);

        return $diagnosis;
    }

    /**
     * Amend a confirmed/resolved diagnosis's clinical content. The
     * controlled correction workflow required by spec §22 — the audit log's
     * old/new diff is the "what changed" record.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function correct(EncounterDiagnosis $diagnosis, array $data, string $reason, Request $request): EncounterDiagnosis
    {
        if (! in_array($diagnosis->status, [EncounterDiagnosis::STATUS_CONFIRMED, EncounterDiagnosis::STATUS_RESOLVED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only a confirmed or resolved diagnosis can be corrected.',
            ]);
        }

        $oldValues = $diagnosis->only([...array_keys($data), 'corrected_at', 'corrected_by', 'correction_reason']);

        $diagnosis->update([
            ...$data,
            'corrected_at' => now(),
            'corrected_by' => $request->user()->id,
            'correction_reason' => $reason,
        ]);

        $this->auditLog->record(
            'diagnosis_corrected',
            $diagnosis,
            $oldValues,
            $diagnosis->only([...array_keys($data), 'corrected_at', 'corrected_by', 'correction_reason']),
            $request,
        );

        return $diagnosis;
    }

    /**
     * The encounter's currently active primary diagnosis, if any.
     */
    protected function activePrimary(int $clinicalEncounterId, ?int $excludeId = null): ?EncounterDiagnosis
    {
        return EncounterDiagnosis::query()
            ->where('clinical_encounter_id', $clinicalEncounterId)
            ->where('diagnosis_type', EncounterDiagnosis::TYPE_PRIMARY)
            ->whereIn('status', EncounterDiagnosis::ACTIVE_STATUSES)
            ->when($excludeId, fn ($query) => $query->whereKeyNot($excludeId))
            ->first();
    }

    /**
     * @throws ValidationException
     */
    protected function assertEncounterEligible(ClinicalEncounter $encounter): void
    {
        if (! in_array($encounter->status, ClinicalEncounter::ACTIVE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'clinical_encounter_id' => "Cannot record a diagnosis on an encounter with status \"{$encounter->status}\".",
            ]);
        }
    }

    /**
     * Confirm the encounter has a consultation the doctor has actually
     * engaged with (spec §11) — diagnosis is part of documenting the visit,
     * which may happen before or after the consultation note is marked
     * complete, but never before it has been started.
     *
     * @throws ValidationException
     */
    protected function assertConsultationEligible(ClinicalEncounter $encounter): Consultation
    {
        $consultation = $encounter->consultation;

        if (! $consultation || ! in_array($consultation->status, [Consultation::STATUS_IN_PROGRESS, Consultation::STATUS_COMPLETED], true)) {
            throw ValidationException::withMessages([
                'clinical_encounter_id' => 'A consultation must be started before a diagnosis can be recorded for this encounter.',
            ]);
        }

        return $consultation;
    }
}
