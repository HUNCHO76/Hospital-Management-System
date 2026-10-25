export type OverrideEffect = 'allow' | 'deny' | 'inherit';

export interface PermissionItem {
    id: number;
    name: string;
    title: string;
    description: string;
    is_inherited: boolean;
    inherited_from_roles: string[];
    is_direct: boolean;
    override: 'allow' | 'deny' | null;
    override_reason: string | null;
    override_updated_at: string | null;
    is_effective: boolean;
}

export interface PermissionModule {
    module: string;
    permissions: PermissionItem[];
}

export interface PermissionGroup {
    group: string;
    modules: PermissionModule[];
}

export interface PermissionMatrixUser {
    id: number;
    username: string;
    email: string;
    status: string;
    roles: string[];
    staff: {
        id: number;
        staff_number: string;
        full_name: string;
        job_title?: string;
        department?: string;
        hospital?: string;
    } | null;
}

export interface PermissionMatrixCounts {
    total_system_permissions: number;
    inherited_count: number;
    overridden_allow_count: number;
    overridden_deny_count: number;
}

export interface PermissionMatrix {
    user: PermissionMatrixUser;
    permission_groups: PermissionGroup[];
    counts: PermissionMatrixCounts;
}

export interface OverridePayloadItem {
    permission_id: number;
    effect: OverrideEffect;
}
