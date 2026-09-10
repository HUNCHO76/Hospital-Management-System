import { type InsuranceProviderSummary } from '@/types/insurance-provider';

export type PatientInsuranceStatus = 'active' | 'inactive' | 'expired' | 'suspended';
export type VerificationStatus = 'pending' | 'verified' | 'failed' | 'expired';
export type MembershipRelationship = 'self' | 'spouse' | 'child' | 'parent' | 'other';

export interface InsuranceSchemeSummary {
    id: number;
    insurance_provider_id: number;
    name: string;
    code: string;
}

export interface PatientInsurancePatient {
    id: number;
    patient_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
}

export interface PatientInsurance {
    id: number;
    patient_id: number;
    insurance_provider_id: number;
    insurance_scheme_id: number | null;
    membership_number: string | null;
    policy_number: string | null;
    card_number: string | null;
    principal_member_name: string | null;
    relationship: MembershipRelationship | null;
    effective_from: string | null;
    effective_to: string | null;
    is_primary: boolean;
    status: PatientInsuranceStatus;
    verification_status: VerificationStatus;
    verified_at: string | null;
    patient?: PatientInsurancePatient;
    insurance_provider?: InsuranceProviderSummary;
    insurance_scheme?: InsuranceSchemeSummary | null;
    created_at: string;
    updated_at: string;
}

export interface PossibleDuplicatePatientInsurance {
    id: number;
    membership_number: string | null;
    status: PatientInsuranceStatus;
    patient?: PatientInsurancePatient;
    insurance_provider?: InsuranceProviderSummary;
}

export interface PatientInsuranceFormData {
    insurance_provider_id: string;
    insurance_scheme_id: string;
    membership_number: string;
    policy_number: string;
    card_number: string;
    principal_member_name: string;
    relationship: MembershipRelationship | '';
    effective_from: string;
    effective_to: string;
    is_primary: boolean;
    status: PatientInsuranceStatus;
    verification_status: VerificationStatus;
    confirm_duplicate: boolean;
    [key: string]: string | boolean;
}

export interface PatientInsuranceFilters {
    search: string;
    insurance_provider_id: string;
    status: string;
    verification_status: string;
}
