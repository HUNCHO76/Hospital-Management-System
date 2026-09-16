import { type CarePlan } from '@/types/care-plan';
import {
    type ClinicalEncounterDepartment,
    type ClinicalEncounterDisposition,
    type ClinicalEncounterStatus,
    type ClinicalEncounterType,
} from '@/types/clinical-encounter';
import { type ClinicalNote } from '@/types/clinical-note';
import { type Consultation } from '@/types/consultation';
import { type EncounterDiagnosis } from '@/types/diagnosis';
import {
    type PrescriptionDurationUnit,
    type PrescriptionFrequency,
    type PrescriptionOrderItemStatus,
    type PrescriptionOrderStatus,
    type PrescriptionOrderUser,
    type PrescriptionRoute,
    type PrescriptionVerificationStatus,
} from '@/types/prescription';
import {
    type RadiologyBodySite,
    type RadiologyModality,
    type RadiologyOrderPriority,
    type RadiologyOrderStatus,
    type RadiologyResult,
} from '@/types/radiology';
import { type TriageAssessment } from '@/types/triage';

export interface ClinicalHistoryPractitioner {
    id: number;
    practitioner_number: string;
    name: string | null;
}

/**
 * Only ever RELEASED results (Phase 4, Step 35, spec Part 23/29) — see
 * ClinicalHistoryEncounterResource::releasedLaboratoryResults().
 */
export interface ClinicalHistoryLaboratoryResult {
    id: number;
    status: 'released';
    test_code: string | null;
    test_name: string | null;
    sample_number: string | null;
    value: string | null;
    unit: string | null;
    abnormal_flag: string | null;
    verified_by: string | null;
    released_by: string | null;
    released_at: string | null;
    active_report_id: number | null;
}

/**
 * Plain order/item status tracking, shown alongside radiology_results below
 * (Phase 5, Step 44) — this surfaces every order regardless of status,
 * unlike radiology_results which only ever contains RELEASED reports.
 */
export interface ClinicalHistoryRadiologyOrderItem {
    id: number;
    procedure_code: string;
    procedure_name: string;
    modality_snapshot: RadiologyModality | null;
    body_site_snapshot: RadiologyBodySite[] | null;
    status: string;
}

export interface ClinicalHistoryRadiologyOrder {
    id: number;
    order_number: string;
    priority: RadiologyOrderPriority;
    status: RadiologyOrderStatus;
    ordered_at: string | null;
    items: ClinicalHistoryRadiologyOrderItem[];
}

/**
 * Prescription is its own sibling branch off the encounter (Phase 6, Step
 * 46), shown regardless of status — distinct from the pre-existing,
 * unrelated CarePlanItem-nested Prescription already surfaced via
 * `care_plan.items[].prescription` above.
 */
export interface ClinicalHistoryPrescriptionOrderItem {
    id: number;
    medication_name_snapshot: string;
    strength_snapshot: string | null;
    dose: string;
    dose_unit: string | null;
    frequency: PrescriptionFrequency;
    route: PrescriptionRoute;
    duration: number | null;
    duration_unit: PrescriptionDurationUnit | null;
    status: PrescriptionOrderItemStatus;
}

export interface ClinicalHistoryPrescriptionOrder {
    id: number;
    prescription_number: string;
    status: PrescriptionOrderStatus;
    prescribed_at: string | null;
    prescriber: PrescriptionOrderUser | null;
    items: ClinicalHistoryPrescriptionOrderItem[];
    verification_status: PrescriptionVerificationStatus | null;
    dispensing_status: 'pending' | 'partially_dispensed' | 'dispensed' | null;
}

export interface ClinicalHistoryEncounter {
    id: number;
    encounter_number: string;
    encounter_type: ClinicalEncounterType;
    status: ClinicalEncounterStatus;
    disposition: ClinicalEncounterDisposition | null;
    disposition_notes: string | null;
    started_at: string | null;
    ended_at: string | null;
    department: ClinicalEncounterDepartment | null;
    practitioner: ClinicalHistoryPractitioner | null;
    triage: TriageAssessment | null;
    consultation: Consultation | null;
    diagnoses: EncounterDiagnosis[];
    care_plan: CarePlan | null;
    clinical_notes: ClinicalNote[];
    laboratory_results: ClinicalHistoryLaboratoryResult[];
    radiology_results: RadiologyResult[];
    radiology_orders: ClinicalHistoryRadiologyOrder[];
    prescription_orders: ClinicalHistoryPrescriptionOrder[];
}

export interface ClinicalHistoryFilters {
    date_from: string;
    date_to: string;
    department_id: string;
    practitioner_id: string;
    encounter_type: string;
    status: string;
    diagnosis: string;
    note_type: string;
}
