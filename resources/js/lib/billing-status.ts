import { type ChargeStatus, type InvoiceStatus, type PatientAccountStatus } from '@/types/billing';

export const patientAccountStatusLabel: Record<PatientAccountStatus, string> = {
    active: 'Active',
    suspended: 'Suspended',
    closed: 'Closed',
};

export const patientAccountStatusVariant: Record<PatientAccountStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    active: 'default',
    suspended: 'secondary',
    closed: 'outline',
};

export const chargeStatusLabel: Record<ChargeStatus, string> = {
    pending: 'Pending',
    posted: 'Posted',
    voided: 'Voided',
    cancelled: 'Cancelled',
};

export const chargeStatusVariant: Record<ChargeStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    pending: 'outline',
    posted: 'default',
    voided: 'secondary',
    cancelled: 'destructive',
};

export const invoiceStatusLabel: Record<InvoiceStatus, string> = {
    draft: 'Draft',
    posted: 'Posted',
    voided: 'Voided',
    cancelled: 'Cancelled',
};

export const invoiceStatusVariant: Record<InvoiceStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    draft: 'outline',
    posted: 'default',
    voided: 'secondary',
    cancelled: 'destructive',
};

/**
 * Formats a decimal-string money amount (as returned by the backend, never
 * a JS float computation) with the account/charge's own currency code.
 */
export function formatMoney(amount: string | number, currency: string): string {
    const value = typeof amount === 'string' ? Number(amount) : amount;

    return `${currency} ${value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}
