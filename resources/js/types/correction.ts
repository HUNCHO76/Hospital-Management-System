import { type BillingHospital, type BillingPatient, type BillingUser } from '@/types/billing';

type PaymentMethod = 'cash' | 'card' | 'bank_transfer' | 'mobile_money' | 'other';

export interface PaymentReversal {
    id: number;
    reversal_number: string;
    amount: string;
    currency: string;
    reason: string;
    notes: string | null;
    reversed_at: string;
    original_payment?: {
        id: number;
        payment_number: string;
        amount: string;
        payment_method: PaymentMethod;
        payment_date: string;
    } | null;
    invoice?: { id: number; invoice_number: string } | null;
    patient?: BillingPatient | null;
    hospital?: BillingHospital | null;
    reversed_by?: BillingUser | null;
    created_at: string;
}

export interface PaymentRefund {
    id: number;
    refund_number: string;
    amount: string;
    currency: string;
    refund_method: PaymentMethod;
    refund_reference: string | null;
    reason: string;
    notes: string | null;
    processed_at: string;
    payment?: {
        id: number;
        payment_number: string;
        amount: string;
        payment_method: PaymentMethod;
        payment_date: string;
    } | null;
    invoice?: { id: number; invoice_number: string } | null;
    patient?: BillingPatient | null;
    hospital?: BillingHospital | null;
    processed_by?: BillingUser | null;
    created_at: string;
}

export type AdjustmentType =
    | 'credit_adjustment'
    | 'debit_adjustment'
    | 'approved_discount'
    | 'write_off'
    | 'billing_correction';

export type AdjustmentDirection = 'credit' | 'debit';

export interface BillingAdjustment {
    id: number;
    adjustment_number: string;
    type: AdjustmentType;
    direction: AdjustmentDirection;
    amount: string;
    currency: string;
    reason: string;
    notes: string | null;
    adjusted_at: string;
    invoice?: { id: number; invoice_number: string } | null;
    patient?: BillingPatient | null;
    hospital?: BillingHospital | null;
    adjusted_by?: BillingUser | null;
    created_at: string;
}

export type StoreReversalFormData = {
    reason: string;
    notes: string;
    [key: string]: string;
};

export type StoreRefundFormData = {
    amount: string;
    refund_method: PaymentMethod | '';
    refund_reference: string;
    reason: string;
    notes: string;
    [key: string]: string;
};

export type StoreAdjustmentFormData = {
    type: AdjustmentType | '';
    direction: AdjustmentDirection | '';
    amount: string;
    reason: string;
    notes: string;
    [key: string]: string;
};
