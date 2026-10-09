import { type InsuranceIndicator, type ReceptionVisitStatus } from '@/types/reception';

export const receptionStatusLabel: Record<ReceptionVisitStatus, string> = {
    checked_in: 'Checked In',
    waiting: 'Waiting',
    ready_for_service: 'Ready',
    sent_to_service: 'Sent to Service',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

export const receptionStatusVariant: Record<ReceptionVisitStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    checked_in: 'outline',
    waiting: 'secondary',
    ready_for_service: 'secondary',
    sent_to_service: 'default',
    completed: 'default',
    cancelled: 'destructive',
};

/**
 * The single source of truth for which actions are valid from a given
 * status, mirroring ReceptionVisit::TRANSITIONS on the backend. This only
 * controls which buttons render — the backend re-validates independently.
 */
const NEXT_ACTIONS: Record<ReceptionVisitStatus, string[]> = {
    checked_in: ['waiting', 'cancel'],
    waiting: ['ready', 'cancel'],
    ready_for_service: ['sendToService', 'complete', 'cancel'],
    sent_to_service: ['complete', 'cancel'],
    completed: [],
    cancelled: [],
};

export function allowedReceptionActions(status: ReceptionVisitStatus): string[] {
    return NEXT_ACTIONS[status] ?? [];
}

export const insuranceIndicatorLabel: Record<InsuranceIndicator, string> = {
    active: 'Active',
    expired: 'Expired',
    pending_verification: 'Pending Verification',
    not_verified: 'Not Verified',
    none: 'No Active Coverage',
};

export const insuranceIndicatorVariant: Record<InsuranceIndicator, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    active: 'default',
    expired: 'destructive',
    pending_verification: 'secondary',
    not_verified: 'outline',
    none: 'outline',
};
