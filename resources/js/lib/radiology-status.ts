import {
    type RadiologyAbortReason,
    type RadiologyAppointmentStatus,
    type RadiologyBodySite,
    type RadiologyConsentStatus,
    type RadiologyContrastRequirement,
    type RadiologyContrastType,
    type RadiologyLaterality,
    type RadiologyModality,
    type RadiologyNotReadyReason,
    type RadiologyOrderPriority,
    type RadiologyOrderStatus,
    type RadiologyPreparationType,
    type RadiologyProcedureStatus,
    type RadiologyReportReturnReason,
    type RadiologyReportStatus,
} from '@/types/radiology';

export const radiologyOrderStatusLabel: Record<RadiologyOrderStatus, string> = {
    draft: 'Draft',
    ordered: 'Ordered',
    scheduled: 'Scheduled',
    cancelled: 'Cancelled',
};

export const radiologyOrderStatusVariant: Record<RadiologyOrderStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    draft: 'outline',
    ordered: 'outline',
    scheduled: 'default',
    cancelled: 'destructive',
};

export const radiologyAppointmentStatusLabel: Record<RadiologyAppointmentStatus, string> = {
    scheduled: 'Scheduled',
    confirmed: 'Confirmed',
    arrived: 'Arrived',
    preparation_pending: 'Preparation Pending',
    preparation_in_progress: 'Preparation In Progress',
    ready: 'Ready',
    not_ready: 'Not Ready',
    in_progress: 'In Progress',
    completed: 'Completed',
    aborted: 'Aborted',
    cancelled: 'Cancelled',
    no_show: 'No-Show',
};

export const radiologyAppointmentStatusVariant: Record<RadiologyAppointmentStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    scheduled: 'outline',
    confirmed: 'default',
    arrived: 'secondary',
    preparation_pending: 'outline',
    preparation_in_progress: 'default',
    ready: 'default',
    not_ready: 'destructive',
    in_progress: 'default',
    completed: 'default',
    aborted: 'destructive',
    cancelled: 'destructive',
    no_show: 'secondary',
};

export const radiologyReportStatusLabel: Record<RadiologyReportStatus, string> = {
    draft: 'Draft',
    submitted: 'Submitted',
    returned: 'Returned',
    verified: 'Verified',
};

export const radiologyReportStatusVariant: Record<RadiologyReportStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    draft: 'outline',
    submitted: 'default',
    returned: 'destructive',
    verified: 'default',
};

export const radiologyReportReturnReasonLabel: Record<RadiologyReportReturnReason, string> = {
    incomplete_findings: 'Incomplete Findings',
    unclear_impression: 'Unclear Impression',
    incorrect_technique: 'Incorrect Technique',
    missing_recommendation: 'Missing Recommendation',
    clinical_inconsistency: 'Clinical Inconsistency',
    wrong_procedure_information: 'Wrong Procedure Information',
    typographical_error: 'Typographical Error',
    additional_review_required: 'Additional Review Required',
    other: 'Other',
};

export const radiologyAbortReasonLabel: Record<RadiologyAbortReason, string> = {
    patient_unable_to_tolerate: 'Patient Unable to Tolerate Procedure',
    equipment_problem: 'Equipment Problem',
    patient_condition_changed: 'Patient Condition Changed',
    patient_refused: 'Patient Refused',
    incorrect_preparation: 'Incorrect Preparation',
    safety_concern: 'Safety Concern',
    technical_failure: 'Technical Failure',
    other: 'Other',
};

/**
 * Human-readable labels for the modality-specific technical_details keys
 * (Phase 5, Step 41, Part 9) — the union of every key across
 * RadiologyAppointment::TECHNICAL_FIELDS_BY_MODALITY.
 */
export const radiologyTechnicalFieldLabel: Record<string, string> = {
    view: 'View / Projection',
    body_part: 'Body Part',
    laterality: 'Laterality',
    body_site: 'Body Site',
    scan_type: 'Scan Type',
    body_region: 'Body Region',
    protocol: 'Protocol',
    contrast_used: 'Contrast Used',
    coil: 'Coil',
};

export const radiologyNotReadyReasonLabel: Record<RadiologyNotReadyReason, string> = {
    preparation_incomplete: 'Preparation Incomplete',
    identity_issue: 'Identity Issue',
    consent_pending: 'Consent Pending',
    clinical_review_required: 'Clinical Review Required',
    equipment_unavailable: 'Equipment Unavailable',
    patient_condition_changed: 'Patient Condition Changed',
    other: 'Other',
};

export const radiologyConsentStatusLabel: Record<RadiologyConsentStatus, string> = {
    not_required: 'Not Required',
    pending: 'Pending',
    obtained: 'Obtained',
    declined: 'Declined',
};

export const radiologyConsentStatusVariant: Record<RadiologyConsentStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    not_required: 'secondary',
    pending: 'outline',
    obtained: 'default',
    declined: 'destructive',
};

export const radiologyOrderPriorityLabel: Record<RadiologyOrderPriority, string> = {
    routine: 'Routine',
    urgent: 'Urgent',
    stat: 'STAT',
};

export const radiologyOrderPriorityVariant: Record<RadiologyOrderPriority, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    routine: 'outline',
    urgent: 'default',
    stat: 'destructive',
};

export const radiologyProcedureStatusVariant: Record<RadiologyProcedureStatus, 'default' | 'secondary'> = {
    active: 'default',
    inactive: 'secondary',
};

export const radiologyModalityLabel: Record<RadiologyModality, string> = {
    x_ray: 'X-Ray',
    ultrasound: 'Ultrasound',
    ct: 'CT',
    mri: 'MRI',
    mammography: 'Mammography',
    fluoroscopy: 'Fluoroscopy',
    other: 'Other',
};

export const radiologyBodySiteLabel: Record<RadiologyBodySite, string> = {
    chest: 'Chest',
    abdomen: 'Abdomen',
    pelvis: 'Pelvis',
    head: 'Head',
    brain: 'Brain',
    spine: 'Spine',
    cervical_spine: 'Cervical Spine',
    thoracic_spine: 'Thoracic Spine',
    lumbar_spine: 'Lumbar Spine',
    neck: 'Neck',
    upper_limb: 'Upper Limb',
    lower_limb: 'Lower Limb',
    knee: 'Knee',
    shoulder: 'Shoulder',
    ankle: 'Ankle',
    foot: 'Foot',
    hand: 'Hand',
    wrist: 'Wrist',
    skull: 'Skull',
    breast: 'Breast',
    kidney: 'Kidney',
    uterus: 'Uterus',
    thyroid: 'Thyroid',
    scrotum: 'Scrotum',
    prostate: 'Prostate',
    other: 'Other',
};

export const radiologyLateralityLabel: Record<RadiologyLaterality, string> = {
    not_applicable: 'Not Applicable',
    left: 'Left',
    right: 'Right',
    bilateral: 'Bilateral',
    midline: 'Midline',
};

export const radiologyContrastRequirementLabel: Record<RadiologyContrastRequirement, string> = {
    no_contrast: 'Not Required',
    optional: 'May Be Required',
    required: 'Required',
};

export const radiologyContrastTypeLabel: Record<RadiologyContrastType, string> = {
    oral: 'Oral',
    intravenous: 'Intravenous',
    rectal: 'Rectal',
    other: 'Other',
};

export const radiologyPreparationTypeLabel: Record<RadiologyPreparationType, string> = {
    none: 'None',
    fasting: 'Fasting',
    full_bladder: 'Full Bladder',
    empty_bladder: 'Empty Bladder',
    oral_preparation: 'Oral Preparation',
    contrast_preparation: 'Contrast Preparation',
    mri_safety_screening: 'MRI Safety Screening',
    other: 'Other',
};
