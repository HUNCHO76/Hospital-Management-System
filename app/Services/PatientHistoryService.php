<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\CarePlan;
use App\Models\ClinicalEncounter;
use App\Models\EncounterDiagnosis;
use App\Models\Patient;
use App\Models\PatientInsurance;
use App\Models\ReceptionVisit;
use App\Models\TriageAssessment;
use App\Models\VitalSign;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A read-only aggregation of a patient's non-clinical activity, built
 * entirely from the existing Step 9 audit log rather than a new events
 * table — every action this surfaces (registration, demographic updates,
 * insurance changes, appointment lifecycle, reception visits) is already
 * recorded by AuditLogService when it happens in its own module. This is
 * intentionally the same shape as DashboardController's "recent activity"
 * (action/module/user/created_at) and never exposes old_values/new_values,
 * so no clinical or otherwise sensitive field content leaks through a
 * timeline that reception and other non-clinical roles can see.
 */
class PatientHistoryService
{
    /**
     * @return Collection<int, array{id: int, action: string, module: string, user: string|null, created_at: Carbon|null}>
     */
    public function timeline(Patient $patient, int $limit = 50): Collection
    {
        $insuranceIds = PatientInsurance::query()->where('patient_id', $patient->id)->pluck('id');
        $appointmentIds = Appointment::query()->where('patient_id', $patient->id)->pluck('id');
        $visitIds = ReceptionVisit::query()->where('patient_id', $patient->id)->pluck('id');
        $encounterIds = ClinicalEncounter::query()->where('patient_id', $patient->id)->pluck('id');
        // Triage/vital-sign audit entries are scoped through the patient's
        // own encounters rather than a redundant patient_id column on those
        // tables (Step 22 deliberately avoids duplicating patient
        // ownership — see TriageAssessment's class docs).
        $triageIds = TriageAssessment::query()->whereIn('clinical_encounter_id', $encounterIds)->pluck('id');
        $vitalSignIds = VitalSign::query()->whereIn('clinical_encounter_id', $encounterIds)->pluck('id');
        // Same scoping approach as triage/vital-signs above — only the
        // action name, timestamp, and user ever surface here, never the
        // diagnosis, code, or clinical note content.
        $diagnosisIds = EncounterDiagnosis::query()->whereIn('clinical_encounter_id', $encounterIds)->pluck('id');
        // Only the care plan's own lifecycle events surface here, not its
        // individual items/prescriptions — the plan-level action is the
        // meaningful timeline granularity, same content-safety guarantee.
        $carePlanIds = CarePlan::query()->whereIn('clinical_encounter_id', $encounterIds)->pluck('id');

        return AuditLog::query()
            ->with('user:id,UserName')
            ->where(function ($query) use ($patient, $insuranceIds, $appointmentIds, $visitIds, $encounterIds, $triageIds, $vitalSignIds, $diagnosisIds, $carePlanIds) {
                $query->where(fn ($query) => $query->where('auditable_type', Patient::class)->where('auditable_id', $patient->id))
                    ->orWhere(fn ($query) => $query->where('auditable_type', PatientInsurance::class)->whereIn('auditable_id', $insuranceIds))
                    ->orWhere(fn ($query) => $query->where('auditable_type', Appointment::class)->whereIn('auditable_id', $appointmentIds))
                    ->orWhere(fn ($query) => $query->where('auditable_type', ReceptionVisit::class)->whereIn('auditable_id', $visitIds))
                    ->orWhere(fn ($query) => $query->where('auditable_type', ClinicalEncounter::class)->whereIn('auditable_id', $encounterIds))
                    ->orWhere(fn ($query) => $query->where('auditable_type', TriageAssessment::class)->whereIn('auditable_id', $triageIds))
                    ->orWhere(fn ($query) => $query->where('auditable_type', VitalSign::class)->whereIn('auditable_id', $vitalSignIds))
                    ->orWhere(fn ($query) => $query->where('auditable_type', EncounterDiagnosis::class)->whereIn('auditable_id', $diagnosisIds))
                    ->orWhere(fn ($query) => $query->where('auditable_type', CarePlan::class)->whereIn('auditable_id', $carePlanIds));
            })
            ->latest('created_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'module' => $log->moduleName(),
                'user' => $log->user?->UserName,
                'created_at' => $log->created_at,
            ]);
    }
}
