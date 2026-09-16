import {
    type PrescriptionDurationUnit,
    type PrescriptionFrequency,
    type PrescriptionOrderItemStatus,
    type PrescriptionOrderStatus,
    type PrescriptionRoute,
    type PrescriptionVerificationFlag,
    type PrescriptionVerificationStatus,
} from '@/types/prescription';

export const prescriptionOrderStatusLabel: Record<PrescriptionOrderStatus, string> = {
    draft: 'Draft',
    active: 'Active',
    cancelled: 'Cancelled',
};

export const prescriptionOrderStatusVariant: Record<PrescriptionOrderStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    draft: 'outline',
    active: 'default',
    cancelled: 'destructive',
};

export const prescriptionOrderItemStatusLabel: Record<PrescriptionOrderItemStatus, string> = {
    active: 'Active',
    cancelled: 'Cancelled',
};

export const prescriptionRouteLabel: Record<PrescriptionRoute, string> = {
    oral: 'Oral',
    intravenous: 'Intravenous',
    intramuscular: 'Intramuscular',
    subcutaneous: 'Subcutaneous',
    topical: 'Topical',
    rectal: 'Rectal',
    inhalation: 'Inhalation',
    other: 'Other',
};

export const prescriptionFrequencyLabel: Record<PrescriptionFrequency, string> = {
    once_daily: 'Once daily',
    twice_daily: 'Twice daily',
    three_times_daily: 'Three times daily',
    four_times_daily: 'Four times daily',
    every_4_hours: 'Every 4 hours',
    every_6_hours: 'Every 6 hours',
    every_8_hours: 'Every 8 hours',
    every_12_hours: 'Every 12 hours',
    as_needed: 'As needed',
    stat: 'Stat',
    other: 'Other',
};

export const prescriptionDurationUnitLabel: Record<PrescriptionDurationUnit, string> = {
    days: 'Days',
    weeks: 'Weeks',
    months: 'Months',
};

export const prescriptionVerificationStatusLabel: Record<PrescriptionVerificationStatus, string> = {
    pending_verification: 'Pending Verification',
    returned: 'Returned',
    verified: 'Verified',
};

export const prescriptionVerificationStatusVariant: Record<PrescriptionVerificationStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    pending_verification: 'outline',
    returned: 'destructive',
    verified: 'default',
};

export const prescriptionVerificationFlagLabel: Record<PrescriptionVerificationFlag, string> = {
    medication_inactive: 'Medication is now inactive in the catalog',
    duplicate_medication: 'Already active on another prescription for this patient',
};
