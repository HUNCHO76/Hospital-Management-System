import { type ChargeInsuranceAllocation } from '@/types/insurance-billing';

/**
 * ACTIVE/SUSPENDED/CLOSED — spec §7's own minimal status list.
 */
export type PatientAccountStatus = 'active' | 'suspended' | 'closed';

/**
 * PENDING: just recorded, not yet a financial fact. POSTED: counts toward
 * the account balance. VOIDED: a PENDING charge invalidated before posting.
 * CANCELLED: a POSTED charge reversed after the fact (a controlled
 * correction, never a silent edit).
 */
export type ChargeStatus = 'pending' | 'posted' | 'voided' | 'cancelled';

/**
 * DRAFT: being built, charges may still be added/removed. POSTED: a real,
 * immutable financial document. VOIDED: a draft abandoned before it ever
 * became one. CANCELLED: a posted invoice reversed after the fact (a
 * controlled correction, never a silent edit). No PARTIALLY_PAID/PAID —
 * those belong to Step 57 (Payment), not this foundation.
 */
export type InvoiceStatus = 'draft' | 'posted' | 'voided' | 'cancelled';

export interface BillingHospital {
    id: number;
    name: string;
}

export interface BillingPatient {
    id: number;
    patient_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    date_of_birth: string | null;
    gender: string | null;
}

export interface BillingUser {
    id: number;
    name: string;
}

export interface PatientAccount {
    id: number;
    account_number: string;
    status: PatientAccountStatus;
    currency: string;
    hospital: BillingHospital | null;
    patient: BillingPatient | null;
    created_at: string;
}

export interface Charge {
    id: number;
    charge_number: string | null;
    invoice_id: number | null;
    source_type: string | null;
    source_id: number | null;
    service: { id: number; code: string; name: string } | null;
    service_code_snapshot: string | null;
    description: string;
    notes: string | null;
    quantity: number;
    unit_price: string;
    gross_amount: string;
    discount_amount: string;
    net_amount: string;
    currency: string;
    status: ChargeStatus;
    encounter: { id: number; encounter_number: string } | null;
    created_by: BillingUser | null;
    cancelled_by: BillingUser | null;
    cancellation_reason: string | null;
    charged_at: string;
    posted_at: string | null;
    /**
     * This charge's own insurance responsibility split (Phase 7, Step 56) —
     * only present when the backend eager-loaded it (the invoice's own
     * insurance page); null until coverage has ever been calculated.
     */
    insurance_allocation?: ChargeInsuranceAllocation | null;
}

/**
 * A single Invoice (Phase 7, Step 55). `charges` — its own line items, see
 * the create_invoices_table migration's own docblock for why there is no
 * separate invoice-item shape — is only present when the backend actually
 * loaded them (the show page; the index page's rows omit it).
 */
export interface Invoice {
    id: number;
    invoice_number: string;
    status: InvoiceStatus;
    invoice_date: string | null;
    subtotal: string | null;
    total_amount: string | null;
    currency: string;
    notes: string | null;
    hospital: BillingHospital | null;
    patient: BillingPatient | null;
    patient_account: { id: number; account_number: string } | null;
    charges: Charge[];
    created_by: BillingUser | null;
    cancelled_by: BillingUser | null;
    cancellation_reason: string | null;
    posted_at: string | null;
    created_at: string;
}

export interface BillingAccountFilters {
    search: string;
    status: string;
}

export interface BillingInvoiceFilters {
    search: string;
    status: string;
}

export interface PatientSearchResult {
    id: number;
    patient_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    phone: string | null;
}

export type CreateChargeFormData = {
    hospital_id: string;
    patient_id: string;
    encounter_id: string;
    service_id: string;
    quantity: string;
    notes: string;
    [key: string]: string;
};

export type VoidChargeFormData = {
    reason: string;
    [key: string]: string;
};

export type CreateInvoiceFormData = {
    patient_account_id: string;
    charge_ids: number[];
    notes: string;
    [key: string]: string | number[];
};

export type VoidInvoiceFormData = {
    reason: string;
    [key: string]: string;
};
