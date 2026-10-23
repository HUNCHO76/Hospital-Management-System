import {
    type LaboratoryAbnormalFlag,
    type LaboratoryOrderItem,
    type LaboratoryOrderPriority,
    type LaboratoryOrderStatus,
    type LaboratoryProcessingStatus,
    type LaboratoryResultStatus,
    type LaboratoryResultType,
    type LaboratoryReturnReason,
    type LaboratorySampleRejectionReason,
    type LaboratorySampleStatus,
    type LaboratorySpecimenContainer,
    type LaboratorySpecimenType,
    type LaboratoryTestStatus,
} from '@/types/laboratory';

export const laboratoryOrderStatusLabel: Record<LaboratoryOrderStatus, string> = {
    draft: 'Draft',
    ordered: 'Ordered',
    sample_pending: 'Sample Pending',
    sample_collected: 'Sample Collected',
    received: 'Received',
    in_progress: 'In Progress',
    result_pending: 'Result Pending',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

export const laboratoryOrderStatusVariant: Record<LaboratoryOrderStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    draft: 'outline',
    ordered: 'outline',
    sample_pending: 'secondary',
    sample_collected: 'secondary',
    received: 'secondary',
    in_progress: 'default',
    result_pending: 'default',
    completed: 'secondary',
    cancelled: 'destructive',
};

export const laboratoryOrderPriorityLabel: Record<LaboratoryOrderPriority, string> = {
    routine: 'Routine',
    urgent: 'Urgent',
    stat: 'STAT',
};

export const laboratoryOrderPriorityVariant: Record<LaboratoryOrderPriority, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    routine: 'outline',
    urgent: 'default',
    stat: 'destructive',
};

export const laboratoryTestStatusVariant: Record<LaboratoryTestStatus, 'default' | 'secondary'> = {
    active: 'default',
    inactive: 'secondary',
};

export const laboratorySpecimenTypeLabel: Record<LaboratorySpecimenType, string> = {
    whole_blood: 'Whole Blood',
    serum: 'Serum',
    plasma: 'Plasma',
    urine: 'Urine',
    stool: 'Stool',
    sputum: 'Sputum',
    swab: 'Swab',
    csf: 'CSF',
    body_fluid: 'Body Fluid',
    tissue: 'Tissue',
    other: 'Other',
};

export const laboratorySpecimenContainerLabel: Record<LaboratorySpecimenContainer, string> = {
    edta_tube: 'EDTA Tube',
    plain_tube: 'Plain Tube',
    fluoride_oxalate_tube: 'Fluoride Oxalate Tube',
    sterile_container: 'Sterile Container',
    universal_container: 'Universal Container',
    swab_transport_medium: 'Swab Transport Medium',
    other: 'Other',
};

export const laboratoryResultTypeLabel: Record<LaboratoryResultType, string> = {
    numeric: 'Numeric',
    text: 'Text',
    boolean: 'Boolean',
    positive_negative: 'Positive / Negative',
    qualitative: 'Qualitative',
    enum: 'Enumerated',
};

export const laboratorySampleStatusLabel: Record<LaboratorySampleStatus, string> = {
    pending_collection: 'Pending Collection',
    collected: 'Collected',
    labelled: 'Labelled',
    received: 'Received',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
};

export const laboratorySampleStatusVariant: Record<LaboratorySampleStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    pending_collection: 'outline',
    collected: 'secondary',
    labelled: 'secondary',
    received: 'default',
    rejected: 'destructive',
    cancelled: 'destructive',
};

export const laboratorySampleRejectionReasonLabel: Record<LaboratorySampleRejectionReason, string> = {
    insufficient_sample: 'Insufficient Sample',
    wrong_container: 'Wrong Container',
    wrong_specimen: 'Wrong Specimen',
    hemolysed: 'Hemolysed Sample',
    clotted: 'Clotted Sample',
    leaking_container: 'Leaking Container',
    unlabelled: 'Unlabelled Sample',
    mislabeled: 'Mislabeled Sample',
    contaminated: 'Contaminated Sample',
    other: 'Other',
};

/**
 * The single source of truth for which sample actions are available from a
 * given status, mirroring LaboratorySample::TRANSITIONS on the backend —
 * the backend re-validates independently; this only drives which buttons
 * the UI offers.
 */
export function canCollectSample(status: LaboratorySampleStatus): boolean {
    return status === 'pending_collection';
}

export function canCancelSample(status: LaboratorySampleStatus): boolean {
    return status === 'pending_collection';
}

export function canLabelSample(status: LaboratorySampleStatus): boolean {
    return status === 'collected' || status === 'labelled';
}

export function canRejectSample(status: LaboratorySampleStatus): boolean {
    return status === 'collected';
}

export function canReceiveSample(status: LaboratorySampleStatus): boolean {
    return status === 'labelled';
}

export function canRecollectSample(status: LaboratorySampleStatus): boolean {
    return status === 'rejected';
}

export const laboratoryProcessingStatusLabel: Record<LaboratoryProcessingStatus, string> = {
    received: 'Received',
    accepted: 'Accepted',
    assigned: 'Assigned',
    in_progress: 'In Progress',
    processing_complete: 'Processing Complete',
    result_pending: 'Result Pending',
    rejected: 'Rejected',
};

export const laboratoryProcessingStatusVariant: Record<LaboratoryProcessingStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    received: 'outline',
    accepted: 'secondary',
    assigned: 'secondary',
    in_progress: 'default',
    processing_complete: 'secondary',
    result_pending: 'default',
    rejected: 'destructive',
};

/**
 * The single source of truth for which processing actions are available
 * from a given status, mirroring LaboratoryProcessing::TRANSITIONS on the
 * backend — the backend re-validates independently; this only drives
 * which buttons the UI offers. Accept/reject act on the whole specimen
 * (LaboratorySample::status), not a single record — see
 * canAcceptSpecimen()/canRejectDuringProcessing() below.
 */
export function canAssignProcessing(status: LaboratoryProcessingStatus): boolean {
    return status === 'accepted' || status === 'assigned';
}

export function canStartProcessing(status: LaboratoryProcessingStatus): boolean {
    return status === 'accepted' || status === 'assigned';
}

export function canCompleteProcessing(status: LaboratoryProcessingStatus): boolean {
    return status === 'in_progress';
}

export function canTransferProcessing(status: LaboratoryProcessingStatus): boolean {
    return status === 'assigned' || status === 'in_progress';
}

/**
 * Gates on both the specimen's own status (must be RECEIVED — Step 31's own
 * boundary) and its rolled-up processing status (must still be RECEIVED,
 * i.e. not yet accepted). Without the second check this would keep
 * offering "Accept" forever after the specimen was already accepted, since
 * LaboratorySample.status itself never advances past "received" — only its
 * LaboratoryProcessing records do (see LaboratorySampleResource::
 * processingStatusSummary()). The backend re-validates independently
 * either way (LaboratoryProcessingService::acceptSample()).
 */
export function canAcceptSpecimen(sampleStatus: LaboratorySampleStatus, processingStatus?: LaboratoryProcessingStatus | null): boolean {
    return sampleStatus === 'received' && (processingStatus ?? 'received') === 'received';
}

/**
 * Reject-during-processing stays available through accepted/assigned/
 * in_progress (a specimen can be found unsuitable at any point before its
 * processing is actually complete) but must turn off once any one of the
 * specimen's tests has reached result_pending or the specimen is already
 * rejected — mirrors the guard enforced server-side in
 * LaboratoryProcessingService::rejectSample().
 */
export function canRejectDuringProcessing(sampleStatus: LaboratorySampleStatus, processingStatus?: LaboratoryProcessingStatus | null): boolean {
    return sampleStatus === 'received' && processingStatus !== 'result_pending' && processingStatus !== 'rejected';
}

export const laboratoryResultStatusLabel: Record<LaboratoryResultStatus, string> = {
    result_pending: 'Result Pending',
    draft: 'Draft',
    pending_verification: 'Pending Verification',
    under_review: 'Under Review',
    returned_for_correction: 'Returned for Correction',
    verified: 'Verified',
    authorized: 'Authorized',
    released: 'Released',
};

export const laboratoryResultStatusVariant: Record<LaboratoryResultStatus, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    result_pending: 'outline',
    draft: 'secondary',
    pending_verification: 'default',
    under_review: 'default',
    returned_for_correction: 'destructive',
    verified: 'secondary',
    authorized: 'default',
    released: 'secondary',
};

export const laboratoryReturnReasonLabel: Record<LaboratoryReturnReason, string> = {
    incorrect_value: 'Incorrect Value',
    wrong_unit: 'Wrong Unit',
    missing_component: 'Missing Component',
    wrong_specimen: 'Wrong Specimen',
    transcription_error: 'Transcription Error',
    insufficient_clinical_information: 'Insufficient Clinical Information',
    other: 'Other',
};

export const laboratoryAbnormalFlagLabel: Record<LaboratoryAbnormalFlag, string> = {
    normal: 'Within reference',
    low: 'Below reference',
    high: 'Above reference',
    critical: 'Critical alert',
    critical_low: 'Critical alert (Low)',
    critical_high: 'Critical alert (High)',
    abnormal: 'Abnormal',
    unconfigured: 'Reference range not configured',
};

export const laboratoryAbnormalFlagVariant: Record<LaboratoryAbnormalFlag, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    normal: 'secondary',
    low: 'default',
    high: 'default',
    critical: 'destructive',
    critical_low: 'destructive',
    critical_high: 'destructive',
    abnormal: 'default',
    unconfigured: 'outline',
};

export const laboratorySpecimenSourceLabel: Record<string, string> = {
    arterial: 'Arterial',
    venous: 'Venous',
    capillary: 'Capillary',
    any: 'Any',
};

/**
 * The single source of truth for which result actions are available from a
 * given status, mirroring LaboratoryResult::TRANSITIONS on the backend —
 * the backend re-validates independently; this only drives which buttons
 * the UI offers.
 */
export function canSaveResultDraft(status: LaboratoryResultStatus): boolean {
    return status === 'result_pending' || status === 'draft' || status === 'returned_for_correction';
}

export function canSubmitResult(status: LaboratoryResultStatus): boolean {
    return status === 'draft';
}

/**
 * Pre-verification amendment (Step 33) — gated by the `amend` permission.
 */
export function canAmendResult(status: LaboratoryResultStatus): boolean {
    return status === 'pending_verification';
}

/**
 * Post-verification/authorization/release controlled amendment (Step 34,
 * extended by Step 35) — gated by the stricter `amend_verified` permission.
 */
export function canAmendVerifiedResult(status: LaboratoryResultStatus): boolean {
    return status === 'verified' || status === 'authorized' || status === 'released';
}

export function canStartReview(status: LaboratoryResultStatus): boolean {
    return status === 'pending_verification';
}

export function canVerifyResult(status: LaboratoryResultStatus): boolean {
    return status === 'under_review';
}

export function canReturnForCorrection(status: LaboratoryResultStatus): boolean {
    return status === 'under_review';
}

export function canAuthorizeResult(status: LaboratoryResultStatus): boolean {
    return status === 'verified';
}

export function canReleaseResult(status: LaboratoryResultStatus): boolean {
    return status === 'authorized';
}

/**
 * The order's own `status` is a rollup computed purely from item status
 * (LaboratoryOrder::statusFromItemProgress()), which — like the item status
 * it's built from — never advances past "result_pending" once processing
 * completes; it has no way to reflect that every test's result has since
 * been through verification and release. This gives the order header a
 * second, results-focused signal to show alongside that (still-accurate,
 * for the pre-result pipeline) rollup, without touching the persisted
 * column or its well-tested transition logic.
 *
 * Returns null when no item has a result yet (the order status badge alone
 * already tells the whole story), 'released' once every non-cancelled
 * item's result has been released, or 'in_progress' when at least one
 * result exists but not all of them are released yet.
 */
export function orderResultsSummary(items: LaboratoryOrderItem[]): 'released' | 'in_progress' | null {
    const relevant = items.filter((item) => item.status !== 'cancelled');
    const withResults = relevant.filter((item) => item.result);

    if (withResults.length === 0) {
        return null;
    }

    return relevant.every((item) => item.result?.status === 'released') ? 'released' : 'in_progress';
}
