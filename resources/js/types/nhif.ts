export interface NhifServiceMapping {
    id: number;
    service_id: number | null;
    hospital_id: number | null;
    nhif_item_code: string;
    nhif_item_name: string;
    nhif_package_code: string | null;
    nhif_package_name: string | null;
    coverage_status: 'covered' | 'partially_covered' | 'excluded' | 'not_mapped' | 'unknown';
    requires_preauth: boolean;
    effective_from: string | null;
    effective_to: string | null;
    is_active: boolean;
    mapping_source: 'manual' | 'sync';
    notes: string | null;
    current_allowable_amount: number | null;
    service?: { id: number; name: string; code: string };
    hospital?: { id: number; name: string; code: string };
    created_at: string;
    updated_at: string;
}

export interface NhifService {
    id: number;
    nhif_item_code: string;
    nhif_item_name: string;
    nhif_item_type_id: string | null;
    nhif_item_type_name: string | null;
    is_active: boolean;
    synced_at: string | null;
}

export interface NhifPackage {
    id: number;
    nhif_package_code: string;
    nhif_package_name: string;
    scheme_id: string | null;
    scheme_name: string | null;
    is_active: boolean;
    synced_at: string | null;
}

export interface NhifPriceSyncLog {
    id: number;
    hospital_id: number | null;
    facility_code: string | null;
    sync_type: string;
    status: 'started' | 'completed' | 'failed' | 'partial';
    total_records: number | null;
    new_records: number | null;
    updated_records: number | null;
    error_message: string | null;
    started_at: string;
    completed_at: string | null;
}

export interface NhifUtilization {
    id: number;
    utilization_number: string;
    module: string;
    status: 'pending' | 'submitted' | 'accepted' | 'rejected' | 'reconciled' | 'failed' | 'excluded';
    nhif_item_code: string;
    nhif_item_name: string;
    quantity: number;
    hms_amount: number | null;
    nhif_approved_amount: number | null;
    service_date: string | null;
    error_message: string | null;
}

export interface NhifDashboardStats {
    pending_verifications: number;
    pending_eligibility_checks: number;
    pending_preauthorizations: number;
    pending_utilizations: number;
    draft_claims: number;
    submitted_claims: number;
    open_discrepancies: number;
    last_sync_at: string | null;
    today_verifications: number;
    today_eligibility_checks: number;
}
