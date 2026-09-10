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
