import { type ClinicalEncounterDisposition, type ClinicalEncounterStatus } from '@/types/clinical-encounter';

export const clinicalEncounterStatusLabel: Record<ClinicalEncounterStatus, string> = {
    open: 'Open',
    in_progress: 'In Progress',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

export const clinicalEncounterStatusVariant: Record<ClinicalEncounterStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    open: 'outline',
    in_progress: 'default',
    completed: 'secondary',
    cancelled: 'destructive',
};

/**
 * The single source of truth for which actions are valid from a given
 * status, mirroring ClinicalEncounter::TRANSITIONS on the backend. This only
 * controls which buttons render — the backend re-validates independently
 * and is the actual authority.
 */
const NEXT_ACTIONS: Record<ClinicalEncounterStatus, string[]> = {
    open: ['start', 'cancel'],
    in_progress: ['complete', 'cancel'],
    completed: [],
    cancelled: [],
};

export function allowedClinicalEncounterActions(status: ClinicalEncounterStatus): string[] {
    return NEXT_ACTIONS[status] ?? [];
}

export const clinicalEncounterDispositionLabel: Record<ClinicalEncounterDisposition, string> = {
    completed: 'Completed',
    follow_up: 'Follow-up Required',
    referred: 'Referred',
    admitted: 'Admitted',
    transferred: 'Transferred',
};
