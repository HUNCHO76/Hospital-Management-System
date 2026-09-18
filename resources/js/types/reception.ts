import { type AppointmentStatus, type AppointmentType } from '@/types/appointment';

export type ReceptionVisitStatus = 'checked_in' | 'waiting' | 'ready_for_service' | 'sent_to_service' | 'completed' | 'cancelled';

export type ReceptionVisitType = 'appointment' | 'walk_in' | 'follow_up' | 'other';

export type InsuranceIndicator = 'active' | 'expired' | 'pending_verification' | 'not_verified' | 'none';

export interface ReceptionVisitPatient {
    id: number;
    patient_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    phone?: string | null;
    date_of_birth?: string | null;
    gender?: string;
    status?: string;
}

export interface ReceptionVisitDepartment {
    id: number;
    name: string;
    code?: string;
    hospital_id?: number;
}

export interface ReceptionVisitAppointment {
    id: number;
    appointment_number: string;
    appointment_type?: AppointmentType;
    scheduled_start?: string;
    status?: AppointmentStatus;
    practitioner?: {
        practitioner_number: string;
        staff?: { first_name: string; last_name: string };
    };
}

export type ConsultationFinancialStatus = 'authorized' | 'payment_required';

export interface ReceptionPractitioner {
    id: number;
    practitioner_number: string;
    staff_id: number;
    consultation_service_id: number | null;
    staff?: { first_name: string; last_name: string };
}

export interface ConsultationCharge {
    id: number;
    charge_number: string;
    status: string;
    unit_price: number;
    quantity: number;
    net_amount: number;
    currency: string;
}

export interface ReceptionVisit {
    id: number;
    visit_number: string;
    patient_id?: number;
    appointment_id?: number | null;
    department_id?: number;
    practitioner_id?: number | null;
    visit_type: ReceptionVisitType;
    status: ReceptionVisitStatus;
    completed_at: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    patient?: ReceptionVisitPatient;
    department?: ReceptionVisitDepartment;
    appointment?: ReceptionVisitAppointment | null;
    practitioner?: ReceptionPractitioner | null;
    financial_status?: ConsultationFinancialStatus;
    patient_account_id?: number | null;
    created_at: string;
    updated_at: string;
}

/**
 * A row in the dashboard's "today's appointments" table — a flattened
 * projection built server-side by ReceptionVisitController::dashboard(),
 * not the raw Appointment resource.
 */
export interface TodaysAppointmentRow {
    id: number;
    appointment_number: string;
    appointment_type: AppointmentType;
    scheduled_start: string;
    status: AppointmentStatus;
    patient: ReceptionVisitPatient | null;
    department: ReceptionVisitDepartment | null;
    practitioner: { first_name: string; last_name: string } | null;
    reception_visit: { id: number; visit_number: string; status: ReceptionVisitStatus } | null;
    insurance_indicator: InsuranceIndicator;
}

export interface ReceptionDashboardSummary {
    total_appointments: number;
    no_show: number;
    checked_in: number;
    waiting: number;
    ready_for_service: number;
    sent_to_service: number;
    completed: number;
    cancelled: number;
}

export interface WalkInFormData {
    patient_id: string;
    department_id: string;
    visit_type: ReceptionVisitType | '';
    practitioner_id: string;
    [key: string]: string;
}

export interface ReceptionVisitFilters {
    date: string;
    department_id: string;
    status: string;
    visit_type: string;
    patient_id?: string;
    search: string;
}
