import { type CarePlanItemPriority, type CarePlanItemStatus, type CarePlanItemType, type CarePlanStatus } from '@/types/care-plan';

export const carePlanStatusLabel: Record<CarePlanStatus, string> = {
    draft: 'Draft',
    active: 'Active',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

export const carePlanStatusVariant: Record<CarePlanStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    draft: 'outline',
    active: 'default',
    completed: 'secondary',
    cancelled: 'destructive',
};

export const carePlanItemStatusLabel: Record<CarePlanItemStatus, string> = {
    planned: 'Planned',
    active: 'Active',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

export const carePlanItemStatusVariant: Record<CarePlanItemStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    planned: 'outline',
    active: 'default',
    completed: 'secondary',
    cancelled: 'destructive',
};

export const carePlanItemTypeLabel: Record<CarePlanItemType, string> = {
    medication: 'Medication',
    procedure: 'Procedure',
    clinical_intervention: 'Clinical Intervention',
    referral: 'Referral',
    monitoring: 'Monitoring',
    follow_up: 'Follow-up',
    radiology: 'Radiology Order',
    other: 'Other',
};

export const carePlanItemPriorityLabel: Record<CarePlanItemPriority, string> = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
};

/**
 * The single source of truth for which status actions are valid from a
 * given status, mirroring CarePlan::TRANSITIONS on the backend. Cancellation
 * is offered separately since the backend treats it as its own action.
 */
const NEXT_CARE_PLAN_STATUSES: Record<CarePlanStatus, string[]> = {
    draft: ['active'],
    active: ['completed'],
    completed: [],
    cancelled: [],
};

export function allowedCarePlanStatusActions(status: CarePlanStatus): string[] {
    return NEXT_CARE_PLAN_STATUSES[status] ?? [];
}

const NEXT_ITEM_STATUSES: Record<CarePlanItemStatus, string[]> = {
    planned: ['active'],
    active: ['completed'],
    completed: [],
    cancelled: [],
};

export function allowedCarePlanItemStatusActions(status: CarePlanItemStatus): string[] {
    return NEXT_ITEM_STATUSES[status] ?? [];
}

export function canCancelCarePlan(status: CarePlanStatus): boolean {
    return status === 'draft' || status === 'active';
}

export function canCorrectCarePlan(status: CarePlanStatus): boolean {
    return status === 'completed';
}

export function isCarePlanMutable(status: CarePlanStatus): boolean {
    return status === 'draft' || status === 'active';
}

export function canCancelCarePlanItem(status: CarePlanItemStatus): boolean {
    return status === 'planned' || status === 'active';
}

export function canCorrectCarePlanItem(status: CarePlanItemStatus): boolean {
    return status === 'completed';
}

export function isCarePlanItemEditable(status: CarePlanItemStatus): boolean {
    return status === 'planned';
}
