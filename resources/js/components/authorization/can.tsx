import { usePermissions } from '@/hooks/use-permissions';
import { type ReactNode } from 'react';

interface CanProps {
    permission: string;
    children: ReactNode;
    fallback?: ReactNode;
}

/**
 * Renders `children` only when the authenticated user has the given
 * permission. UI convenience only — the backend is the real gatekeeper.
 */
export function Can({ permission, children, fallback = null }: CanProps) {
    const { can } = usePermissions();

    return can(permission) ? <>{children}</> : <>{fallback}</>;
}
