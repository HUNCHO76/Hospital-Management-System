import { type ConsultationStatus } from '@/types/consultation';

export const consultationStatusLabel: Record<ConsultationStatus, string> = {
    pending: 'Pending',
    in_progress: 'In Progress',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

export const consultationStatusVariant: Record<ConsultationStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    pending: 'outline',
    in_progress: 'default',
    completed: 'secondary',
    cancelled: 'destructive',
};

/**
 * The single source of truth for which actions are valid from a given
 * status, mirroring Consultation::TRANSITIONS on the backend. This only
 * controls which buttons render — the backend re-validates independently
 * and is the actual authority.
 */
const NEXT_ACTIONS: Record<ConsultationStatus, string[]> = {
    pending: ['start', 'cancel'],
    in_progress: ['complete', 'cancel'],
    completed: [],
    cancelled: [],
};

export function allowedConsultationActions(status: ConsultationStatus): string[] {
    return NEXT_ACTIONS[status] ?? [];
}

/**
 * Field definitions for the consultation clinical-documentation form,
 * grouped for rendering (spec §28's ordering) — one source of truth shared
 * by the editable form and any label lookups.
 */
export const CONSULTATION_FIELDS: { key: string; label: string; rows: number }[] = [
    { key: 'chief_complaint', label: 'Chief Complaint', rows: 2 },
    { key: 'history_presenting_illness', label: 'History of Presenting Illness', rows: 4 },
    { key: 'relevant_medical_history', label: 'Relevant Medical History', rows: 2 },
    { key: 'relevant_surgical_history', label: 'Relevant Surgical History', rows: 2 },
    { key: 'relevant_social_history', label: 'Relevant Social History', rows: 2 },
    { key: 'review_of_systems', label: 'Review of Systems', rows: 3 },
    { key: 'physical_examination', label: 'Physical Examination', rows: 4 },
    { key: 'assessment_summary', label: 'Clinical Assessment', rows: 3 },
    { key: 'clinical_notes', label: 'Consultation Notes', rows: 3 },
    { key: 'plan_summary', label: 'Plan Summary', rows: 2 },
];
