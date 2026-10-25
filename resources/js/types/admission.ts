import { type BloodGroup, type PatientGender } from './patient';
import { type PatientInsurance } from './patient-insurance';

export type AdmissionStatus = 'pending' | 'under_review' | 'approved' | 'rejected' | 'active' | 'discharged' | 'cancelled' | 'transferred';

export type ClinicalStatus = 'stable' | 'under_observation' | 'improving' | 'deteriorating' | 'critical';

export type AdmissionType = 'emergency' | 'elective' | 'referral' | 'transfer' | 'other';

export type DischargeType = 'routine' | 'against_medical_advice' | 'transferred_out' | 'deceased' | 'other';

export interface AdmissionPatient {
    id: number;
    patient_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    phone?: string | null;
    gender?: PatientGender | string | null;
    date_of_birth?: string | null;
    blood_group?: BloodGroup | string | null;
}

export interface AdmissionHospital {
    id: number;
    name: string;
}

export interface AdmissionDepartment {
    id: number;
    name: string;
    hospital_id?: number;
}

export interface AdmissionPractitioner {
    id: number;
    practitioner_number: string;
    staff_id: number;
    practitioner_type?: string | null;
    staff?: {
        first_name: string;
        last_name: string;
        job_title?: string | null;
        department_id?: number | null;
    };
}

export interface AdmissionService {
    id: number;
    code: string;
    name: string;
}

export interface AdmissionUser {
    id: number;
    UserName: string;
}

export interface NhifAdmissionRecord {
    id: number;
    nhif_status: string;
    nhif_admission_no: string | null;
    nhif_authorization_no?: string | null;
    nhif_facility_code?: string | null;
    nhif_practitioner_no?: string | null;
    nhif_discharge_type_id?: number | null;
    nhif_discharge_status?: string | null;
    nhif_discharge_error?: string | null;
    nhif_discharged_at?: string | null;
    retry_count?: number;
    error_message: string | null;
    submitted_at: string | null;
    resolved_at: string | null;
}

export interface Admission {
    id: number;
    admission_number: string;
    hospital_id?: number;
    patient_id?: number;
    department_id?: number | null;
    practitioner_id?: number | null;
    service_id?: number | null;
    admission_type: AdmissionType | null;
    ward_type: string | null;
    room_type: string | null;
    diagnosis_at_admission: string | null;
    charges_per_day: string | null;
    notes: string | null;
    status: AdmissionStatus;
    admitted_at: string | null;
    discharged_at: string | null;
    discharge_type: DischargeType | null;
    discharge_reason: string | null;
    diagnosis_at_discharge?: string | null;
    referred_to_facility_code?: string | null;
    cancellation_reason: string | null;
    patient?: AdmissionPatient;
    hospital?: AdmissionHospital;
    department?: AdmissionDepartment | null;
    practitioner?: AdmissionPractitioner | null;
    service?: AdmissionService | null;
    rejection_reason?: string | null;
    reviewed_by?: number | null;
    reviewed_at?: string | null;
    approved_by?: number | null;
    approved_at?: string | null;
    rejected_by?: number | null;
    rejected_at?: string | null;
    admittedBy?: AdmissionUser | null;
    dischargedBy?: AdmissionUser | null;
    createdBy?: AdmissionUser | null;
    reviewedBy?: AdmissionUser | null;
    approvedBy?: AdmissionUser | null;
    rejectedBy?: AdmissionUser | null;
    nhifAdmission?: NhifAdmissionRecord | null;
    patient_insurance_id?: number | null;
    patient_insurance?: PatientInsurance | null;
    patientInsurance?: PatientInsurance | null;
    // Phase 9, Step 74 — inpatient clinical status
    clinical_status?: ClinicalStatus | null;
    clinical_status_updated_at?: string | null;
    clinicalStatusUpdatedBy?: AdmissionUser | null;
    inpatient_encounters_count?: number;
    created_at: string;
    updated_at: string;
}

export interface AdmissionFormData {
    hospital_id: string;
    patient_id: string;
    department_id: string;
    practitioner_id: string;
    service_id: string;
    admission_type: AdmissionType | '';
    ward_type: string;
    room_type: string;
    diagnosis_at_admission: string;
    notes: string;
    nhif_authorization_no?: string;
    nhif_ward_type_id?: string;
    nhif_room_type_id?: string;
    nhif_admission_type_id?: string;
    clinical_encounter_id?: string;
    reception_visit_id?: string;
    patient_insurance_id?: string;
    [key: string]: string | undefined;
}

export interface DischargeFormData {
    discharge_type: DischargeType | '';
    discharge_reason: string;
    diagnosis_at_discharge?: string;
    referred_to_facility_code?: string;
    [key: string]: string | undefined;
}

export interface CancelAdmissionFormData {
    reason: string;
    [key: string]: string;
}

export interface ApproveAdmissionFormData {
    notes: string;
    [key: string]: string;
}

export interface RejectAdmissionFormData {
    reason: string;
    [key: string]: string;
}

export interface ReturnAdmissionFormData {
    notes: string;
    [key: string]: string;
}

export interface AdmissionFilters {
    status: string;
    department_id: string;
    hospital_id: string;
    patient_id?: string;
    admission_type: string;
}
