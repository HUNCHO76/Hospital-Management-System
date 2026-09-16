import {
    type PrescriptionDurationUnit,
    type PrescriptionFrequency,
    type PrescriptionOrderDepartment,
    type PrescriptionOrderEncounter,
    type PrescriptionOrderItemStatus,
    type PrescriptionOrderPatient,
    type PrescriptionOrderStatus,
    type PrescriptionOrderUser,
    type PrescriptionRoute,
    type PrescriptionVerificationStatus,
} from '@/types/prescription';

/**
 * Opened by 'start' and either completed or cancelled — only one may ever
 * be IN_PROGRESS per prescription at a time (DispensingService::start()'s
 * own guarantee, spec §19).
 */
export type DispensingStatus = 'in_progress' | 'completed' | 'cancelled';

/**
 * DISPENSED: a positive quantity was actually handed to the patient this
 * event. UNAVAILABLE: explicitly recorded as not dispensable this event
 * (spec §4's UNABLE_TO_DISPENSE, folded to the item level since one event
 * can mix both outcomes across its medications).
 */
export type DispensingItemStatus = 'dispensed' | 'unavailable';

/**
 * The prescription's overall fulfillment state (spec §24) — deliberately
 * separate from both PrescriptionOrderStatus (the clinical order lifecycle)
 * and PrescriptionVerificationStatus (the pharmacy safety review), computed
 * purely from dispensing history rather than stored anywhere.
 */
export type PrescriptionFulfillmentStatus = 'pending' | 'partially_dispensed' | 'dispensed';

export interface DispensingItem {
    id: number;
    dispensing_id: number;
    prescription_order_item_id: number;
    dispensed_quantity: number;
    status: DispensingItemStatus;
    notes: string | null;
    created_at: string;
}

export interface Dispensing {
    id: number;
    dispensing_number: string;
    status: DispensingStatus;
    dispensed_by: PrescriptionOrderUser | null;
    started_at: string;
    completed_at: string | null;
    notes: string | null;
    cancelled_at: string | null;
    cancelled_by: PrescriptionOrderUser | null;
    cancellation_reason: string | null;
    items: DispensingItem[];
}

/**
 * One medication line on the dispensing view of a prescription —
 * PrescriptionOrderItem's own prescribing fields plus this item's computed
 * dispensing history (spec §9-10: prescribed vs. dispensed vs. remaining).
 */
export interface DispensingPrescriptionItem {
    id: number;
    medication_name_snapshot: string;
    strength_snapshot: string | null;
    form_snapshot: string | null;
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
    dispensed_quantity: number;
    remaining_quantity: number;
}

/**
 * The pharmacy dispensing view of a prescription — used on both the
 * dispensing worklist and detail page (mirrors PrescriptionVerificationOrder's
 * own role for the verification queue).
 */
export interface DispensingPrescription {
    id: number;
    prescription_number: string;
    clinical_notes: string | null;
    status: PrescriptionOrderStatus;
    prescriber: PrescriptionOrderUser | null;
    prescribed_at: string;
    verification: {
        status: PrescriptionVerificationStatus;
        verified_by: PrescriptionOrderUser | null;
        verified_at: string | null;
    } | null;
    fulfillment_status: PrescriptionFulfillmentStatus;
    items: DispensingPrescriptionItem[];
    dispensings: Dispensing[];
    current_dispensing: Dispensing | null;
    patient: PrescriptionOrderPatient | null;
    encounter: PrescriptionOrderEncounter | null;
    department: PrescriptionOrderDepartment | null;
    created_at: string;
}

export interface DispensingFilters {
    search: string;
    status: string;
    date_from: string;
    date_to: string;
}

export type CompleteDispensingItemFormData = {
    prescription_order_item_id: number;
    dispensed_quantity: string;
    status: DispensingItemStatus;
    notes: string;
};

export interface CompleteDispensingFormData {
    items: CompleteDispensingItemFormData[];
    notes: string;
    [key: string]: string | CompleteDispensingItemFormData[];
}

export interface CancelDispensingFormData {
    cancellation_reason: string;
    [key: string]: string;
}
