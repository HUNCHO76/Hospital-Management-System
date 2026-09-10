export type ClinicalNoteType = 'progress' | 'follow_up' | 'nursing' | 'clinical_observation' | 'procedure' | 'other';

export type ClinicalNoteStatus = 'draft' | 'signed' | 'amended' | 'cancelled';

export interface ClinicalNoteUser {
    id: number;
    name: string;
}

export interface ClinicalNoteVersion {
    id: number;
    version_number: number;
    note_type: ClinicalNoteType;
    note_content: string;
    action: 'signed' | 'amended';
    author: ClinicalNoteUser | null;
    reason: string | null;
    created_at: string;
}

export interface ClinicalNote {
    id: number;
    clinical_encounter_id: number;
    patient_id: number;
    note_type: ClinicalNoteType;
    note_content: string;
    status: ClinicalNoteStatus;
    author: ClinicalNoteUser | null;
    signed_at: string | null;
    signed_by: ClinicalNoteUser | null;
    corrected_at: string | null;
    correction_reason: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    versions: ClinicalNoteVersion[];
    created_at: string;
    updated_at: string;
}

export interface ClinicalNoteFormData {
    note_type: ClinicalNoteType | '';
    note_content: string;
    [key: string]: string;
}

export interface AmendClinicalNoteFormData {
    note_type: ClinicalNoteType | '';
    note_content: string;
    amendment_reason: string;
    [key: string]: string;
}
