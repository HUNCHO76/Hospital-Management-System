import { type PrescriptionOrderStatus } from '@/types/prescription';
import { type StockBatchStatus } from '@/types/stock';

export interface PharmacyDashboardMetrics {
    prescriptions: {
        today: number;
        this_week: number;
        this_month: number;
        cancelled: number;
    };
    verification: {
        pending: number;
        verified: number;
        returned: number;
    };
    dispensing: {
        pending: number;
        partially_dispensed: number;
        dispensed: number;
        cancelled_events: number;
    };
    stock: {
        active_medications: number;
        total_stock_items: number;
        low_stock: number;
        out_of_stock: number;
        expiring_soon: number;
        expired: number;
        quarantined: number;
        damaged: number;
    };
    movements: {
        receipts: number;
        dispense: number;
        adjustments: number;
        expired_damaged: number;
    };
}

export interface ReportUser {
    id: number;
    name: string;
}

export interface ReportMedication {
    id: number;
    code: string;
    generic_name: string;
    brand_name: string | null;
    form: string | null;
    unit: string | null;
}

export interface ReportPatient {
    id: number;
    patient_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
}

export interface StockBatchReportRow {
    id: number;
    batch_number: string;
    manufacture_date: string | null;
    expiry_date: string;
    quantity: number;
    unit_cost: string | null;
    supplier_name: string | null;
    status: StockBatchStatus;
    is_expired: boolean;
    is_expiring_soon: boolean;
    medication: ReportMedication | null;
    hospital: { id: number; name: string } | null;
    stock_item_id: number;
    created_at: string;
}

export interface StockBatchReportFilters {
    search: string;
    status: string;
    expiry_from: string;
    expiry_to: string;
    [key: string]: string | undefined;
}

export interface StockMovementReportFilters {
    search: string;
    transaction_type: string;
    medication_id: string;
    performed_by: string;
    date_from: string;
    date_to: string;
    [key: string]: string | undefined;
}

export interface MedicationUtilizationRow {
    medication_name: string;
    period: string;
    total_quantity: number;
    dispensing_events: number;
    prescription_count: number;
    patient_count: number;
}

export interface MedicationUtilizationFilters {
    medication_id: string;
    date_from: string;
    date_to: string;
    group_by: 'day' | 'week' | 'month';
    [key: string]: string | undefined;
}

export interface PrescriptionReportRow {
    id: number;
    prescription_number: string;
    status: PrescriptionOrderStatus;
    prescribed_at: string | null;
    medication_count: number | null;
    prescriber: ReportUser | null;
    verification_status: string | null;
    dispensing_status: 'pending' | 'partially_dispensed' | 'dispensed' | null;
    patient: ReportPatient | null;
    encounter: { id: number; encounter_number: string } | null;
    department: { id: number; name: string } | null;
}

export interface PrescriptionReportFilters {
    search: string;
    status: string;
    verification_status: string;
    prescriber_id: string;
    date_from: string;
    date_to: string;
    [key: string]: string | undefined;
}

export interface DispensingReportRow {
    id: number;
    dispensed_quantity: number;
    status: 'dispensed' | 'unavailable';
    notes: string | null;
    medication_name: string | null;
    prescribed_quantity: number | null;
    dispensing: {
        id: number;
        dispensing_number: string;
        status: string;
        completed_at: string | null;
        dispensed_by: ReportUser | null;
    } | null;
    prescription: { id: number; prescription_number: string } | null;
    patient: ReportPatient | null;
    department: { id: number; name: string } | null;
}

export interface DispensingReportFilters {
    search: string;
    status: string;
    dispenser_id: string;
    date_from: string;
    date_to: string;
    [key: string]: string | undefined;
}

export interface ReconciliationRow {
    batch: StockBatchReportRow;
    ledger_balance: number;
    recorded_quantity: number;
    reconciled: boolean;
}
