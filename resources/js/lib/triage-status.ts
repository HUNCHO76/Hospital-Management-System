import { type TriageCategory, type TriageStatus, type VitalSignFlag } from '@/types/triage';

export const triageStatusLabel: Record<TriageStatus, string> = {
    pending: 'Pending',
    in_progress: 'In Progress',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

export const triageStatusVariant: Record<TriageStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    pending: 'outline',
    in_progress: 'default',
    completed: 'secondary',
    cancelled: 'destructive',
};

/**
 * Most urgent first — matches TriageAssessment::CATEGORIES on the backend.
 */
export const triageCategoryLabel: Record<TriageCategory, string> = {
    emergency: 'Emergency',
    very_urgent: 'Very Urgent',
    urgent: 'Urgent',
    non_urgent: 'Non-Urgent',
};

/**
 * Colors reserve red for the most urgent category only — never used to
 * imply a diagnosis, only relative priority for staff scanning a list.
 */
export const triageCategoryColor: Record<TriageCategory, string> = {
    emergency: '#dc2626',
    very_urgent: '#d97706',
    urgent: '#2563eb',
    non_urgent: '#16a34a',
};

/**
 * The single source of truth for which actions are valid from a given
 * status, mirroring TriageAssessment::TRANSITIONS on the backend. This only
 * controls which buttons render — the backend re-validates independently
 * and is the actual authority.
 */
const NEXT_ACTIONS: Record<TriageStatus, string[]> = {
    pending: ['start', 'cancel'],
    in_progress: ['complete', 'cancel'],
    completed: [],
    cancelled: [],
};

export function allowedTriageActions(status: TriageStatus): string[] {
    return NEXT_ACTIONS[status] ?? [];
}

export const vitalSignFlagLabel: Record<VitalSignFlag, string> = {
    normal: 'Normal',
    low: 'Low',
    high: 'High',
    critical: 'Critical',
};

/**
 * Text color classes only (not a full Badge variant) — flags render as an
 * inline label next to the value, not a separate badge, so the vitals
 * history table stays scannable.
 */
export const vitalSignFlagClassName: Record<VitalSignFlag, string> = {
    normal: 'text-muted-foreground',
    low: 'text-amber-600 dark:text-amber-500 font-medium',
    high: 'text-amber-600 dark:text-amber-500 font-medium',
    critical: 'text-destructive font-semibold',
};
