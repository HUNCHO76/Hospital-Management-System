export type ProcedureType = 'bedside_procedure' | 'minor_theatre' | 'major_theatre' | 'endoscopy' | 'other';
export type ProcedureUrgency = 'elective' | 'urgent' | 'emergency';
export type ProcedureOrderStatus = 'ordered' | 'scheduled' | 'in_progress' | 'completed' | 'cancelled';
export type ProcedurePaymentMode = 'cash' | 'nhif' | 'insurance' | 'exempt';
export type ProcedureBillingStatus = 'pending_payment' | 'paid' | 'pending_authorization' | 'authorized' | 'rejected' | 'exempted';

export interface ProcedureOrder {
    id: number;
    order_number: string;
    procedure_name: string;
    procedure_type: ProcedureType;
    urgency: ProcedureUrgency;
    clinical_indication: string;
    status: ProcedureOrderStatus;
    payment_mode: ProcedurePaymentMode;
    billing_status: ProcedureBillingStatus;
    quantity: number;
    unit_price: number;
    total_price: number;
    charge_id: number | null;
    invoice_id: number | null;
    is_paid: boolean;
    is_authorized: boolean;
    can_be_executed: boolean;
    is_emergency_exemption: boolean;
    exemption_reason: string | null;
    nhif_item_code: string | null;
    nhif_approval_status: string | null;
    nhif_authorization_no: string | null;
    nhif_rejection_reason: string | null;
    nhif_pre_approval: {
        status: string;
        label: string;
        blocked: boolean;
        message: string;
    } | null;
    prescribed_by: { id: number; UserName: string } | null;
    performer: { id: number; UserName: string } | null;
    performed_at: string | null;
    scheduled_at: string | null;
    findings: string | null;
    results: string | null;
    procedure_notes: string | null;
    complications: string | null;
    notes: string | null;
    created_at: string;
}

export interface ProcedureCatalogueItem {
    id: number;
    code: string;
    name: string;
    category: string;
    cash_price: number;
    nhif_code: string | null;
    is_covered_under_scheme: boolean;
    requires_preauth: boolean;
}

export const PROCEDURE_TYPE_LABELS: Record<ProcedureType, string> = {
    bedside_procedure: 'Bedside Procedure',
    minor_theatre: 'Minor Theatre',
    major_theatre: 'Major Theatre',
    endoscopy: 'Endoscopy / Diagnostic',
    other: 'Other Procedure',
};

export const PROCEDURE_URGENCY_LABELS: Record<ProcedureUrgency, string> = {
    elective: 'Elective',
    urgent: 'Urgent',
    emergency: 'Emergency',
};

export const PROCEDURE_STATUS_LABELS: Record<ProcedureOrderStatus, string> = {
    ordered: 'Ordered',
    scheduled: 'Scheduled',
    in_progress: 'In Progress',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

export const PROCEDURE_STATUS_VARIANTS: Record<ProcedureOrderStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    ordered: 'secondary',
    scheduled: 'outline',
    in_progress: 'default',
    completed: 'default',
    cancelled: 'destructive',
};
