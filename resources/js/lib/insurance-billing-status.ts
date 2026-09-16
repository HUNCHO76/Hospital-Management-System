import {
    type ChargeInsuranceAllocationStatus,
    type CoverageType,
    type InsuranceCoverageRuleStatus,
} from '@/types/insurance-billing';

export const coverageTypeLabel: Record<CoverageType, string> = {
    percentage: 'Percentage',
    fixed_copay: 'Fixed Copay',
    full: 'Full Coverage',
    not_covered: 'Not Covered',
};

export const insuranceCoverageRuleStatusLabel: Record<InsuranceCoverageRuleStatus, string> = {
    active: 'Active',
    cancelled: 'Cancelled',
};

export const insuranceCoverageRuleStatusVariant: Record<InsuranceCoverageRuleStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    active: 'default',
    cancelled: 'destructive',
};

export const allocationStatusLabel: Record<ChargeInsuranceAllocationStatus, string> = {
    fully_covered: 'Fully Covered',
    partially_covered: 'Partially Covered',
    not_covered: 'Not Covered',
    rejected: 'Not Eligible',
};

export const allocationStatusVariant: Record<ChargeInsuranceAllocationStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    fully_covered: 'default',
    partially_covered: 'secondary',
    not_covered: 'destructive',
    rejected: 'destructive',
};
