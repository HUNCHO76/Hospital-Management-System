import { type AdjustmentDirection, type AdjustmentType } from '@/types/correction';

export const adjustmentTypeLabel: Record<AdjustmentType, string> = {
    credit_adjustment: 'Credit Adjustment',
    debit_adjustment: 'Debit Adjustment',
    approved_discount: 'Approved Discount',
    write_off: 'Write-Off',
    billing_correction: 'Billing Correction',
};

export const adjustmentDirectionLabel: Record<AdjustmentDirection, string> = {
    credit: 'Credit (reduces balance)',
    debit: 'Debit (increases balance)',
};

export const adjustmentDirectionVariant: Record<AdjustmentDirection, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    credit: 'default',
    debit: 'destructive',
};
