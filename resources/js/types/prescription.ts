import { type MedicationRoute } from '@/types/medication';

/**
 * DRAFT is declared for completeness but never persisted (Phase 6, Step 46)
 * — an order is created directly as ACTIVE, mirroring RadiologyOrderStatus's
 * own STATUS_ORDERED precedent. Pharmacy-side processing states
 * (verified/dispensed/...) belong to Steps 47-49.
 */
export type PrescriptionOrderStatus = 'draft' | 'active' | 'cancelled';

export type PrescriptionOrderItemStatus = 'active' | 'cancelled';

/**
 * Mirrors PrescriptionOrderItem::ROUTES on the backend exactly (the same
 * controlled list the pre-existing CarePlanItem-nested Prescription uses).
 */
export type PrescriptionRoute = 'oral' | 'intravenous' | 'intramuscular' | 'subcutaneous' | 'topical' | 'rectal' | 'inhalation' | 'other';

/**
 * Mirrors PrescriptionOrderItem::FREQUENCIES on the backend exactly — the
 * first controlled dosing-frequency list in this codebase (the legacy
 * Prescription.frequency is an unrestricted free string).
 */
export type PrescriptionFrequency =
    | 'once_daily'
    | 'twice_daily'
    | 'three_times_daily'
    | 'four_times_daily'
    | 'every_4_hours'
    | 'every_6_hours'
    | 'every_8_hours'
    | 'every_12_hours'
    | 'as_needed'
    | 'stat'
    | 'other';

export type PrescriptionDurationUnit = 'days' | 'weeks' | 'months';

export interface PrescriptionOrderUser {
    id: number;
    name: string;
}

export interface PrescriptionOrderPatient {
    id: number;
    patient_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    date_of_birth: string | null;
    gender: string | null;
}

export interface PrescriptionOrderEncounter {
    id: number;
    encounter_number: string;
}

export interface PrescriptionOrderDepartment {
    id: number;
    name: string;
}

export interface PrescriptionOrderItem {
    id: number;
    prescription_order_id: number;
    medication_id: number;
    /** Snapshotted at prescribing time — a later catalog edit never changes these. */
    medication_code_snapshot: string;
    medication_name_snapshot: string;
    strength_snapshot: string | null;
    form_snapshot: string | null;
    route_snapshot: MedicationRoute | null;
    dose: string;
    dose_unit: string | null;
    frequency: PrescriptionFrequency;
    route: PrescriptionRoute;
    duration: number | null;
    duration_unit: PrescriptionDurationUnit | null;
    quantity: number;
    quantity_unit: string | null;
    instructions: string | null;
    status: PrescriptionOrderItemStatus;
    cancelled_at: string | null;
    cancelled_by: PrescriptionOrderUser | null;
    cancellation_reason: string | null;
}

export interface PrescriptionOrder {
    id: number;
    clinical_encounter_id: number;
    prescription_number: string;
    clinical_notes: string | null;
    status: PrescriptionOrderStatus;
    items: PrescriptionOrderItem[];
    prescriber: PrescriptionOrderUser | null;
    prescribed_at: string;
    updated_by: PrescriptionOrderUser | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    /** Phase 6, Step 47 — only present when the caller eager-loads it. */
    verification?: PrescriptionVerification | null;
    patient: PrescriptionOrderPatient | null;
    encounter: PrescriptionOrderEncounter | null;
    department: PrescriptionOrderDepartment | null;
    created_at: string;
}

export interface PrescriptionOrderItemFormData {
    medication_id: number | null;
    medication_label: string;
    dose: string;
    dose_unit: string;
    frequency: PrescriptionFrequency | '';
    route: PrescriptionRoute | '';
    duration: string;
    duration_unit: PrescriptionDurationUnit | '';
    quantity: string;
    quantity_unit: string;
    instructions: string;
}

export interface PrescriptionOrderFormData {
    items: {
        medication_id: number | null;
        dose: string;
        dose_unit: string;
        frequency: string;
        route: string;
        duration: string;
        duration_unit: string;
        quantity: string;
        quantity_unit: string;
        instructions: string;
    }[];
    clinical_notes: string;
    [key: string]: string | PrescriptionOrderFormData['items'];
}

export interface PrescriptionOrderFilters {
    search: string;
    status: string;
    date_from: string;
    date_to: string;
}

/**
 * The pharmacy-owned verification review (Phase 6, Step 47) — a separate
 * entity from PrescriptionOrder itself, mirrored here as a separate,
 * optional type rather than folded into PrescriptionOrder so the
 * clinical-encounter page's own PrescriptionOrder usage is never forced to
 * account for fields it never receives.
 */
export type PrescriptionVerificationStatus = 'pending_verification' | 'returned' | 'verified';

export interface PrescriptionVerification {
    status: PrescriptionVerificationStatus;
    submitted_by: PrescriptionOrderUser | null;
    submitted_at: string | null;
    verified_by: PrescriptionOrderUser | null;
    verified_at: string | null;
    verification_notes: string | null;
    returned_by: PrescriptionOrderUser | null;
    returned_at: string | null;
    return_reason: string | null;
}

/**
 * A safety flag a verifier should notice (spec §12) — a workflow/data check
 * computed server-side by PrescriptionVerificationResource, never a
 * clinical decision.
 */
export type PrescriptionVerificationFlag = 'medication_inactive' | 'duplicate_medication';

export interface PrescriptionVerificationItem extends PrescriptionOrderItem {
    flags: PrescriptionVerificationFlag[];
}

/**
 * The verification-review payload PrescriptionVerificationController
 * renders — PrescriptionOrder's own fields plus its `verification` review
 * and per-item safety `flags`.
 */
export interface PrescriptionVerificationOrder extends Omit<PrescriptionOrder, 'items'> {
    items: PrescriptionVerificationItem[];
    verification: PrescriptionVerification | null;
    financial_status?: 'authorized' | 'payment_required';
}

export interface PrescriptionVerificationFilters {
    search: string;
    status: string;
    date_from: string;
    date_to: string;
}
