export type DiagnosisType = 'primary' | 'secondary';

export type DiagnosisStatus = 'provisional' | 'confirmed' | 'resolved' | 'cancelled';

export interface DiagnosisCodeSearchResult {
    id: number;
    coding_system: string;
    code: string;
    description: string;
    category: string | null;
}

export interface DiagnosisUser {
    id: number;
    name: string;
}

export interface EncounterDiagnosis {
    id: number;
    clinical_encounter_id: number;
    consultation_id: number | null;
    diagnosis_code: DiagnosisCodeSearchResult | null;
    diagnosis_type: DiagnosisType;
    status: DiagnosisStatus;
    clinical_note: string | null;
    diagnosed_by: DiagnosisUser | null;
    diagnosed_at: string;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    corrected_at: string | null;
    correction_reason: string | null;
    created_at: string;
    updated_at: string;
}

export interface DiagnosisFormData {
    diagnosis_code_id: string;
    diagnosis_type: DiagnosisType | '';
    clinical_note: string;
    [key: string]: string;
}
