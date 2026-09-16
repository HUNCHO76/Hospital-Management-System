export type MedicationStatus = 'active' | 'inactive';

export type MedicationForm =
    | 'tablet'
    | 'capsule'
    | 'syrup'
    | 'suspension'
    | 'injection'
    | 'cream'
    | 'ointment'
    | 'drops'
    | 'suppository'
    | 'inhaler'
    | 'powder'
    | 'solution'
    | 'other';

export type MedicationRoute =
    | 'oral'
    | 'intravenous'
    | 'intramuscular'
    | 'subcutaneous'
    | 'topical'
    | 'rectal'
    | 'vaginal'
    | 'ophthalmic'
    | 'otic'
    | 'nasal'
    | 'inhalation'
    | 'sublingual'
    | 'other';

export interface MedicationSearchResult {
    id: number;
    code: string;
    generic_name: string;
    brand_name: string | null;
    strength: string | null;
    form: MedicationForm | null;
}

export interface MedicationUser {
    id: number;
    UserName: string;
}

/**
 * The full medication catalog entry (Phase 6, Step 45) — the shape
 * returned by MedicationController's index/show/edit actions. Mirrors
 * RadiologyProcedure's own TypeScript shape.
 */
export interface Medication {
    id: number;
    code: string;
    generic_name: string;
    brand_name: string | null;
    strength: string | null;
    form: MedicationForm | null;
    route: MedicationRoute | null;
    unit: string | null;
    category: string | null;
    description: string | null;
    nhif_code: string | null;
    price: string | null;
    status: MedicationStatus;
    created_by: MedicationUser | null;
    updated_by: MedicationUser | null;
    created_at: string;
    updated_at: string;
}

export interface MedicationFilters {
    search: string;
    category: string;
    form: string;
    route: string;
    status: string;
}

export interface MedicationFormData {
    code: string;
    generic_name: string;
    brand_name: string;
    strength: string;
    form: MedicationForm | '';
    route: MedicationRoute | '';
    unit: string;
    category: string;
    description: string;
    nhif_code: string;
    price: string;
    [key: string]: string;
}
