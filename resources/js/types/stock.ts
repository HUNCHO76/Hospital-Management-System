import { type MedicationForm } from '@/types/medication';

/**
 * "This medication, held at this hospital" (Phase 6, Step 49) — identity and
 * reorder configuration only; quantity/expiry/cost all live on StockBatch.
 */
export type StockItemStatus = 'active' | 'inactive';

/**
 * ACTIVE: normal, counts toward availability when not expired. QUARANTINED:
 * held pending investigation, reversible via release. EXPIRED/DAMAGED: fully
 * written off for that reason, terminal. DEPLETED: reached zero through
 * ordinary dispensing/adjustment, not itself a write-off.
 */
export type StockBatchStatus = 'active' | 'quarantined' | 'expired' | 'damaged' | 'depleted';

export type StockTransactionType = 'receipt' | 'dispense' | 'adjustment_in' | 'adjustment_out' | 'expired' | 'damaged' | 'quarantine' | 'release';

/**
 * The type of stock adjustment a user may submit — 'expired'/'damaged' are
 * always a quantity write-off (spec §20); 'adjustment_in'/'adjustment_out'
 * are plain count corrections.
 */
export type StockAdjustmentType = 'adjustment_in' | 'adjustment_out' | 'expired' | 'damaged';

export interface StockHospital {
    id: number;
    name: string;
}

export interface StockMedication {
    id: number;
    code: string;
    generic_name: string;
    brand_name: string | null;
    form: MedicationForm | null;
    unit: string | null;
    category: string | null;
}

export interface StockUser {
    id: number;
    name: string;
}

export interface StockBatch {
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
    is_dispensable: boolean;
    created_by: StockUser | null;
    created_at: string;
}

export interface StockTransaction {
    id: number;
    transaction_number: string;
    transaction_type: StockTransactionType;
    quantity: number;
    balance_before: number;
    balance_after: number;
    reference_type: string | null;
    reference_id: number | null;
    reason: string | null;
    /** Only present on the Phase 6, Step 50 cross-medication movement report. */
    medication_name?: string | null;
    stock_batch: { id: number; batch_number: string } | null;
    performed_by: StockUser | null;
    occurred_at: string;
}

export interface StockItem {
    id: number;
    hospital: StockHospital | null;
    medication: StockMedication | null;
    reorder_level: number;
    maximum_level: number | null;
    status: StockItemStatus;
    total_quantity: number;
    available_quantity: number;
    is_low_stock: boolean;
    nearest_expiry_date: string | null;
    batches: StockBatch[] | null;
    created_at: string;
}

export interface StockFilters {
    search: string;
    status: string;
}

export interface StockDashboardCounts {
    total_stock_items: number;
    total_medications: number;
    low_stock: number;
    out_of_stock: number;
    expiring_soon: number;
    expired: number;
    quarantined: number;
}

export type ReceiveStockFormData = {
    hospital_id: string;
    medication_id: string;
    batch_number: string;
    manufacture_date: string;
    expiry_date: string;
    quantity: string;
    unit_cost: string;
    supplier_name: string;
    notes: string;
    reorder_level: string;
    maximum_level: string;
    [key: string]: string;
};

export type AdjustStockFormData = {
    type: StockAdjustmentType;
    quantity: string;
    reason: string;
    [key: string]: string;
};

export type QuarantineStockFormData = {
    reason: string;
    [key: string]: string;
};
