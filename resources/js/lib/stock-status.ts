import { type StockAdjustmentType, type StockBatchStatus, type StockTransactionType } from '@/types/stock';

export const stockBatchStatusLabel: Record<StockBatchStatus, string> = {
    active: 'Active',
    quarantined: 'Quarantined',
    expired: 'Expired',
    damaged: 'Damaged',
    depleted: 'Depleted',
};

export const stockBatchStatusVariant: Record<StockBatchStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    active: 'default',
    quarantined: 'secondary',
    expired: 'destructive',
    damaged: 'destructive',
    depleted: 'outline',
};

export const stockTransactionTypeLabel: Record<StockTransactionType, string> = {
    receipt: 'Receipt',
    dispense: 'Dispense',
    adjustment_in: 'Adjustment (In)',
    adjustment_out: 'Adjustment (Out)',
    expired: 'Expired Write-off',
    damaged: 'Damaged Write-off',
    quarantine: 'Quarantined',
    release: 'Released',
};

export const stockAdjustmentTypeLabel: Record<StockAdjustmentType, string> = {
    adjustment_in: 'Count Correction (+)',
    adjustment_out: 'Count Correction (-)',
    expired: 'Expired Stock',
    damaged: 'Damaged Stock',
};
