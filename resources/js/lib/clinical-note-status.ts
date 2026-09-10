import { type ClinicalNoteStatus, type ClinicalNoteType } from '@/types/clinical-note';

export const clinicalNoteStatusLabel: Record<ClinicalNoteStatus, string> = {
    draft: 'Draft',
    signed: 'Signed',
    amended: 'Amended',
    cancelled: 'Cancelled',
};

export const clinicalNoteStatusVariant: Record<ClinicalNoteStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    draft: 'outline',
    signed: 'default',
    amended: 'secondary',
    cancelled: 'destructive',
};

export const clinicalNoteTypeLabel: Record<ClinicalNoteType, string> = {
    progress: 'Progress Note',
    follow_up: 'Follow-up Note',
    nursing: 'Nursing Note',
    clinical_observation: 'Clinical Observation',
    procedure: 'Procedure Note',
    other: 'Other',
};

/**
 * Whether the note is still a mutable draft (plain edits allowed).
 */
export function isClinicalNoteDraft(status: ClinicalNoteStatus): boolean {
    return status === 'draft';
}

/**
 * Whether the note is eligible for the controlled amendment workflow,
 * mirroring ClinicalNote::TRANSITIONS on the backend.
 */
export function canAmendClinicalNote(status: ClinicalNoteStatus): boolean {
    return status === 'signed' || status === 'amended';
}

/**
 * Whether the note can still be signed.
 */
export function canSignClinicalNote(status: ClinicalNoteStatus): boolean {
    return status === 'draft';
}

/**
 * Whether the note can still be cancelled — only from draft, since a signed
 * clinical record is amended, never cancelled.
 */
export function canCancelClinicalNote(status: ClinicalNoteStatus): boolean {
    return status === 'draft';
}
