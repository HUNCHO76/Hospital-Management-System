export type InsuranceProviderStatus = 'active' | 'inactive';
export type InsuranceProviderType = 'government' | 'private' | 'corporate' | 'other';

export interface InsuranceProviderSummary {
    id: number;
    name: string;
    code: string;
    api_enabled?: boolean;
}

export interface InsuranceProvider {
    id: number;
    name: string;
    code: string;
    registration_number: string | null;
    type: InsuranceProviderType;
    phone: string | null;
    email: string | null;
    address: string | null;
    api_enabled: boolean;
    status: InsuranceProviderStatus;
    schemes_count?: number;
    created_at: string;
    updated_at: string;
}

export interface InsuranceProviderFormData {
    name: string;
    code: string;
    registration_number: string;
    type: InsuranceProviderType | '';
    phone: string;
    email: string;
    address: string;
    api_enabled: boolean;
    status: InsuranceProviderStatus;
    [key: string]: string | boolean;
}

export interface InsuranceProviderFilters {
    search: string;
    type: string;
    status: string;
}
