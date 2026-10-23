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
    skipped_records: number | null;
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

export interface NhifPreApprovalSession {
    id: number;
    status: string;
    nhif_authorization_no: string;
    nhif_request_no: string | null;
    nhif_request_id: string | null;
    created_at: string;
    items: NhifPreApprovalSessionItem[];
}

export interface NhifPreApprovalSessionItem {
    id: number;
    nhif_item_code: string;
    nhif_item_name: string | null;
    source_type: 'lab' | 'medication' | 'radiology' | 'consultation';
    source_id: number | null;
    quantity: number;
    unit_price: number;
    approval_status: string;
    nhif_approval_ref_no: string | null;
    rejection_reason: string | null;
}

export interface NhifPendingItem {
    source_type: 'lab' | 'medication' | 'radiology';
    source_id: number;
    nhif_item_code: string;
    nhif_item_name: string;
    quantity: number;
    unit_price: number | null;
    needs_mapping?: boolean;
    medication_id?: number;
    radiology_procedure_id?: number;
}

export interface NhifPendingItems {
    lab_items: NhifPendingItem[];
    medication_items: NhifPendingItem[];
    radiology_items: NhifPendingItem[];
}

export interface NhifInsuranceSummary {
    id: number;
    card_number: string;
    nhif_authorization_no: string;
    nhif_scheme_id: number | null;
    nhif_scheme_name: string | null;
    nhif_facility_code: string | null;
    nhif_pf_number: string | null;
    is_authorization_expired?: boolean;
    has_valid_authorization?: boolean;
    authorization_hours_remaining?: number | null;
    authorization_expires_at?: string | null;
}
