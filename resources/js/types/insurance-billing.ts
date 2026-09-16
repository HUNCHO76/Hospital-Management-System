import { type ServiceCategory } from '@/types/service';

/**
 * PERCENTAGE: insurer pays a % of the eligible amount. FIXED_COPAY: patient
 * pays a flat amount, insurer pays the rest. FULL: insurer pays 100%.
 * NOT_COVERED: a deliberate, configured "this plan does not cover this"
 * rule (Phase 7, Step 56).
 */
export type CoverageType = 'percentage' | 'fixed_copay' | 'full' | 'not_covered';

export type InsuranceCoverageRuleStatus = 'active' | 'cancelled';

/**
 * FULLY_COVERED/PARTIALLY_COVERED/NOT_COVERED describe a matched (or
 * deliberately absent) coverage rule's outcome. REJECTED means the patient
 * had no valid coverage at all on the charge's own date — distinct from a
 * configured NOT_COVERED rule, even though the patient owes the full amount
 * either way.
 */
export type ChargeInsuranceAllocationStatus = 'fully_covered' | 'partially_covered' | 'not_covered' | 'rejected';

export interface InsuranceCoverageRuleSummary {
    id: number;
    coverage_type: CoverageType;
    requires_preauthorization: boolean;
}

export interface ChargeInsuranceAllocation {
    id: number;
    charge_id: number;
    coverage_type_applied: CoverageType | null;
    coverage_percentage_applied: string | null;
    copay_amount_applied: string | null;
    eligible_amount: string;
    covered_amount: string;
    patient_responsibility: string;
    currency: string;
    status: ChargeInsuranceAllocationStatus;
    rejection_reason: string | null;
    insurance_coverage_rule: InsuranceCoverageRuleSummary | null;
    calculated_at: string;
    calculated_by: { id: number; name: string } | null;
}

export interface CoverageEligibilityInfo {
    id: number;
    insurance_provider_id: number;
    insurance_scheme_id: number | null;
    membership_number: string | null;
    status: string;
    verification_status: string;
    effective_from: string | null;
    effective_to: string | null;
}

export interface CoverageEligibility {
    eligible: boolean;
    reason: string | null;
    coverage: CoverageEligibilityInfo | null;
}

export interface InsuranceCoverageRule {
    id: number;
    insurance_scheme: { id: number; name: string; code: string } | null;
    service: { id: number; code: string; name: string } | null;
    category: ServiceCategory | null;
    coverage_type: CoverageType;
    coverage_percentage: string | null;
    fixed_copay_amount: string | null;
    requires_preauthorization: boolean;
    status: InsuranceCoverageRuleStatus;
    effective_from: string;
    effective_to: string | null;
    cancellation_reason: string | null;
    created_by: { id: number; name: string } | null;
    created_at: string;
}

export interface InsuranceCoverageRuleFilters {
    insurance_scheme_id: string;
    status: string;
}

export type CreateInsuranceCoverageRuleFormData = {
    insurance_scheme_id: string;
    service_id: string;
    category: string;
    coverage_type: CoverageType | '';
    coverage_percentage: string;
    fixed_copay_amount: string;
    requires_preauthorization: boolean;
    effective_from: string;
    effective_to: string;
    [key: string]: string | boolean;
};

export type CancelInsuranceCoverageRuleFormData = {
    reason: string;
    [key: string]: string;
};
