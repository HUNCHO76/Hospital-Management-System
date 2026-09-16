import { type DispensingItemStatus, type DispensingStatus, type PrescriptionFulfillmentStatus } from '@/types/dispensing';

export const dispensingStatusLabel: Record<DispensingStatus, string> = {
    in_progress: 'In Progress',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

export const dispensingStatusVariant: Record<DispensingStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    in_progress: 'outline',
    completed: 'default',
    cancelled: 'destructive',
};

export const dispensingItemStatusLabel: Record<DispensingItemStatus, string> = {
    dispensed: 'Dispensed',
    unavailable: 'Unavailable',
};

export const fulfillmentStatusLabel: Record<PrescriptionFulfillmentStatus, string> = {
    pending: 'Awaiting Dispensing',
    partially_dispensed: 'Partially Dispensed',
    dispensed: 'Dispensed',
};

export const fulfillmentStatusVariant: Record<PrescriptionFulfillmentStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    pending: 'outline',
    partially_dispensed: 'secondary',
    dispensed: 'default',
};
