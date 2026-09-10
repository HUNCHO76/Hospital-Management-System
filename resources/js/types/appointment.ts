export type AppointmentStatus = 'scheduled' | 'confirmed' | 'checked_in' | 'in_progress' | 'completed' | 'cancelled' | 'no_show';

export type AppointmentType = 'initial_consultation' | 'follow_up' | 'review' | 'procedure' | 'specialist_consultation' | 'other';

export interface AppointmentPatient {
    id: number;
    patient_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    phone?: string | null;
}

export interface AppointmentDepartment {
    id: number;
    name: string;
    code: string;
}

export interface AppointmentPractitionerStaff {
    id: number;
    first_name: string;
    last_name: string;
    department_id?: number | null;
    hospital_id?: number;
}

export interface AppointmentPractitioner {
    id: number;
    staff_id?: number;
    practitioner_number: string;
    practitioner_type?: string;
    staff?: AppointmentPractitionerStaff;
}

export interface Appointment {
    id: number;
    appointment_number: string;
    patient_id?: number;
    department_id?: number;
    practitioner_id?: number;
    patient?: AppointmentPatient;
    department?: AppointmentDepartment;
    practitioner?: AppointmentPractitioner;
    appointment_type: AppointmentType;
    scheduled_start: string;
    scheduled_end: string;
    status: AppointmentStatus;
    reason: string | null;
    notes: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    created_at: string;
    updated_at: string;
}

export interface AppointmentBookingFormData {
    patient_id: string;
    department_id: string;
    practitioner_id: string;
    appointment_type: AppointmentType | '';
    scheduled_start: string;
    scheduled_end: string;
    reason: string;
    notes: string;
    [key: string]: string;
}

export interface AppointmentEditFormData {
    appointment_type: AppointmentType | '';
    reason: string;
    notes: string;
    [key: string]: string;
}

export interface AppointmentRescheduleFormData {
    scheduled_start: string;
    scheduled_end: string;
    [key: string]: string;
}

export interface AppointmentFilters {
    search: string;
    department_id: string;
    practitioner_id: string;
    status: string;
    date_from: string;
    date_to: string;
    sort: string;
}
