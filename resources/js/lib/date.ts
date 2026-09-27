/** Formats an ISO date/datetime string as a locale date, or '—' when absent/invalid. */
export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return date.toLocaleDateString();
}

/** Calculates age in whole years from a date of birth, or '—' when absent/invalid. */
export function calculateAge(dateOfBirth: string | null | undefined): string {
    if (!dateOfBirth) {
        return '—';
    }

    const dob = new Date(dateOfBirth);

    if (Number.isNaN(dob.getTime())) {
        return '—';
    }

    const diff = Date.now() - dob.getTime();
    const age = Math.floor(diff / (1000 * 60 * 60 * 24 * 365.25));

    return `${age}y`;
}
