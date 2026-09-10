export type ClinicalEncounterStatus = 'open' | 'in_progress' | 'completed' | 'cancelled';

export type ClinicalEncounterDisposition = 'completed' | 'follow_up' | 'referred' | 'admitted' | 'transferred';

export type ClinicalEncounterType = 'opd' | 'walk_in' | 'scheduled' | 'follow_up';

export interface ClinicalEncounterPatient {
    id: number;
    patient_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    date_of_birth?: string | null;
    gender?: string | null;
}

export interface ClinicalEncounterDepartment {
    id: number;
    name: string;
    code: string;
}

export interface ClinicalEncounterPractitionerStaff {
    id: number;
    first_name: string;
    last_name: string;
    department_id?: number | null;
}

export interface ClinicalEncounterPractitioner {
    id: number;
    staff_id?: number;
    practitioner_number: string;
    practitioner_type?: string;
    staff?: ClinicalEncounterPractitionerStaff;
}

export interface ClinicalEncounterReceptionVisit {
    id: number;
    visit_number: string;
    status: string;
}

export interface ClinicalEncounterAppointment {
    id: number;
    appointment_number: string;
    appointment_type: string;
    scheduled_start?: string;
}

export interface ClinicalEncounterConsultationSummary {
    id: number;
    clinical_encounter_id: number;
    status: string;
}

export interface ClinicalEncounter {
    id: number;
    encounter_number: string;
    patient?: ClinicalEncounterPatient;
    department?: ClinicalEncounterDepartment;
    practitioner?: ClinicalEncounterPractitioner | null;
    reception_visit?: ClinicalEncounterReceptionVisit | null;
    appointment?: ClinicalEncounterAppointment | null;
    consultation?: ClinicalEncounterConsultationSummary | null;
    encounter_type: ClinicalEncounterType;
    status: ClinicalEncounterStatus;
    disposition: ClinicalEncounterDisposition | null;
    disposition_notes: string | null;
    started_at: string | null;
    ended_at: string | null;
    cancellation_reason: string | null;
    created_at: string;
    updated_at: string;
}

export interface ClinicalEncounterFormData {
    patient_id: string;
    department_id: string;
    practitioner_id: string;
    reception_visit_id: string;
    appointment_id: string;
    encounter_type: ClinicalEncounterType | '';
    [key: string]: string;
}

export type ClinicalEncounterWorkflowStage = 'waiting_for_triage' | 'ready_for_consultation' | 'in_consultation' | 'completed';

export interface ClinicalEncounterFilters {
    search: string;
    department_id: string;
    practitioner_id: string;
    status: string;
    encounter_type: string;
    date_from: string;
    date_to: string;
    workflow_stage: string;
}
