export type CarePlanStatus = 'draft' | 'active' | 'completed' | 'cancelled';

export type CarePlanItemType = 'medication' | 'procedure' | 'clinical_intervention' | 'referral' | 'monitoring' | 'follow_up' | 'other';

export type CarePlanItemStatus = 'planned' | 'active' | 'completed' | 'cancelled';

export type CarePlanItemPriority = 'low' | 'medium' | 'high';

export type PrescriptionRoute = 'oral' | 'intravenous' | 'intramuscular' | 'subcutaneous' | 'topical' | 'rectal' | 'inhalation' | 'other';

export interface CarePlanUser {
    id: number;
    name: string;
}

export interface Prescription {
    id: number;
    medication: { id: number; generic_name: string } | null;
    strength: string | null;
    dose: string | null;
    route: PrescriptionRoute | null;
    frequency: string | null;
    duration: string | null;
    quantity: string | null;
    instructions: string | null;
}

export interface CarePlanItem {
    id: number;
    care_plan_id: number;
    encounter_diagnosis: {
        id: number;
        diagnosis_code: { code: string; description: string } | null;
    } | null;
    type: CarePlanItemType;
    description: string;
    priority: CarePlanItemPriority | null;
    status: CarePlanItemStatus;
    start_date: string | null;
    end_date: string | null;
    instructions: string | null;
    referred_to_department: { id: number; name: string } | null;
    referred_to_practitioner: { id: number; name: string | null } | null;
    prescription: Prescription | null;
    created_by: CarePlanUser | null;
    activated_at: string | null;
    activated_by: CarePlanUser | null;
    completed_at: string | null;
    completed_by: CarePlanUser | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    corrected_at: string | null;
    correction_reason: string | null;
    created_at: string;
}

export interface CarePlan {
    id: number;
    clinical_encounter_id: number;
    status: CarePlanStatus;
    goals: string | null;
    clinical_instructions: string | null;
    monitoring_instructions: string | null;
    follow_up_instructions: string | null;
    items: CarePlanItem[];
    started_at: string | null;
    completed_at: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    corrected_at: string | null;
    correction_reason: string | null;
    created_at: string;
    updated_at: string;
}

export interface CarePlanFormData {
    goals: string;
    clinical_instructions: string;
    monitoring_instructions: string;
    follow_up_instructions: string;
    [key: string]: string;
}

export interface PrescriptionFormData {
    medication_id: string;
    strength: string;
    dose: string;
    route: PrescriptionRoute | '';
    frequency: string;
    duration: string;
    quantity: string;
    instructions: string;
    [key: string]: string;
}

export interface CarePlanItemFormData {
    type: CarePlanItemType | '';
    description: string;
    priority: CarePlanItemPriority | '';
    encounter_diagnosis_id: string;
    start_date: string;
    end_date: string;
    instructions: string;
    referred_to_department_id: string;
    referred_to_practitioner_id: string;
    prescription: PrescriptionFormData;
    // Kept broad (rather than `string`) so it stays a supertype of every
    // property above, including the nested `prescription` object — an
    // index signature is required both to satisfy Inertia's FormDataType
    // constraint and to allow dotted error keys like
    // "prescription.medication_id" to be looked up on the errors object.
    [key: string]: string | PrescriptionFormData;
}
