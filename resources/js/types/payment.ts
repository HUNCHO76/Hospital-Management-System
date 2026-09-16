import { type BillingHospital, type BillingPatient, type BillingUser, type Invoice } from '@/types/billing';
import { type PaymentRefund, type PaymentReversal } from '@/types/correction';

export type PaymentStatus = 'posted' | 'failed';

export type PaymentMethod = 'cash' | 'card' | 'bank_transfer' | 'mobile_money' | 'other';

/**
 * The server-calculated responsibility split for one invoice
 * (PaymentService::calculateOutstanding()) — every figure here comes from
 * the backend; the frontend never computes or overrides any of them.
 */
export interface OutstandingBreakdown {
    invoice_total: number;
    insurance_responsibility: number;
    patient_responsibility: number;
    patient_paid: number;
    reversals_total: number;
    refunds_total: number;
    credit_adjustments: number;
    debit_adjustments: number;
    patient_outstanding: number;
    status: 'unpaid' | 'partially_paid' | 'paid';
}

export interface EligibleInvoice {
    invoice: Invoice;
    outstanding: OutstandingBreakdown;
}

export interface PaymentAllocationSummary {
    id: number;
    invoice_id: number;
    amount: string;
}

export interface Payment {
    id: number;
    payment_number: string;
    receipt_number: string;
    payment_date: string;
    amount: string;
    currency: string;
    payment_method: PaymentMethod;
    reference_number: string | null;
    status: PaymentStatus;
    notes: string | null;
    hospital: BillingHospital | null;
    patient: BillingPatient | null;
    patient_account: { id: number; account_number: string } | null;
    invoice: { id: number; invoice_number: string; total_amount: string | null } | null;
    allocations: PaymentAllocationSummary[];
    received_by: BillingUser | null;
    failed_by: BillingUser | null;
    failure_reason: string | null;
    posted_at: string | null;
    failed_at: string | null;
    created_at: string;
    reversal?: PaymentReversal | null;
    refunds?: PaymentRefund[];
}

export interface PaymentFilters {
    search: string;
    status: string;
    payment_method: string;
}

export type CreatePaymentFormData = {
    invoice_id: string;
    amount: string;
    payment_date: string;
    payment_method: PaymentMethod | '';
    reference_number: string;
    notes: string;
    [key: string]: string;
};

export type VoidPaymentFormData = {
    reason: string;
    [key: string]: string;
};
