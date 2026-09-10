import { type DiagnosisStatus, type DiagnosisType } from '@/types/diagnosis';

export const diagnosisStatusLabel: Record<DiagnosisStatus, string> = {
    provisional: 'Provisional',
    confirmed: 'Confirmed',
    resolved: 'Resolved',
    cancelled: 'Cancelled',
};

export const diagnosisStatusVariant: Record<DiagnosisStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    provisional: 'outline',
    confirmed: 'default',
    resolved: 'secondary',
    cancelled: 'destructive',
};

export const diagnosisTypeLabel: Record<DiagnosisType, string> = {
    primary: 'Primary',
    secondary: 'Secondary',
};

/**
 * The single source of truth for which status actions are valid from a
 * given status, mirroring EncounterDiagnosis::TRANSITIONS on the backend.
 * Cancellation is offered separately (see allowedDiagnosisActions) since the
 * backend treats it as its own action, not part of this transition map.
 */
const NEXT_STATUSES: Record<DiagnosisStatus, string[]> = {
    provisional: ['confirmed'],
    confirmed: ['resolved'],
    resolved: [],
    cancelled: [],
};

export function allowedDiagnosisStatusActions(status: DiagnosisStatus): string[] {
    return NEXT_STATUSES[status] ?? [];
}

/**
 * Whether the diagnosis can still be cancelled from its current status.
 */
export function canCancelDiagnosis(status: DiagnosisStatus): boolean {
    return status === 'provisional' || status === 'confirmed';
}

/**
 * Whether the diagnosis is eligible for the controlled correction workflow.
 */
export function canCorrectDiagnosis(status: DiagnosisStatus): boolean {
    return status === 'confirmed' || status === 'resolved';
}

/**
 * Whether the diagnosis is still a mutable draft (plain edits allowed).
 */
export function isDiagnosisDraft(status: DiagnosisStatus): boolean {
    return status === 'provisional';
}

/**
 * Whether the diagnosis still counts toward the one-active-primary rule.
 */
export function isDiagnosisActive(status: DiagnosisStatus): boolean {
    return status === 'provisional' || status === 'confirmed';
}
