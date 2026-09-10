import { type InsuranceProviderSummary } from '@/types/insurance-provider';

export type InsuranceSchemeStatus = 'active' | 'inactive';

export interface InsuranceScheme {
    id: number;
    insurance_provider_id: number;
    name: string;
    code: string;
    description: string | null;
    status: InsuranceSchemeStatus;
    insurance_provider?: InsuranceProviderSummary;
    created_at: string;
    updated_at: string;
}

export interface InsuranceSchemeFormData {
    insurance_provider_id: string;
    name: string;
    code: string;
    description: string;
    status: InsuranceSchemeStatus;
    [key: string]: string;
}

export interface InsuranceSchemeFilters {
    search: string;
    insurance_provider_id: string;
    status: string;
}
