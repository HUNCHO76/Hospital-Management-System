import { type AdmissionStatus, type ClinicalStatus } from '@/types/admission';

export const admissionStatusLabel: Record<AdmissionStatus, string> = {
    pending: 'Pending Review',
    under_review: 'Under Review',
    approved: 'Approved',
    rejected: 'Rejected',
    active: 'Admitted',
    discharged: 'Discharged',
    cancelled: 'Cancelled',
    transferred: 'Transferred',
};

export const admissionStatusVariant: Record<AdmissionStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    pending: 'outline',
    under_review: 'secondary',
    approved: 'default',
    rejected: 'destructive',
    active: 'default',
    discharged: 'secondary',
    cancelled: 'destructive',
    transferred: 'secondary',
};

/**
 * The single source of truth for which actions are valid from a given
 * status. Mirrors AdmissionService's own status-guard checks on the backend.
 * Controls button visibility only — the backend re-validates independently.
 */
const NEXT_ACTIONS: Record<AdmissionStatus, string[]> = {
    pending: ['review', 'cancel'],
    under_review: ['approve', 'reject', 'return', 'cancel'],
    approved: ['confirm', 'cancel'],
    rejected: [],
    active: ['discharge'],
    discharged: [],
    cancelled: [],
    transferred: [],
};

export function allowedAdmissionActions(status: AdmissionStatus): string[] {
    return NEXT_ACTIONS[status] ?? [];
}

export const admissionTypeLabel: Record<string, string> = {
    emergency: 'Emergency',
    elective: 'Elective',
    referral: 'Referral',
    transfer: 'Transfer',
    other: 'Other',
};

export const dischargeTypeLabel: Record<string, string> = {
    routine: 'Routine',
    against_medical_advice: 'Against Medical Advice',
    transferred_out: 'Transferred Out',
    deceased: 'Deceased',
    other: 'Other',
};

export const clinicalStatusLabel: Record<ClinicalStatus, string> = {
    stable: 'Stable',
    under_observation: 'Under Observation',
    improving: 'Improving',
    deteriorating: 'Deteriorating',
    critical: 'Critical',
};

export const clinicalStatusVariant: Record<ClinicalStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    stable: 'default',
    under_observation: 'outline',
    improving: 'default',
    deteriorating: 'secondary',
    critical: 'destructive',
};;
