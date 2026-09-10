export type LicenseStatus = 'valid' | 'expiring-soon' | 'expired' | 'unknown';

const EXPIRING_SOON_WINDOW_DAYS = 60;

/**
 * Classify a practitioner's license expiry date for display. This is a
 * presentational hint only — the system never auto-deactivates a
 * practitioner based on license expiry.
 */
export function licenseStatus(expiryDate: string | null): LicenseStatus {
    if (!expiryDate) {
        return 'unknown';
    }

    const daysRemaining = (new Date(expiryDate).getTime() - Date.now()) / (1000 * 60 * 60 * 24);

    if (daysRemaining < 0) {
        return 'expired';
    }

    if (daysRemaining <= EXPIRING_SOON_WINDOW_DAYS) {
        return 'expiring-soon';
    }

    return 'valid';
}

export const licenseStatusLabel: Record<LicenseStatus, string> = {
    valid: 'Valid',
    'expiring-soon': 'Expiring soon',
    expired: 'Expired',
    unknown: 'No license on file',
};
