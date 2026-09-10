import { type PatientInsurance } from '@/types/patient-insurance';

export type PatientStatus = 'active' | 'inactive' | 'deceased';
export type PatientGender = 'male' | 'female';
export type MaritalStatus = 'single' | 'married' | 'divorced' | 'widowed';
export type BloodGroup = 'A+' | 'A-' | 'B+' | 'B-' | 'AB+' | 'AB-' | 'O+' | 'O-';

export interface PatientContact {
    id?: number;
    patient_id?: number;
    name: string;
    relationship: string;
    phone: string;
    alternative_phone: string | null;
    address: string | null;
    is_primary: boolean;
}

export interface Patient {
    id: number;
    patient_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    gender: PatientGender;
    date_of_birth: string | null;
    phone: string | null;
    email: string | null;
    national_id: string | null;
    passport_number: string | null;
    other_identification: string | null;
    occupation: string | null;
    marital_status: MaritalStatus | null;
    blood_group: BloodGroup | null;
    address: string | null;
    region: string | null;
    district: string | null;
    ward: string | null;
    status: PatientStatus;
    contacts?: PatientContact[];
    insurances?: PatientInsurance[];
    created_at: string;
    updated_at: string;
}

/**
 * A row in patient search/list results. Deliberately narrower than `Patient`
 * (used for a single patient's full profile) — the phone is masked and
 * identification numbers are omitted, since a search result is for
 * identification only, not full record access.
 */
export interface PatientListItem {
    id: number;
    patient_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    gender: PatientGender;
    date_of_birth: string | null;
    phone: string | null;
    status: PatientStatus;
    created_at: string;
}

/**
 * A row returned by the lightweight `/patients/search-json` endpoint used by
 * `components/patients/patient-selector.tsx` — shared by the appointment
 * booking form and the reception walk-in form, so patient search is never
 * re-implemented per module.
 */
export interface PatientSearchResult {
    id: number;
    patient_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    phone: string | null;
}

export interface PossibleDuplicatePatient {
    id: number;
    patient_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    date_of_birth: string | null;
    phone: string | null;
    national_id: string | null;
    passport_number?: string | null;
}

export interface PatientContactFormData {
    id?: number;
    name: string;
    relationship: string;
    phone: string;
    alternative_phone: string;
    address: string;
    is_primary: boolean;
    [key: string]: string | number | boolean | undefined;
}

export interface PatientFormData {
    first_name: string;
    middle_name: string;
    last_name: string;
    gender: PatientGender | '';
    date_of_birth: string;
    phone: string;
    email: string;
    national_id: string;
    passport_number: string;
    other_identification: string;
    occupation: string;
    marital_status: MaritalStatus | '';
    blood_group: BloodGroup | '';
    address: string;
    region: string;
    district: string;
    ward: string;
    status: PatientStatus;
    confirm_duplicate: boolean;
    contacts: PatientContactFormData[];
    [key: string]: string | boolean | PatientContactFormData[];
}

export interface PatientFilters {
    search: string;
    patient_number: string;
    national_id: string;
    passport_number: string;
    phone: string;
    date_of_birth: string;
    gender: string;
    status: string;
    region: string;
    district: string;
    sort: string;
}
