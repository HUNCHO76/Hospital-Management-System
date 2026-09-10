import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * Frontend permission/role helpers, sourced from the `auth` data Laravel
 * shares via Inertia. These are for UI visibility only (showing/hiding
 * buttons and navigation) — Laravel still enforces authorization on every
 * request, so nothing here should be treated as a security boundary.
 */
export function usePermissions() {
    const { auth } = usePage<SharedData>().props;

    const can = (permission: string): boolean => auth.permissions.includes(permission);

    const hasRole = (role: string): boolean => auth.roles.includes(role);

    const hasAnyRole = (roles: string[]): boolean => roles.some((role) => auth.roles.includes(role));

    return { can, hasRole, hasAnyRole, roles: auth.roles, permissions: auth.permissions };
}
