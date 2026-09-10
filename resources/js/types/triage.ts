export type TriageStatus = 'pending' | 'in_progress' | 'completed' | 'cancelled';

export type TriageCategory = 'emergency' | 'very_urgent' | 'urgent' | 'non_urgent';

export type VitalSignFlag = 'low' | 'high' | 'critical' | 'normal';

export interface VitalSignUser {
    id: number;
    name: string;
}

export interface VitalSign {
    id: number;
    triage_assessment_id: number;
    clinical_encounter_id: number;
    recorded_by?: VitalSignUser | null;
    recorded_at: string;
    temperature_c: number | null;
    systolic_mmhg: number | null;
    diastolic_mmhg: number | null;
    pulse_bpm: number | null;
    respiratory_rate_bpm: number | null;
    oxygen_saturation_pct: number | null;
    weight_kg: number | null;
    height_cm: number | null;
    bmi: number | null;
    flags: Partial<
        Record<'temperature_c' | 'systolic_mmhg' | 'diastolic_mmhg' | 'pulse_bpm' | 'respiratory_rate_bpm' | 'oxygen_saturation_pct', VitalSignFlag>
    >;
    corrects_vital_sign_id: number | null;
    correction_reason: string | null;
    is_corrected?: boolean;
    created_at: string;
}

export interface TriageAssessment {
    id: number;
    clinical_encounter_id: number;
    status: TriageStatus;
    triage_category: TriageCategory | null;
    assessed_by?: VitalSignUser | null;
    assessment_time: string | null;
    chief_complaint: string | null;
    pain_score: number | null;
    notes: string | null;
    vital_signs: VitalSign[];
    completed_at: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    created_at: string;
    updated_at: string;
}

export interface VitalSignFormData {
    temperature_c: string;
    systolic_mmhg: string;
    diastolic_mmhg: string;
    pulse_bpm: string;
    respiratory_rate_bpm: string;
    oxygen_saturation_pct: string;
    weight_kg: string;
    height_cm: string;
    [key: string]: string;
}

export interface TriageUpdateFormData {
    triage_category: TriageCategory | '';
    chief_complaint: string;
    pain_score: string;
    notes: string;
    [key: string]: string;
}
