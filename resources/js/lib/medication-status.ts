import { type MedicationForm, type MedicationRoute, type MedicationStatus } from '@/types/medication';

export const medicationStatusVariant: Record<MedicationStatus, 'default' | 'secondary'> = {
    active: 'default',
    inactive: 'secondary',
};

export const medicationFormLabel: Record<MedicationForm, string> = {
    tablet: 'Tablet',
    capsule: 'Capsule',
    syrup: 'Syrup',
    suspension: 'Suspension',
    injection: 'Injection',
    cream: 'Cream',
    ointment: 'Ointment',
    drops: 'Drops',
    suppository: 'Suppository',
    inhaler: 'Inhaler',
    powder: 'Powder',
    solution: 'Solution',
    other: 'Other',
};

export const medicationRouteLabel: Record<MedicationRoute, string> = {
    oral: 'Oral',
    intravenous: 'Intravenous',
    intramuscular: 'Intramuscular',
    subcutaneous: 'Subcutaneous',
    topical: 'Topical',
    rectal: 'Rectal',
    vaginal: 'Vaginal',
    ophthalmic: 'Ophthalmic',
    otic: 'Otic',
    nasal: 'Nasal',
    inhalation: 'Inhalation',
    sublingual: 'Sublingual',
    other: 'Other',
};
