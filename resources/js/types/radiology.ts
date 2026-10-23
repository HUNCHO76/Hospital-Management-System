export type RadiologyOrderPriority = 'routine' | 'urgent' | 'stat';

/**
 * 'scheduled' is a derived rollup (Phase 5, Step 39, Part 21) set once
 * every non-cancelled item on the order has an active appointment — never a
 * directly user-driven transition. Examination/reporting/release states
 * belong to Steps 40-44.
 */
export type RadiologyOrderStatus = 'draft' | 'ordered' | 'scheduled' | 'cancelled';

/**
 * A radiology appointment's own lifecycle (Phase 5, Steps 39-41) — distinct
 * from RadiologyOrderStatus above, though it shares two string values by
 * coincidence. IN_PROGRESS/COMPLETED/ABORTED are the actual examination
 * (Step 41): READY -> IN_PROGRESS -> COMPLETED, or READY -> IN_PROGRESS ->
 * ABORTED.
 */
export type RadiologyAppointmentStatus =
    | 'scheduled'
    | 'confirmed'
    | 'arrived'
    | 'preparation_pending'
    | 'preparation_in_progress'
    | 'ready'
    | 'not_ready'
    | 'in_progress'
    | 'completed'
    | 'aborted'
    | 'cancelled'
    | 'no_show';

export type RadiologyAbortReason =
    | 'patient_unable_to_tolerate'
    | 'equipment_problem'
    | 'patient_condition_changed'
    | 'patient_refused'
    | 'incorrect_preparation'
    | 'safety_concern'
    | 'technical_failure'
    | 'other';

/**
 * The modality-specific technical fields recognized in `technical_details`
 * (Phase 5, Step 41, Part 9) — mirrors
 * RadiologyAppointment::TECHNICAL_FIELDS_BY_MODALITY exactly. Passed from
 * the backend as `technicalFields` on the examination detail page rather
 * than hard-coded twice.
 */
export type RadiologyTechnicalFieldsByModality = Partial<Record<RadiologyModality, string[]>>;

/**
 * One imaging file attached to an examination. Deliberately has no `url`/
 * `path` field — the storage key is never sent to the browser; every
 * viewer/thumbnail builds its `src` from `route('radiology-images.show',
 * [appointmentId, image.id])`, which re-authorizes on every request.
 */
export interface RadiologyImage {
    id: number;
    radiology_appointment_id: number;
    original_filename: string;
    mime_type: string;
    size_bytes: number;
    uploaded_by: RadiologyOrderUser | null;
    created_at: string;
}

export type RadiologyNotReadyReason =
    | 'preparation_incomplete'
    | 'identity_issue'
    | 'consent_pending'
    | 'clinical_review_required'
    | 'equipment_unavailable'
    | 'patient_condition_changed'
    | 'other';

export type RadiologyConsentStatus = 'not_required' | 'pending' | 'obtained' | 'declined';

export type RadiologyProcedureStatus = 'active' | 'inactive';

/**
 * Serves both the catalog's "Category" and "Modality" concepts (Phase 5,
 * Step 37, Parts 6-7) — see RadiologyProcedure's own PHP docblock for why
 * there is only one field rather than two overlapping ones.
 */
export type RadiologyModality = 'x_ray' | 'ultrasound' | 'ct' | 'mri' | 'mammography' | 'fluoroscopy' | 'other';

export type RadiologyBodySite =
    | 'chest'
    | 'abdomen'
    | 'pelvis'
    | 'head'
    | 'brain'
    | 'spine'
    | 'cervical_spine'
    | 'thoracic_spine'
    | 'lumbar_spine'
    | 'neck'
    | 'upper_limb'
    | 'lower_limb'
    | 'knee'
    | 'shoulder'
    | 'ankle'
    | 'foot'
    | 'hand'
    | 'wrist'
    | 'skull'
    | 'breast'
    | 'kidney'
    | 'uterus'
    | 'thyroid'
    | 'scrotum'
    | 'prostate'
    | 'other';

export type RadiologyLaterality = 'not_applicable' | 'left' | 'right' | 'bilateral' | 'midline';

export type RadiologyContrastRequirement = 'no_contrast' | 'optional' | 'required';

export type RadiologyContrastType = 'oral' | 'intravenous' | 'rectal' | 'other';

export type RadiologyPreparationType =
    | 'none'
    | 'fasting'
    | 'full_bladder'
    | 'empty_bladder'
    | 'oral_preparation'
    | 'contrast_preparation'
    | 'mri_safety_screening'
    | 'other';

export interface RadiologyProcedureSearchResult {
    id: number;
    code: string;
    name: string;
    modality?: RadiologyModality | null;
    preparation_type?: RadiologyPreparationType | null;
    nhif_code?: string | null;
}

export interface NhifRadiologyCatalogItem {
    item_code: string;
    item_name: string;
    local_procedure?: RadiologyProcedureSearchResult | null;
}

export interface RadiologyOrderUser {
    id: number;
    name: string;
}

export interface RadiologyProcedureDepartment {
    id: number;
    name: string;
    code: string;
}

export interface RadiologyProcedure {
    id: number;
    code: string;
    name: string;
    description: string | null;
    modality: RadiologyModality | null;
    body_sites: RadiologyBodySite[] | null;
    laterality: RadiologyLaterality;
    contrast_requirement: RadiologyContrastRequirement;
    contrast_type: RadiologyContrastType | null;
    preparation_type: RadiologyPreparationType;
    preparation_instructions: string | null;
    turnaround_time_minutes: number | null;
    sort_order: number;
    department: RadiologyProcedureDepartment | null;
    nhif_code: string | null;
    price: string | null;
    status: RadiologyProcedureStatus;
    created_by: RadiologyOrderUser | null;
    updated_by: RadiologyOrderUser | null;
    created_at: string;
    updated_at: string;
}

export interface RadiologyProcedureFormData {
    code: string;
    name: string;
    description: string;
    modality: RadiologyModality | '';
    body_sites: RadiologyBodySite[];
    laterality: RadiologyLaterality;
    contrast_requirement: RadiologyContrastRequirement;
    contrast_type: RadiologyContrastType | '';
    preparation_type: RadiologyPreparationType;
    preparation_instructions: string;
    turnaround_time_minutes: string;
    sort_order: string;
    department_id: string;
    nhif_code: string;
    price: string;
    [key: string]: string | string[] | number[];
}

export interface RadiologyProcedureFilters {
    search: string;
    modality: string;
    department_id: string;
    status: string;
}

export interface RadiologyOrderPatient {
    id: number;
    patient_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    date_of_birth: string | null;
    gender: string | null;
}

export interface RadiologyOrderEncounter {
    id: number;
    encounter_number: string;
}

export interface RadiologyOrderItemAppointment {
    id: number;
    status: RadiologyAppointmentStatus;
    resource_name: string;
    scheduled_start: string | null;
}

export interface RadiologyOrderItem {
    id: number;
    radiology_order_id: number;
    radiology_procedure_id: number;
    procedure_code: string;
    procedure_name: string;
    modality_snapshot: RadiologyModality | null;
    body_site_snapshot: RadiologyBodySite[] | null;
    clinical_note: string | null;
    status: RadiologyOrderStatus;
    cancelled_at: string | null;
    cancelled_by: RadiologyOrderUser | null;
    cancellation_reason: string | null;
    /** Only present when the caller eager-loads it (currently: the order detail page). */
    appointment?: RadiologyOrderItemAppointment | null;
}

export interface RadiologyOrder {
    id: number;
    clinical_encounter_id: number;
    order_number: string;
    priority: RadiologyOrderPriority;
    clinical_indication: string | null;
    notes: string | null;
    status: RadiologyOrderStatus;
    items: RadiologyOrderItem[];
    ordered_by: RadiologyOrderUser | null;
    ordered_at: string;
    updated_by: RadiologyOrderUser | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    patient: RadiologyOrderPatient | null;
    encounter: RadiologyOrderEncounter | null;
    nhif_pre_approval?: {
        status: string;
        label: string;
        blocked: boolean;
        message: string;
        session_id?: number | null;
        can_issue?: boolean;
    } | null;
    created_at: string;
}

export interface RadiologyOrderFormData {
    procedure_ids: number[];
    priority: RadiologyOrderPriority | '';
    clinical_indication: string;
    notes: string;
    send_preapproval?: boolean;
    [key: string]: string | number[] | boolean | undefined;
}

export interface RadiologyOrderFilters {
    search: string;
    status: string;
    priority: string;
    modality: string;
    date_from: string;
    date_to: string;
}

export interface UnscheduledRadiologyOrderItemOrder {
    id: number;
    order_number: string;
    priority: RadiologyOrderPriority;
    clinical_indication: string | null;
    ordered_at: string | null;
}

export interface UnscheduledRadiologyOrderItem {
    id: number;
    procedure_code: string;
    procedure_name: string;
    modality_snapshot: RadiologyModality | null;
    body_site_snapshot: RadiologyBodySite[] | null;
    order: UnscheduledRadiologyOrderItemOrder | null;
    encounter: RadiologyOrderEncounter | null;
    patient: RadiologyOrderPatient | null;
}

export interface RadiologyAppointmentItem {
    id: number;
    procedure_code: string;
    procedure_name: string;
}

export interface RadiologyAppointmentOrder {
    id: number;
    order_number: string;
    priority: RadiologyOrderPriority;
    clinical_indication: string | null;
}

export interface RadiologyAppointmentProcedureInfo {
    preparation_type: RadiologyPreparationType;
    preparation_instructions: string | null;
    contrast_requirement: RadiologyContrastRequirement;
    contrast_type: RadiologyContrastType | null;
}

export interface RadiologyAppointment {
    id: number;
    radiology_order_item_id: number;
    modality: RadiologyModality | null;
    resource_name: string;
    scheduled_start: string;
    scheduled_end: string;
    status: RadiologyAppointmentStatus;
    notes: string | null;
    scheduled_by: RadiologyOrderUser | null;
    confirmed_at: string | null;
    rescheduled_at: string | null;
    reschedule_reason: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    arrived_at: string | null;
    arrived_by: RadiologyOrderUser | null;
    verified_at: string | null;
    verified_by: RadiologyOrderUser | null;
    instructions_given_at: string | null;
    preparation_completed_at: string | null;
    preparation_notes: string | null;
    preparation_waived_at: string | null;
    preparation_waived_reason: string | null;
    mri_screening_completed_at: string | null;
    mri_screening_flagged: boolean;
    mri_screening_notes: string | null;
    contrast_reviewed_at: string | null;
    consent_status: RadiologyConsentStatus;
    consent_recorded_at: string | null;
    not_ready_reason: RadiologyNotReadyReason | null;
    ready_at: string | null;
    ready_by: RadiologyOrderUser | null;
    performed_by: RadiologyOrderUser | null;
    started_at: string | null;
    completed_at: string | null;
    completed_by: RadiologyOrderUser | null;
    technical_notes: string | null;
    technical_details: Record<string, string> | null;
    aborted_at: string | null;
    aborted_by: RadiologyOrderUser | null;
    abort_reason: RadiologyAbortReason | null;
    /** Only present when the caller eager-loads it (currently: the examination detail page). */
    images?: RadiologyImage[];
    item: RadiologyAppointmentItem | null;
    order: RadiologyAppointmentOrder | null;
    encounter: RadiologyOrderEncounter | null;
    patient: RadiologyOrderPatient | null;
    procedure: RadiologyAppointmentProcedureInfo | null;
    created_at: string;
}

export interface ScheduleRadiologyOrderItemFormData {
    radiology_order_item_id: number | null;
    resource_name: string;
    scheduled_start: string;
    scheduled_end: string;
    notes: string;
    [key: string]: string | number | null;
}

export interface RadiologySchedulingUnscheduledFilters {
    search: string;
    modality: string;
    priority: string;
}

export interface RadiologyWorklistFilters {
    search: string;
    status: string;
    modality: string;
    resource_name: string;
    priority: string;
    date: string;
    date_from: string;
    date_to: string;
    overdue: string;
}

export interface RadiologyReceptionAppointment extends RadiologyAppointment {
    readiness_blockers: string[];
}

export interface RadiologyReceptionFilters {
    search: string;
    status: string;
    arrival_status: string;
    date: string;
    date_from: string;
    date_to: string;
}

export interface RadiologyExaminationWorklistFilters {
    search: string;
    status: string;
    modality: string;
    resource_name: string;
    priority: string;
    date: string;
    date_from: string;
    date_to: string;
}

export interface StartRadiologyExaminationFormData {
    resource_name: string;
    [key: string]: string;
}

export interface RadiologyExaminationTechnicalFormData {
    technical_notes: string;
    technical_details: Record<string, string>;
    [key: string]: string | Record<string, string>;
}

export interface AbortRadiologyExaminationFormData {
    reason: RadiologyAbortReason | '';
    [key: string]: string;
}

/**
 * A radiology report's own lifecycle (Phase 5, Steps 42-44): DRAFT ->
 * SUBMITTED -> VERIFIED -> RELEASED (terminal), or SUBMITTED -> RETURNED ->
 * (corrected) -> DRAFT -> SUBMITTED again.
 */
export type RadiologyReportStatus = 'draft' | 'submitted' | 'returned' | 'verified' | 'released';

/**
 * The controlled set of reasons a report may be returned for correction
 * (Phase 5, Step 43, Part 11).
 */
export type RadiologyReportReturnReason =
    | 'incomplete_findings'
    | 'unclear_impression'
    | 'incorrect_technique'
    | 'missing_recommendation'
    | 'clinical_inconsistency'
    | 'wrong_procedure_information'
    | 'typographical_error'
    | 'additional_review_required'
    | 'other';

export interface RadiologyReportPractitioner {
    id: number;
    name: string | null;
    specialization?: string | null;
}

export interface RadiologyReportExamination {
    id: number;
    modality: RadiologyModality | null;
    resource_name: string;
    status: RadiologyAppointmentStatus;
    performed_by: RadiologyOrderUser | null;
    started_at: string | null;
    completed_at: string | null;
    technical_notes: string | null;
    technical_details: Record<string, string> | null;
    images: RadiologyImage[];
}

export interface RadiologyReportProcedureInfo {
    laterality: RadiologyLaterality | null;
    body_sites: RadiologyBodySite[] | null;
}

export interface RadiologyReportItem {
    id: number;
    procedure_code: string;
    procedure_name: string;
    modality_snapshot: RadiologyModality | null;
    body_site_snapshot: RadiologyBodySite[] | null;
}

export interface RadiologyReport {
    id: number;
    report_number: string;
    radiology_appointment_id: number;
    status: RadiologyReportStatus;
    technique: string | null;
    findings: string | null;
    impression: string | null;
    recommendations: string | null;
    reporting_practitioner: RadiologyReportPractitioner | null;
    updated_by: RadiologyOrderUser | null;
    submitted_by: RadiologyOrderUser | null;
    submitted_at: string | null;
    verifying_practitioner: RadiologyReportPractitioner | null;
    verified_by: RadiologyOrderUser | null;
    verified_at: string | null;
    verification_notes: string | null;
    returned_by: RadiologyOrderUser | null;
    returned_at: string | null;
    return_reason: RadiologyReportReturnReason | null;
    return_comments: string | null;
    released_by: RadiologyOrderUser | null;
    released_at: string | null;
    release_notes: string | null;
    created_at: string;
    updated_at: string;
    examination: RadiologyReportExamination | null;
    item: RadiologyReportItem | null;
    order: (RadiologyAppointmentOrder & { ordered_by: RadiologyOrderUser | null; ordered_at: string | null }) | null;
    encounter: (RadiologyOrderEncounter & { started_at: string | null }) | null;
    patient: RadiologyOrderPatient | null;
    procedure: RadiologyReportProcedureInfo | null;
}

export interface RadiologyReportWorklistAppointment extends RadiologyAppointment {
    report: {
        id: number;
        report_number: string;
        status: RadiologyReportStatus;
        reporting_practitioner: RadiologyReportPractitioner | null;
    } | null;
}

export interface RadiologyReportWorklistFilters {
    search: string;
    report_status: string;
    modality: string;
    priority: string;
    date: string;
    date_from: string;
    date_to: string;
}

export interface RadiologyReportFormData {
    technique: string;
    findings: string;
    impression: string;
    recommendations: string;
    [key: string]: string;
}

export interface CreateRadiologyReportFormData {
    radiology_appointment_id: number | null;
    technique: string;
    findings: string;
    impression: string;
    recommendations: string;
    [key: string]: string | number | null;
}

export interface RadiologyVerificationFilters {
    search: string;
    status: string;
    modality: string;
    priority: string;
    reporting_practitioner: string;
    date: string;
    date_from: string;
    date_to: string;
}

export interface VerifyRadiologyReportFormData {
    verification_notes: string;
    [key: string]: string;
}

export interface ReturnRadiologyReportFormData {
    return_reason: RadiologyReportReturnReason | '';
    return_comments: string;
    [key: string]: string;
}

export interface RadiologyReportHistoryEntry {
    id: number;
    action: string;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    user: RadiologyOrderUser | null;
    created_at: string | null;
}

export interface RadiologyReleaseFilters {
    search: string;
    status: string;
    modality: string;
    priority: string;
    reporting_practitioner: string;
    verifier: string;
    date: string;
    date_from: string;
    date_to: string;
}

export interface ReleaseRadiologyResultFormData {
    release_notes: string;
    [key: string]: string;
}

/**
 * One RELEASED radiology result — the shape returned by
 * RadiologyResultResource, used both on the clinical encounter page (Phase
 * 5, Step 44, Part 16) and the patient's clinical history timeline (Part
 * 17).
 */
export interface RadiologyResult {
    id: number;
    report_number: string;
    status: RadiologyReportStatus;
    radiology_appointment_id: number;
    procedure_code: string | null;
    procedure_name: string | null;
    modality: RadiologyModality | null;
    body_site_snapshot: RadiologyBodySite[] | null;
    examination_completed_at: string | null;
    technique: string | null;
    findings: string | null;
    impression: string | null;
    recommendations: string | null;
    reporting_practitioner: RadiologyReportPractitioner | null;
    verifying_practitioner: RadiologyReportPractitioner | null;
    verified_at: string | null;
    released_by: RadiologyOrderUser | null;
    released_at: string | null;
    images: RadiologyImage[];
}
