export type PractitionerStatus = 'active' | 'inactive' | 'suspended';

export type NhifAttendanceOperation = 'login' | 'logout';
export type NhifAttendanceStatus = 'success' | 'failed' | 'rejected' | 'already_logged_in' | 'error';

export interface NhifPractitionerAttendance {
    id: number;
    practitioner_id: number;
    nhif_practitioner_no: string;
    operation: NhifAttendanceOperation;
    attendance_status: NhifAttendanceStatus;
    nhif_response_code: string | null;
    nhif_message: string | null;
    http_status: number | null;
    error_message: string | null;
    correlation_id: string | null;
    attendance_at: string | null;
    created_at: string;
}

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
    nhif_practitioner_no: string | null;
    staff?: PractitionerStaff;
    latest_nhif_attendance?: NhifPractitionerAttendance | null;
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
    nhif_practitioner_no: string;
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
