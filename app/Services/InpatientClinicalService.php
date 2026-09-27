<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\ClinicalEncounter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 9, Step 74 — the inpatient clinical care orchestration layer.
 *
 * This service's sole job is the inpatient-specific parts: opening an
 * inpatient clinical encounter against an admission, and updating the
 * admission's clinical status. Everything else (vitals, notes, diagnoses,
 * care plans, lab/radiology/prescription orders) is delegated to the
 * existing, already-working Phase 3–6 services via the standard
 * ClinicalEncounter context — no duplication.
 *
 * Deliberately thin: clinical orders, notes, and diagnoses are owned by
 * their own services. This service creates and validates the inpatient
 * encounter context those services rely on.
 */
class InpatientClinicalService
{
    public function __construct(
        protected ClinicalEncounterService $encounterService,
        protected AuditLogService $auditLog,
    ) {}

    /**
     * Open an inpatient clinical encounter against an active admission.
     * An admission may have multiple encounters over its stay (e.g., initial
     * admission encounter + a specialist review encounter), all linked back
     * to the same admission via admission_id.
     *
     * @throws ValidationException
     */
    public function openEncounter(Admission $admission, int $practitionerId, Request $request): ClinicalEncounter
    {
        if (! $admission->isActive()) {
            throw ValidationException::withMessages([
                'admission_id' => 'An inpatient encounter can only be opened against an active admission.',
            ]);
        }

        $encounter = DB::transaction(function () use ($admission, $practitionerId, $request) {
            $encounter = ClinicalEncounter::create([
                'patient_id' => $admission->patient_id,
                'admission_id' => $admission->id,
                'department_id' => $admission->department_id ?? $this->requireDepartmentId($admission),
                'practitioner_id' => $practitionerId,
                'encounter_type' => 'inpatient',
                'status' => ClinicalEncounter::STATUS_IN_PROGRESS,
                'started_at' => now(),
                'created_by' => $request->user()->id,
            ]);

            $this->auditLog->record(
                'inpatient_encounter_opened',
                $encounter,
                null,
                $encounter->only(['patient_id', 'admission_id', 'practitioner_id', 'encounter_type', 'status']),
                $request,
            );

            return $encounter;
        });

        return $encounter;
    }

    /**
     * Update the patient's clinical status during an active admission.
     * Status changes are auditable and require the user to hold the
     * inpatient.clinical.view permission (enforced in the controller).
     *
     * @throws ValidationException
     */
    public function updateClinicalStatus(Admission $admission, string $clinicalStatus, Request $request): Admission
    {
        if (! in_array($clinicalStatus, Admission::CLINICAL_STATUSES, true)) {
            throw ValidationException::withMessages([
                'clinical_status' => "Invalid clinical status: \"{$clinicalStatus}\". Valid: ".implode(', ', Admission::CLINICAL_STATUSES).'.',
            ]);
        }

        if (! $admission->isActive()) {
            throw ValidationException::withMessages([
                'admission_id' => 'Clinical status can only be updated on an active admission.',
            ]);
        }

        return DB::transaction(function () use ($admission, $clinicalStatus, $request) {
            $admission = Admission::query()->whereKey($admission->id)->lockForUpdate()->firstOrFail();

            $oldStatus = $admission->clinical_status;

            $admission->update([
                'clinical_status' => $clinicalStatus,
                'clinical_status_updated_by' => $request->user()->id,
                'clinical_status_updated_at' => now(),
            ]);

            $this->auditLog->record(
                'inpatient_clinical_status_updated',
                $admission,
                ['clinical_status' => $oldStatus],
                ['clinical_status' => $clinicalStatus],
                $request,
            );

            return $admission;
        });
    }

    /**
     * Fallback: if the admission has no department, check the hospital's
     * default department. Rather than silently defaulting, we throw so the
     * caller knows they must resolve the data gap.
     *
     * @throws ValidationException
     */
    protected function requireDepartmentId(Admission $admission): int
    {
        throw ValidationException::withMessages([
            'department_id' => 'This admission has no department assigned. Please edit the admission to set a department before opening an inpatient encounter.',
        ]);
    }
}
