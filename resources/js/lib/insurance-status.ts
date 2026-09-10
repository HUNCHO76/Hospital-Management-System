import { type PatientInsuranceStatus } from '@/types/patient-insurance';

/**
 * The status to actually display for a patient insurance record. The stored
 * `status` column is never silently rewritten just because a date passed —
 * this only affects how an "active" record with a lapsed effective_to date
 * is presented to the user.
 */
export function effectiveInsuranceStatus(status: PatientInsuranceStatus, effectiveTo: string | null): PatientInsuranceStatus {
    if (status === 'active' && effectiveTo && new Date(effectiveTo).getTime() < Date.now()) {
        return 'expired';
    }

    return status;
}

export const insuranceStatusLabel: Record<PatientInsuranceStatus, string> = {
    active: 'Active',
    inactive: 'Inactive',
    expired: 'Expired',
    suspended: 'Suspended',
};
