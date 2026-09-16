export type ServiceStatus = 'active' | 'inactive';

export type ServiceCategory =
    | 'consultation'
    | 'laboratory'
    | 'radiology'
    | 'pharmacy'
    | 'procedure'
    | 'emergency'
    | 'dental'
    | 'maternity'
    | 'inpatient'
    | 'other';

export type ServicePriceStatus = 'active' | 'cancelled';

export interface ServiceUser {
    id: number;
    name: string;
}

export interface ServiceHospital {
    id: number;
    name: string;
}

export interface Service {
    id: number;
    code: string;
    name: string;
    description: string | null;
    category: ServiceCategory;
    is_billable: boolean;
    status: ServiceStatus;
    serviceable_type: string | null;
    serviceable_id: number | null;
    is_referenced: boolean;
    created_by: ServiceUser | null;
    created_at: string;
}

export interface ServicePrice {
    id: number;
    amount: string;
    currency: string;
    effective_from: string;
    effective_to: string | null;
    status: ServicePriceStatus;
    is_current: boolean;
    service: { id: number; code: string; name: string } | null;
    hospital: ServiceHospital | null;
    created_by: ServiceUser | null;
    cancellation_reason: string | null;
    created_at: string;
}

export interface ServiceFilters {
    search: string;
    category: string;
    status: string;
    is_billable: string;
}

export interface ServicePriceFilters {
    search: string;
    service_id: string;
    hospital_id: string;
    status: string;
}

export type CreateServiceFormData = {
    code: string;
    name: string;
    description: string;
    category: ServiceCategory | '';
    is_billable: boolean;
    [key: string]: string | boolean;
};

export type UpdateServiceFormData = {
    code: string;
    name: string;
    description: string;
    category: ServiceCategory | '';
    is_billable: boolean;
    [key: string]: string | boolean;
};

export type CreateServicePriceFormData = {
    service_id: string;
    hospital_id: string;
    amount: string;
    effective_from: string;
    effective_to: string;
    [key: string]: string;
};

export type CancelServicePriceFormData = {
    reason: string;
    [key: string]: string;
};
