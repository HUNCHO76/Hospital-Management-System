export type HospitalStatus = 'active' | 'inactive';

export interface HospitalConsultationServiceOption {
    id: number;
    code: string;
    name: string;
}

export interface Hospital {
    id: number;
    name: string;
    code: string;
    registration_number: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    region: string | null;
    district: string | null;
    ward: string | null;
    status: HospitalStatus;
    default_consultation_service_id: number | null;
    default_consultation_service?: HospitalConsultationServiceOption | null;
    departments_count?: number;
    created_at: string;
    updated_at: string;
}

export interface HospitalFormData {
    name: string;
    code: string;
    registration_number: string;
    phone: string;
    email: string;
    address: string;
    region: string;
    district: string;
    ward: string;
    status: HospitalStatus;
    default_consultation_service_id: string;
    [key: string]: string;
}

export interface HospitalFilters {
    search: string;
    status: string;
    region: string;
    district: string;
}
