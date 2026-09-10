export type PractitionerStatus = 'active' | 'inactive' | 'suspended';

export interface PractitionerStaffHospital {
    id: number;
    name: string;
}

export interface PractitionerStaffDepartment {
    id: number;
    name: string;
}

export interface PractitionerStaff {
    id: number;
    staff_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    job_title?: string;
    hospital?: PractitionerStaffHospital;
    department?: PractitionerStaffDepartment | null;
}

export interface Practitioner {
    id: number;
    staff_id: number;
    practitioner_number: string;
    practitioner_type: string;
    professional_registration_number: string | null;
    specialization: string | null;
    license_number: string | null;
    license_expiry_date: string | null;
    status: PractitionerStatus;
    staff?: PractitionerStaff;
    created_at: string;
    updated_at: string;
}

export interface PractitionerFormData {
    staff_id: string;
    practitioner_type: string;
    professional_registration_number: string;
    specialization: string;
    license_number: string;
    license_expiry_date: string;
    status: PractitionerStatus;
    [key: string]: string;
}

export interface PractitionerFilters {
    search: string;
    hospital_id: string;
    department_id: string;
    practitioner_type: string;
    status: string;
    specialization: string;
}
