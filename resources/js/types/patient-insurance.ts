import { type InsuranceProviderSummary } from '@/types/insurance-provider';

export type PatientInsuranceStatus = 'active' | 'inactive' | 'expired' | 'suspended';

export type NhifVerificationStatus = 'verified' | 'lookup_found' | 'verification_failed' | 'not_found' | 'invalid' | 'expired' | 'inactive' | 'error';
export type NhifIdentifierType = 'CardNo' | 'nin';
export type NhifEligibilityStatus = 'eligible' | 'ineligible' | 'expired' | 'suspended' | 'unknown' | 'error';

export interface NhifMemberVerification {
    id: number;
    patient_insurance_id: number;
    patient_id: number;
    identifier_type: NhifIdentifierType;
    identifier_value: string;
    nhif_operation: string;
    verification_status: NhifVerificationStatus;
    nhif_member_name: string | null;
    nhif_card_no: string | null;
    nhif_expiry_date: string | null;
    nhif_membership_no: string | null;
    nhif_plan_name: string | null;
    http_status: number | null;
    error_message: string | null;
    correlation_id: string | null;
    verified_at: string | null;
    created_at: string;
}
export type VerificationStatus = 'pending' | 'verified' | 'failed' | 'expired';
export type MembershipRelationship = 'self' | 'spouse' | 'child' | 'parent' | 'other';

export interface NhifEligibilityRecord {
    id: number;
    patient_insurance_id: number;
    patient_id: number;
    card_no: string;
    nhif_operation: string;
    eligibility_status: NhifEligibilityStatus;
    nhif_coverage_percent: string | null;
    nhif_patient_responsibility_percent: string | null;
    nhif_valid_from: string | null;
    nhif_valid_to: string | null;
    nhif_remarks: string | null;
    http_status: number | null;
    error_message: string | null;
    correlation_id: string | null;
    eligibility_checked_at: string | null;
    created_at: string;
}

export interface InsuranceSchemeSummary {
    id: number;
    insurance_provider_id: number;
    name: string;
    code: string;
}

export interface NhifCardType {
    cardTypeID?: string;
    CardTypeID?: string;
    cardTypeName?: string;
    CardTypeName?: string;
}

export interface NhifVerifier {
    verifierID?: string;
    VerifierID?: string;
    verifierName?: string;
    VerifierName?: string;
    cardTypes?: NhifCardType[];
    CardTypes?: NhifCardType[];
}

export interface NhifVisitType {
    visitTypeID?: number | string;
    VisitTypeID?: number | string;
    visitTypeName?: string;
    VisitTypeName?: string;
    RequiresRemarks?: boolean;
    requiresRemarks?: boolean;
    RequiresReferralNo?: boolean;
    requiresReferralNo?: boolean;
}

/**
 * The outcome states of an NHIF-first registration lookup (Phase 8) —
 * mirrors the UI states the registration form must represent while
 * fetching/showing a GetCardDetails / GetCardDetailsByNIN result.
 */
export type NhifLookupStatus = 'found' | 'not_found' | 'invalid_identifier' | 'api_error' | 'member_inactive' | 'duplicate_patient';

/**
 * Normalized NHIF member data returned by POST /patients/nhif-lookup. NHIF's
 * GetCardDetails/GetCardDetailsByNIN response includes name, gender, and
 * date of birth directly — only phone is not part of this response, so it
 * still needs to be collected manually.
 */
export interface NhifLookupMember {
    member_name: string | null;
    first_name: string | null;
    middle_name: string | null;
    last_name: string | null;
    gender: 'male' | 'female' | null;
    date_of_birth: string | null;
    membership_number: string | null;
    card_number: string | null;
    nin: string | null;
    plan_name: string | null;
    expiry_date: string | null;
    authorization_no: string | null;
    nhif_scheme_id: number | null;
}

export interface NhifSchemeService {
    item_code: string;
    item_name: string;
    item_type_id: string | null;
    type_name: string | null;
    unit_price: string | null;
}

export interface NhifSchemeServicesResponse {
    success: boolean;
    data: NhifSchemeService[];
}

export interface NhifLookupExistingPatient {
    id: number;
    patient_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
}

export interface NhifLookupResponse {
    success: boolean;
    status: NhifLookupStatus;
    message: string | null;
    member: NhifLookupMember | null;
    existing_patient: NhifLookupExistingPatient | null;
}

export interface PatientInsurancePatient {
    id: number;
    patient_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    national_id?: string | null;
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
    latest_nhif_verification?: NhifMemberVerification | null;
    latest_nhif_eligibility?: NhifEligibilityRecord | null;
    nhif_eligibility_status?: NhifEligibilityStatus | null;
    nhif_coverage_percent?: string | null;
    nhif_eligibility_checked_at?: string | null;
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
