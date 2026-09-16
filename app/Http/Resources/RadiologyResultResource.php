<?php

namespace App\Http\Resources;

use App\Models\RadiologyAppointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One RELEASED radiology result, as shown on the doctor's clinical
 * encounter page (Phase 5, Step 44, Part 16) and the patient's clinical
 * history timeline (Part 17) — the two surfaces where an already-verified,
 * already-released report becomes an official clinical result the doctor
 * does not need to separately search for. Deliberately a lightweight,
 * purpose-built resource rather than reusing RadiologyReportResource: the
 * caller here already has the patient/encounter/order/item in scope (it is
 * iterating down from the encounter), so re-deriving that same chain
 * upward from the report (as RadiologyReportResource does for the
 * standalone report/verification/release pages) would mean eager-loading
 * the same relations twice. Mirrors the shape and intent of
 * ClinicalHistoryEncounterResource::releasedLaboratoryResults()'s
 * hand-rolled array exactly.
 *
 * Only ever constructed for an appointment whose report is RELEASED — the
 * caller is responsible for that filter (see
 * ClinicalEncounterController::show() and
 * ClinicalHistoryEncounterResource::releasedRadiologyResults()).
 *
 * @mixin RadiologyAppointment
 */
class RadiologyResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $report = $this->report;
        $item = $this->relationLoaded('radiologyOrderItem') ? $this->radiologyOrderItem : null;

        return [
            'id' => $report->id,
            'report_number' => $report->report_number,
            'status' => $report->status,
            'radiology_appointment_id' => $this->id,
            'procedure_code' => $item?->procedure_code,
            'procedure_name' => $item?->procedure_name,
            'modality' => $this->modality,
            'body_site_snapshot' => $item?->body_site_snapshot,
            'examination_completed_at' => optional($this->completed_at)->toIso8601String(),
            'technique' => $report->technique,
            'findings' => $report->findings,
            'impression' => $report->impression,
            'recommendations' => $report->recommendations,
            'reporting_practitioner' => $report->relationLoaded('reportingPractitioner') && $report->reportingPractitioner ? [
                'id' => $report->reportingPractitioner->id,
                'name' => $report->reportingPractitioner->staff?->user?->UserName,
            ] : null,
            'verifying_practitioner' => $report->relationLoaded('verifyingPractitioner') && $report->verifyingPractitioner ? [
                'id' => $report->verifyingPractitioner->id,
                'name' => $report->verifyingPractitioner->staff?->user?->UserName,
            ] : null,
            'verified_at' => optional($report->verified_at)->toIso8601String(),
            'released_by' => $report->relationLoaded('releasedBy') ? $report->releasedBy?->only(['id', 'UserName']) : null,
            'released_at' => optional($report->released_at)->toIso8601String(),
            'images' => $this->relationLoaded('images') ? RadiologyImageResource::collection($this->images)->resolve($request) : [],
        ];
    }
}
