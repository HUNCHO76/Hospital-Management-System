import { type AppointmentStatus } from '@/types/appointment';

export const appointmentStatusLabel: Record<AppointmentStatus, string> = {
    scheduled: 'Scheduled',
    confirmed: 'Confirmed',
    checked_in: 'Checked In',
    in_progress: 'In Progress',
    completed: 'Completed',
    cancelled: 'Cancelled',
    no_show: 'No Show',
};

export const appointmentStatusVariant: Record<AppointmentStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    scheduled: 'outline',
    confirmed: 'secondary',
    checked_in: 'secondary',
    in_progress: 'default',
    completed: 'default',
    cancelled: 'destructive',
    no_show: 'destructive',
};

/**
 * Color used for calendar events — kept separate from the Badge variant
 * above since the calendar renders its own colored blocks rather than
 * shadcn Badge components.
 */
export const appointmentStatusColor: Record<AppointmentStatus, string> = {
    scheduled: '#64748b',
    confirmed: '#2563eb',
    checked_in: '#7c3aed',
    in_progress: '#d97706',
    completed: '#16a34a',
    cancelled: '#dc2626',
    no_show: '#b91c1c',
};

/**
 * The single source of truth for which actions are valid from a given
 * status, mirroring Appointment::TRANSITIONS on the backend. This only
 * controls which buttons render — the backend re-validates independently
 * and is the actual authority.
 */
const NEXT_ACTIONS: Record<AppointmentStatus, string[]> = {
    scheduled: ['confirm', 'checkIn', 'cancel', 'noShow'],
    confirmed: ['checkIn', 'cancel', 'noShow'],
    checked_in: ['start', 'cancel'],
    in_progress: ['complete', 'cancel'],
    completed: [],
    cancelled: [],
    no_show: [],
};

export function allowedAppointmentActions(status: AppointmentStatus): string[] {
    return NEXT_ACTIONS[status] ?? [];
}
