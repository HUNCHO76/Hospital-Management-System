<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\CarePlan;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalNote;
use App\Models\DischargeDocument;
use App\Models\EncounterDiagnosis;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryReport;
use App\Models\PrescriptionOrder;
use App\Models\RadiologyAppointment;
use App\Models\RadiologyReport;
use App\Models\VitalSign;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;

/**
 * Assembles and versions discharge documents for a completed (or active)
 * admission (Phase 9, Step 94).
 *
 * Read-only contract: this service NEVER modifies clinical, financial,
 * NHIF, admission, or bed data. It only reads from existing records and
 * writes to the `discharge_documents` table.
 *
 * Versioning: each call to generate() creates a new version row.
 * The previously active document is superseded — never overwritten.
 */
class DischargeDocumentService
{
    public function __construct(
        private readonly InpatientBillingService $billingService
    ) {}

    /**
     * Aggregate all discharge data from existing records (read-only).
     *
     * @return array<string, mixed>
     */
    public function assembleData(Admission $admission): array
    {
        $admission->loadMissing([
            'patient:id,patient_number,first_name,middle_name,last_name,date_of_birth,gender,phone_number',
            'hospital:id,name,address,phone',
            'department:id,name',
            'practitioner.staff.user:id,UserName',
            'patientInsurance.insuranceProvider:id,name',
            'patientInsurance.insuranceScheme:id,name',
            'nhifAdmission',
        ]);

        $encounterIds = ClinicalEncounter::query()
            ->where('admission_id', $admission->id)
            ->pluck('id')
            ->toArray();

        return [
            'admission' => $this->admissionData($admission),
            'patient' => $this->patientData($admission),
            'diagnoses' => $this->diagnosesData($encounterIds),
            'clinical_notes' => $this->clinicalNotesData($encounterIds),
            'discharge_medications' => $this->medicationsData($encounterIds),
            'laboratory_findings' => $this->laboratoryData($encounterIds),
            'radiology_findings' => $this->radiologyData($encounterIds),
            'care_plans' => $this->carePlanData($encounterIds),
            'vitals_at_discharge' => $this->vitalsData($admission),
            'financial_summary' => $this->billingService->summary($admission),
            'insurance' => $this->insuranceData($admission),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate a new discharge document version for the admission.
     *
     * The previous active document (if any) is superseded. Each call
     * creates a new version — never silently overwrites the previous one.
     */
    public function generate(Admission $admission, ?string $amendmentReason, Request $request): DischargeDocument
    {
        $data = $this->assembleData($admission);
        $user = $request->user();

        $nextVersion = DischargeDocument::query()
            ->where('admission_id', $admission->id)
            ->max('version') + 1;

        $isAmendment = $nextVersion > 1;

        $document = \DB::transaction(function () use ($admission, $data, $user, $nextVersion, $isAmendment, $amendmentReason): DischargeDocument {
            DischargeDocument::query()
                ->where('admission_id', $admission->id)
                ->where('status', DischargeDocument::STATUS_ACTIVE)
                ->update(['status' => DischargeDocument::STATUS_SUPERSEDED]);

            return DischargeDocument::create([
                'admission_id' => $admission->id,
                'hospital_id' => $admission->hospital_id,
                'patient_id' => $admission->patient_id,
                'version' => $nextVersion,
                'status' => DischargeDocument::STATUS_ACTIVE,
                'data_snapshot' => $data,
                'generated_by' => $user->id,
                'generated_at' => now(),
                'amendment_reason' => $isAmendment ? $amendmentReason : null,
                'amended_by' => $isAmendment ? $user->id : null,
                'amended_at' => $isAmendment ? now() : null,
            ]);
        });

        return $document;
    }

    /**
     * All discharge document versions for an admission (newest first).
     *
     * @return EloquentCollection<int, DischargeDocument>
     */
    public function forAdmission(Admission $admission): EloquentCollection
    {
        return DischargeDocument::query()
            ->where('admission_id', $admission->id)
            ->with('generatedBy:id,UserName', 'amendedBy:id,UserName')
            ->orderByDesc('version')
            ->get();
    }

    // -------------------------------------------------------------------------
    // Private data-assembly helpers (all read-only)
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function admissionData(Admission $admission): array
    {
        return [
            'admission_number' => $admission->admission_number,
            'admission_type' => $admission->admission_type,
            'ward_type' => $admission->ward_type,
            'room_type' => $admission->room_type,
            'admitted_at' => $admission->admitted_at?->toIso8601String(),
            'discharged_at' => $admission->discharged_at?->toIso8601String(),
            'discharge_type' => $admission->discharge_type,
            'discharge_reason' => $admission->discharge_reason,
            'diagnosis_at_admission' => $admission->diagnosis_at_admission,
            'clinical_status' => $admission->clinical_status,
            'status' => $admission->status,
            'hospital_name' => $admission->hospital?->name,
            'department_name' => $admission->department?->name,
            'practitioner_name' => $this->practitionerName($admission),
        ];
    }

    /** @return array<string, mixed> */
    private function patientData(Admission $admission): array
    {
        $p = $admission->patient;

        return [
            'patient_number' => $p?->patient_number,
            'full_name' => trim(implode(' ', array_filter([
                $p?->first_name,
                $p?->middle_name,
                $p?->last_name,
            ]))),
            'date_of_birth' => $p?->date_of_birth?->toDateString(),
            'gender' => $p?->gender,
            'phone_number' => $p?->phone_number,
        ];
    }

    /**
     * @param  list<int>  $encounterIds
     * @return list<array<string, mixed>>
     */
    private function diagnosesData(array $encounterIds): array
    {
        if (empty($encounterIds)) {
            return [];
        }

        return EncounterDiagnosis::query()
            ->whereIn('clinical_encounter_id', $encounterIds)
            ->with('diagnosisCode:id,code,description')
            ->orderBy('created_at')
            ->get()
            ->map(fn (EncounterDiagnosis $d) => [
                'code' => $d->diagnosisCode?->code,
                'description' => $d->diagnosisCode?->description,
                'status' => $d->status ?? null,
                'is_primary' => $d->diagnosis_type === EncounterDiagnosis::TYPE_PRIMARY,
            ])
            ->toArray();
    }

    /**
     * @param  list<int>  $encounterIds
     * @return list<array<string, mixed>>
     */
    private function clinicalNotesData(array $encounterIds): array
    {
        if (empty($encounterIds)) {
            return [];
        }

        return ClinicalNote::query()
            ->whereIn('clinical_encounter_id', $encounterIds)
            ->whereIn('status', [ClinicalNote::STATUS_SIGNED, ClinicalNote::STATUS_AMENDED])
            ->with('author:id,UserName')
            ->orderBy('created_at')
            ->get()
            ->map(fn (ClinicalNote $n) => [
                'note_type' => $n->note_type ?? null,
                'content' => $n->note_content ?? null,
                'status' => $n->status,
                'signed_at' => $n->signed_at?->toIso8601String() ?? null,
                'author' => $n->author?->UserName,
            ])
            ->toArray();
    }

    /**
     * Active prescription order items at the time of discharge.
     *
     * @param  list<int>  $encounterIds
     * @return list<array<string, mixed>>
     */
    private function medicationsData(array $encounterIds): array
    {
        if (empty($encounterIds)) {
            return [];
        }

        return PrescriptionOrder::query()
            ->whereIn('clinical_encounter_id', $encounterIds)
            ->where('status', PrescriptionOrder::STATUS_ACTIVE)
            ->with('items')
            ->get()
            ->flatMap(fn (PrescriptionOrder $order) => $order->items->map(fn ($item) => [
                'medication_name' => $item->medication_name_snapshot,
                'dose' => $item->dose,
                'dose_unit' => $item->dose_unit,
                'route' => $item->route_snapshot ?? $item->route,
                'frequency' => $item->frequency,
                'duration' => $item->duration,
                'duration_unit' => $item->duration_unit,
                'quantity' => $item->quantity ?? null,
            ]))
            ->values()
            ->toArray();
    }

    /**
     * Released laboratory reports from this admission's encounters.
     *
     * @param  list<int>  $encounterIds
     * @return list<array<string, mixed>>
     */
    private function laboratoryData(array $encounterIds): array
    {
        if (empty($encounterIds)) {
            return [];
        }

        $orderIds = LaboratoryOrder::query()
            ->whereIn('clinical_encounter_id', $encounterIds)
            ->pluck('id')
            ->toArray();

        if (empty($orderIds)) {
            return [];
        }

        return LaboratoryReport::query()
            ->whereIn('laboratory_order_id', $orderIds)
            ->where('status', LaboratoryReport::STATUS_ACTIVE)
            ->orderBy('created_at')
            ->get()
            ->map(fn (LaboratoryReport $r) => [
                'report_number' => $r->report_number ?? null,
                'released_at' => $r->released_at?->toIso8601String() ?? null,
                'results_snapshot' => $r->results_snapshot,
            ])
            ->toArray();
    }

    /**
     * Released radiology reports from this admission's encounters.
     *
     * @param  list<int>  $encounterIds
     * @return list<array<string, mixed>>
     */
    private function radiologyData(array $encounterIds): array
    {
        if (empty($encounterIds)) {
            return [];
        }

        $appointmentIds = RadiologyAppointment::query()
            ->whereHas('radiologyOrderItem.radiologyOrder', function ($q) use ($encounterIds): void {
                $q->whereIn('clinical_encounter_id', $encounterIds);
            })
            ->pluck('id')
            ->toArray();

        if (empty($appointmentIds)) {
            return [];
        }

        return RadiologyReport::query()
            ->whereIn('radiology_appointment_id', $appointmentIds)
            ->where('status', RadiologyReport::STATUS_RELEASED)
            ->orderBy('created_at')
            ->get()
            ->map(fn (RadiologyReport $r) => [
                'findings' => $r->findings,
                'impression' => $r->impression ?? null,
                'recommendations' => $r->recommendations ?? null,
                'released_at' => $r->released_at?->toIso8601String() ?? null,
            ])
            ->toArray();
    }

    /**
     * @param  list<int>  $encounterIds
     * @return list<array<string, mixed>>
     */
    private function carePlanData(array $encounterIds): array
    {
        if (empty($encounterIds)) {
            return [];
        }

        return CarePlan::query()
            ->whereIn('clinical_encounter_id', $encounterIds)
            ->whereIn('status', [CarePlan::STATUS_ACTIVE, CarePlan::STATUS_COMPLETED])
            ->with('items')
            ->get()
            ->flatMap(fn (CarePlan $plan) => $plan->items->map(fn ($item) => [
                'description' => $item->description ?? null,
                'status' => $item->status ?? null,
            ]))
            ->values()
            ->toArray();
    }

    /**
     * Most recent vitals recorded against this admission (condition at
     * discharge). Uses admission_id FK directly on VitalSign.
     *
     * @return array<string, mixed>|null
     */
    private function vitalsData(Admission $admission): ?array
    {
        $vital = VitalSign::query()
            ->where('admission_id', $admission->id)
            ->latest('recorded_at')
            ->first();

        if (! $vital) {
            return null;
        }

        return [
            'recorded_at' => $vital->recorded_at?->toIso8601String(),
            'temperature_c' => $vital->temperature_c,
            'systolic_mmhg' => $vital->systolic_mmhg,
            'diastolic_mmhg' => $vital->diastolic_mmhg,
            'pulse_bpm' => $vital->pulse_bpm,
            'respiratory_rate_bpm' => $vital->respiratory_rate_bpm,
            'oxygen_saturation_pct' => $vital->oxygen_saturation_pct,
            'weight_kg' => $vital->weight_kg,
        ];
    }

    /** @return array<string, mixed>|null */
    private function insuranceData(Admission $admission): ?array
    {
        $ins = $admission->patientInsurance;
        if (! $ins) {
            return null;
        }

        return [
            'membership_number' => $ins->membership_number,
            'nhif_authorization_no' => $ins->nhif_authorization_no,
            'nhif_scheme_id' => $ins->nhif_scheme_id ?? null,
            'nhif_status' => $admission->nhifAdmission?->nhif_status,
            'provider_name' => $ins->insuranceProvider?->name,
            'scheme_name' => $ins->insuranceScheme?->name,
        ];
    }

    private function practitionerName(Admission $admission): ?string
    {
        $staff = $admission->practitioner?->staff;
        if (! $staff) {
            return null;
        }

        return trim(implode(' ', array_filter([
            $staff->first_name ?? null,
            $staff->last_name ?? null,
        ]))) ?: null;
    }
}
