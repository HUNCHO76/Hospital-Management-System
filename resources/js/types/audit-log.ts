export interface AuditLogUser {
    id: number;
    name: string;
    email: string;
}

export interface AuditLogListItem {
    id: number;
    action: string;
    module: string;
    record_id: number | null;
    ip_address: string | null;
    created_at: string;
    user: AuditLogUser | null;
}

export interface AuditLog {
    id: number;
    action: string;
    module: string;
    auditable_type: string | null;
    auditable_id: number | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    ip_address: string | null;
    user_agent: string | null;
    url: string | null;
    created_at: string;
    user: AuditLogUser | null;
}

export interface AuditLogFilters {
    search: string;
    action: string;
    module: string;
    user_id: string;
    date_from: string;
    date_to: string;
}

export interface AuditLogFilterOptions {
    actions: string[];
    modules: string[];
}
