export type ConsultationStatus = 'pending' | 'in_progress' | 'completed' | 'cancelled';

export interface ConsultationPractitioner {
    id: number;
    name: string | null;
}

export interface Consultation {
    id: number;
    clinical_encounter_id: number;
    status: ConsultationStatus;
    practitioner?: ConsultationPractitioner | null;
    started_at: string | null;
    chief_complaint: string | null;
    history_presenting_illness: string | null;
    relevant_medical_history: string | null;
    relevant_surgical_history: string | null;
    relevant_social_history: string | null;
    review_of_systems: string | null;
    physical_examination: string | null;
    assessment_summary: string | null;
    clinical_notes: string | null;
    plan_summary: string | null;
    completed_at: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    corrected_at: string | null;
    correction_reason: string | null;
    created_at: string;
    updated_at: string;
}

export interface ConsultationFormData {
    chief_complaint: string;
    history_presenting_illness: string;
    relevant_medical_history: string;
    relevant_surgical_history: string;
    relevant_social_history: string;
    review_of_systems: string;
    physical_examination: string;
    assessment_summary: string;
    clinical_notes: string;
    plan_summary: string;
    [key: string]: string;
}

export interface ConsultationCorrectionFormData extends ConsultationFormData {
    correction_reason: string;
}
