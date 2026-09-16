import { type BillingHospital, type BillingUser } from '@/types/billing';

export type CashierSessionStatus = 'open' | 'closed';

export interface CashierSession {
    id: number;
    session_number: string;
    status: CashierSessionStatus;
    opening_balance: string;
    expected_closing_balance: string | null;
    actual_closing_balance: string | null;
    variance: string | null;
    variance_reason: string | null;
    notes: string | null;
    opened_at: string;
    closed_at: string | null;
    hospital?: BillingHospital | null;
    cashier?: BillingUser | null;
    created_at: string;
}

export type CollectionSummary = {
    paymentTotals: Record<string, number>;
    refundTotals: Record<string, number>;
    reversalTotal: number;
    netCashCollected: number;
};

export type OpenSessionFormData = {
    hospital_id: string;
    opening_balance: string;
    notes: string;
    [key: string]: string;
};

export type CloseSessionFormData = {
    actual_closing_balance: string;
    variance_reason: string;
    notes: string;
    [key: string]: string;
};
