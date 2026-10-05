export type LaboratoryOrderPriority = 'routine' | 'urgent' | 'stat';

export type LaboratoryOrderStatus =
    | 'draft'
    | 'ordered'
    | 'sample_pending'
    | 'sample_collected'
    | 'received'
    | 'in_progress'
    | 'result_pending'
    | 'completed'
    | 'cancelled';

export type LaboratoryTestStatus = 'active' | 'inactive';

export type LaboratorySpecimenType =
    | 'whole_blood'
    | 'serum'
    | 'plasma'
    | 'urine'
    | 'stool'
    | 'sputum'
    | 'swab'
    | 'csf'
    | 'body_fluid'
    | 'tissue'
    | 'other';

export type LaboratorySpecimenContainer =
    | 'edta_tube'
    | 'plain_tube'
    | 'fluoride_oxalate_tube'
    | 'sterile_container'
    | 'universal_container'
    | 'swab_transport_medium'
    | 'other';

export type LaboratoryResultType = 'numeric' | 'text' | 'boolean' | 'positive_negative' | 'qualitative' | 'enum';

export interface LaboratoryTestSearchResult {
    id: number;
    code: string;
    name: string;
    nhif_code?: string | null;
}

export interface NhifCatalogItem {
    item_code: string;
    item_name: string;
    local_test: { id: number; code: string; name: string } | null;
}

export interface LaboratoryOrderUser {
    id: number;
    name: string;
}

export interface LaboratoryOrderPatient {
    id: number;
    patient_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    date_of_birth: string | null;
    gender: string | null;
}

export interface LaboratoryOrderEncounter {
    id: number;
    encounter_number: string;
}

export interface LaboratoryOrderItemSampleSummary {
    id: number;
    sample_number: string;
    status: LaboratorySampleStatus;
}

export interface LaboratoryOrderItem {
    id: number;
    laboratory_order_id: number;
    laboratory_test_id: number;
    test_code: string;
    test_name: string;
    clinical_note: string | null;
    status: LaboratoryOrderStatus;
    // Read live from the catalog (Phase 4, Step 31) — only present when the
    // backend eager-loaded the item's laboratoryTest relation.
    specimen_type?: LaboratorySpecimenType | null;
    specimen_container?: LaboratorySpecimenContainer | null;
    samples?: LaboratoryOrderItemSampleSummary[];
    // The authoritative up-to-date progress once a result exists — `status`
    // above never advances past "result_pending" (Steps 33-35 track
    // submit/verify/authorize/release entirely on LaboratoryResult).
    result?: { id: number; status: LaboratoryResultStatus } | null;
    cancelled_at: string | null;
    cancelled_by: LaboratoryOrderUser | null;
    cancellation_reason: string | null;
}

export interface LaboratoryOrder {
    id: number;
    clinical_encounter_id: number;
    order_number: string;
    priority: LaboratoryOrderPriority;
    clinical_indication: string | null;
    notes: string | null;
    status: LaboratoryOrderStatus;
    items: LaboratoryOrderItem[];
    ordered_by: LaboratoryOrderUser | null;
    ordered_at: string;
    updated_by: LaboratoryOrderUser | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    patient: LaboratoryOrderPatient | null;
    encounter: LaboratoryOrderEncounter | null;
    created_at: string;
    financial_status?: 'authorized' | 'payment_required';
}

export interface LaboratoryOrderFormData {
    test_ids: number[];
    priority: LaboratoryOrderPriority | '';
    clinical_indication: string;
    notes: string;
    [key: string]: string | number[];
}

export interface LaboratoryOrderFilters {
    search: string;
    status: string;
    priority: string;
    date_from: string;
    date_to: string;
}

export interface LaboratoryTestCategory {
    id: number;
    name: string;
    code: string;
    description: string | null;
    status: LaboratoryTestStatus;
    created_by: LaboratoryOrderUser | null;
    updated_by: LaboratoryOrderUser | null;
    created_at: string;
    updated_at: string;
}

export interface LaboratoryTestCategoryOption {
    id: number;
    name: string;
    code: string;
}

export interface LaboratoryTestDepartment {
    id: number;
    name: string;
    code: string;
}

export interface LaboratoryTest {
    id: number;
    code: string;
    name: string;
    short_name: string | null;
    description: string | null;
    category: LaboratoryTestCategoryOption | null;
    department: LaboratoryTestDepartment | null;
    specimen_type: LaboratorySpecimenType | null;
    specimen_container: LaboratorySpecimenContainer | null;
    collection_notes: string | null;
    result_type: LaboratoryResultType | null;
    unit: string | null;
    reference_range: string | null;
    result_options: string[] | null;
    turnaround_time_minutes: number | null;
    default_priority: LaboratoryOrderPriority;
    effective_from: string | null;
    effective_to: string | null;
    nhif_code: string | null;
    price: string | null;
    status: LaboratoryTestStatus;
    parameters: LaboratoryTestParameter[];
    created_by: LaboratoryOrderUser | null;
    updated_by: LaboratoryOrderUser | null;
    created_at: string;
    updated_at: string;
}

export interface LaboratoryTestFormData {
    code: string;
    name: string;
    short_name: string;
    description: string;
    category_id: string;
    department_id: string;
    specimen_type: LaboratorySpecimenType | '';
    specimen_container: LaboratorySpecimenContainer | '';
    collection_notes: string;
    result_type: LaboratoryResultType | '';
    unit: string;
    reference_range: string;
    result_options: string;
    turnaround_time_minutes: string;
    default_priority: LaboratoryOrderPriority | '';
    effective_from: string;
    effective_to: string;
    nhif_code: string;
    price: string;
    [key: string]: string;
}

export interface LaboratoryTestFilters {
    search: string;
    category_id: string;
    department_id: string;
    specimen_type: string;
    result_type: string;
    status: string;
}

export interface LaboratoryCategoryFormData {
    name: string;
    code: string;
    description: string;
    [key: string]: string;
}

export interface LaboratoryCategoryFilters {
    search: string;
    status: string;
}

export type LaboratorySampleStatus = 'pending_collection' | 'collected' | 'labelled' | 'received' | 'rejected' | 'cancelled';

export type LaboratorySampleRejectionReason =
    | 'insufficient_sample'
    | 'wrong_container'
    | 'wrong_specimen'
    | 'hemolysed'
    | 'clotted'
    | 'leaking_container'
    | 'unlabelled'
    | 'mislabeled'
    | 'contaminated'
    | 'other';

export interface LaboratorySampleHistoryEntry {
    event: 'created' | 'collected' | 'labelled' | 'received' | 'rejected' | 'cancelled';
    at: string;
    actor: string | null;
    reason: string | null;
}

export interface LaboratorySampleItemSummary {
    id: number;
    test_code: string;
    test_name: string;
}

export interface LaboratorySamplePatient {
    id: number;
    patient_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    date_of_birth: string | null;
    gender: string | null;
}

export interface LaboratorySample {
    id: number;
    sample_number: string;
    laboratory_order_id: number;
    specimen_type: LaboratorySpecimenType;
    container_type: LaboratorySpecimenContainer | null;
    status: LaboratorySampleStatus;
    collection_site: string | null;
    collection_notes: string | null;
    collected_by: LaboratoryOrderUser | null;
    collected_at: string | null;
    labelled_at: string | null;
    received_by: LaboratoryOrderUser | null;
    received_at: string | null;
    rejected_by: LaboratoryOrderUser | null;
    rejected_at: string | null;
    rejection_reason: LaboratorySampleRejectionReason | null;
    rejection_notes: string | null;
    cancelled_by: LaboratoryOrderUser | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    recollection_of_sample_id: number | null;
    recollection_of: { id: number; sample_number: string } | null;
    recollections: { id: number; sample_number: string }[];
    items: LaboratorySampleItemSummary[];
    patient: LaboratorySamplePatient | null;
    laboratory_order: { id: number; order_number: string; priority: LaboratoryOrderPriority } | null;
    // Present only when the backend eager-loaded processingRecords (Phase
    // 4, Step 32) — the processing worklist/detail pages.
    processing_status?: LaboratoryProcessingStatus | null;
    processing_records?: LaboratoryProcessing[];
    history: LaboratorySampleHistoryEntry[];
    created_at: string;
}

export interface StoreLaboratorySampleFormData {
    item_ids: number[];
    specimen_type: LaboratorySpecimenType | '';
    container_type: LaboratorySpecimenContainer | '';
    [key: string]: string | number[];
}

export interface CollectLaboratorySampleFormData {
    collection_site: string;
    collection_notes: string;
    [key: string]: string;
}

export interface RejectLaboratorySampleFormData {
    rejection_reason: LaboratorySampleRejectionReason | '';
    rejection_notes: string;
    [key: string]: string;
}

export interface LaboratoryCollectionFilters {
    search: string;
    priority: string;
    status: string;
    date_from: string;
    date_to: string;
}

export type LaboratoryProcessingStatus = 'received' | 'accepted' | 'assigned' | 'in_progress' | 'processing_complete' | 'result_pending' | 'rejected';

export interface LaboratoryBench {
    id: number;
    name: string;
    code: string;
    description: string | null;
    status: LaboratoryTestStatus;
    created_at?: string;
    updated_at?: string;
}

export interface LaboratoryBenchFormData {
    name: string;
    code: string;
    description: string;
    [key: string]: string;
}

export interface LaboratoryBenchOption {
    id: number;
    name: string;
    code: string;
}

export interface LaboratoryProcessingHistoryEntry {
    event: 'received' | 'accepted' | 'assigned' | 'started' | 'completed';
    at: string;
    actor: string | null;
}

export interface LaboratoryProcessingItemSummary {
    id: number;
    test_code: string;
    test_name: string;
}

export interface LaboratoryProcessing {
    id: number;
    laboratory_sample_id: number;
    laboratory_order_item_id: number;
    status: LaboratoryProcessingStatus;
    item: LaboratoryProcessingItemSummary | null;
    bench: LaboratoryBenchOption | null;
    assigned_to: LaboratoryOrderUser | null;
    assigned_by: LaboratoryOrderUser | null;
    assigned_at: string | null;
    accepted_by: LaboratoryOrderUser | null;
    accepted_at: string | null;
    started_by: LaboratoryOrderUser | null;
    started_at: string | null;
    completed_by: LaboratoryOrderUser | null;
    completed_at: string | null;
    processing_notes: string | null;
    history: LaboratoryProcessingHistoryEntry[];
    created_at: string;
}

export interface AssignProcessingFormData {
    assigned_to: string;
    bench_id: string;
    [key: string]: string;
}

export interface StartProcessingFormData {
    processing_notes: string;
    [key: string]: string;
}

export interface CompleteProcessingFormData {
    processing_notes: string;
    [key: string]: string;
}

export interface TransferProcessingFormData {
    bench_id: string;
    transfer_reason: string;
    [key: string]: string;
}

export interface LaboratoryProcessingFilters {
    search: string;
    status: string;
    priority: string;
    specimen_type: string;
    assigned: string;
    bench_id: string;
    date_from: string;
    date_to: string;
}

export type LaboratoryResultStatus =
    | 'result_pending'
    | 'draft'
    | 'pending_verification'
    | 'under_review'
    | 'returned_for_correction'
    | 'verified'
    | 'authorized'
    | 'released';

export type LaboratoryResultSource = 'manual' | 'machine_document';

export interface LaboratoryResultDocument {
    id: number;
    original_filename: string;
    mime_type: string;
    size_bytes: number;
    uploaded_by: LaboratoryOrderUser | null;
    uploaded_at: string;
}

export type LaboratoryAbnormalFlag = 'normal' | 'low' | 'high' | 'critical' | 'abnormal';

export type LaboratoryReturnReason =
    | 'incorrect_value'
    | 'wrong_unit'
    | 'missing_component'
    | 'wrong_specimen'
    | 'transcription_error'
    | 'insufficient_clinical_information'
    | 'other';

export interface LaboratoryResultHistoryEntry {
    event: 'created' | 'entered' | 'submitted' | 'returned' | 'reviewed' | 'verified' | 'authorized' | 'released' | 'amended';
    at: string;
    actor: string | null;
}

export interface LaboratoryActiveReportSummary {
    id: number;
    report_number: string;
    version: number;
}

export interface LaboratoryResultParameterSummary {
    id: number;
    code: string;
    name: string;
    data_type: LaboratoryParameterDataType;
    unit: string | null;
    decimal_places: number | null;
    options_json: string[] | null;
    display_order: number;
}

export interface LaboratoryResultValue {
    id: number;
    laboratory_test_parameter_id: number;
    parameter: LaboratoryResultParameterSummary | null;
    value_numeric: string | null;
    value_text: string | null;
    value_code: string | null;
    unit: string | null;
    reference_low: string | null;
    reference_high: string | null;
    reference_critical_low: string | null;
    reference_critical_high: string | null;
    abnormal_flag: LaboratoryAbnormalFlag | null;
    result_comment: string | null;
}

export interface LaboratoryTestSummary {
    id: number;
    code: string;
    name: string;
    active_parameters: LaboratoryResultParameterSummary[];
}

export interface LaboratoryResultItemSummary {
    id: number;
    test_code: string;
    test_name: string;
}

export interface LaboratoryResultSampleSummary {
    id: number;
    sample_number: string;
    specimen_type: LaboratorySpecimenType;
    container_type: LaboratorySpecimenContainer | null;
    received_at: string | null;
}

export interface LaboratoryResult {
    id: number;
    laboratory_order_item_id: number;
    laboratory_sample_id: number;
    laboratory_test_id: number;
    status: LaboratoryResultStatus;
    result_source: LaboratoryResultSource | null;
    result_type: LaboratoryResultType | null;
    unit: string | null;
    reference_range: string | null;
    result_options: string[] | null;
    value_numeric: string | null;
    value_text: string | null;
    value_code: string | null;
    abnormal_flag: LaboratoryAbnormalFlag | null;
    result_comment: string | null;
    item: LaboratoryResultItemSummary | null;
    sample: LaboratoryResultSampleSummary | null;
    laboratory_order: { id: number; order_number: string; priority: LaboratoryOrderPriority } | null;
    patient: LaboratorySamplePatient | null;
    entered_by: LaboratoryOrderUser | null;
    entered_at: string | null;
    submitted_by: LaboratoryOrderUser | null;
    submitted_at: string | null;
    amended_by: LaboratoryOrderUser | null;
    amended_at: string | null;
    reviewed_by: LaboratoryOrderUser | null;
    reviewed_at: string | null;
    verified_by: LaboratoryOrderUser | null;
    verified_at: string | null;
    verification_comment: string | null;
    authorized_by: LaboratoryOrderUser | null;
    authorized_at: string | null;
    returned_by: LaboratoryOrderUser | null;
    returned_at: string | null;
    return_reason: LaboratoryReturnReason | null;
    return_notes: string | null;
    released_by: LaboratoryOrderUser | null;
    released_at: string | null;
    release_comment: string | null;
    is_ready_for_release: boolean;
    is_released: boolean;
    active_report: LaboratoryActiveReportSummary | null;
    result_values: LaboratoryResultValue[];
    laboratory_test: LaboratoryTestSummary | null;
    documents: LaboratoryResultDocument[];
    history: LaboratoryResultHistoryEntry[];
    created_at: string;
}

export interface ParameterValueEntry {
    parameter_id: number;
    value_numeric: string;
    value_text: string;
    value_code: string;
    result_comment: string;
    [key: string]: string | number;
}

export interface SaveLaboratoryResultDraftFormData {
    value_numeric: string;
    value_text: string;
    value_code: string;
    abnormal_flag: string;
    result_comment: string;
    parameter_values: ParameterValueEntry[];
    [key: string]: string | ParameterValueEntry[];
}

export interface AmendLaboratoryResultFormData {
    value_numeric: string;
    value_text: string;
    value_code: string;
    abnormal_flag: string;
    result_comment: string;
    amendment_reason: string;
    [key: string]: string;
}

export interface VerifyLaboratoryResultFormData {
    verification_comment: string;
    [key: string]: string;
}

export interface ReturnForCorrectionFormData {
    return_reason: LaboratoryReturnReason | '';
    return_notes: string;
    [key: string]: string;
}

export interface ReleaseLaboratoryResultFormData {
    release_comment: string;
    [key: string]: string;
}

export interface LaboratoryResultFilters {
    search: string;
    status: string;
    priority: string;
    specimen_type: string;
    abnormal: string;
    date_from: string;
    date_to: string;
}

export interface LaboratoryReportResultValueEntry {
    parameter_name: string | null;
    parameter_code: string | null;
    value_numeric: string | null;
    value_text: string | null;
    value_code: string | null;
    unit: string | null;
    reference_low: string | null;
    reference_high: string | null;
    reference_critical_low: string | null;
    reference_critical_high: string | null;
    abnormal_flag: LaboratoryAbnormalFlag | null;
    result_comment: string | null;
}

export interface LaboratoryReportResultEntry {
    laboratory_result_id: number;
    sample_number: string | null;
    specimen_type: LaboratorySpecimenType | null;
    test_code: string | null;
    test_name: string | null;
    result_type: LaboratoryResultType | null;
    value_numeric: string | null;
    value_text: string | null;
    value_code: string | null;
    unit: string | null;
    reference_range: string | null;
    abnormal_flag: LaboratoryAbnormalFlag | null;
    result_comment: string | null;
    result_values: LaboratoryReportResultValueEntry[];
    entered_by: string | null;
    verified_by: string | null;
    verified_at: string | null;
    authorized_by: string | null;
    authorized_at: string | null;
    released_by: string | null;
    released_at: string | null;
}

export interface LaboratoryReportSampleGroup {
    sample_number: string | null;
    specimen_type: LaboratorySpecimenType | null;
    results: LaboratoryReportResultEntry[];
}

export interface LaboratoryReportPatient {
    id: number;
    patient_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    date_of_birth: string | null;
    gender: string | null;
}

export interface LaboratoryReportHospital {
    name: string;
    phone: string | null;
    email: string | null;
    address: string | null;
}

export interface LaboratoryReport {
    id: number;
    report_number: string;
    version: number;
    status: 'active' | 'superseded';
    results: LaboratoryReportSampleGroup[];
    patient: LaboratoryReportPatient | null;
    hospital: LaboratoryReportHospital | null;
    department: { id: number; name: string } | null;
    laboratory_order: {
        id: number;
        order_number: string;
        priority: LaboratoryOrderPriority;
        ordered_at: string | null;
        ordered_by: string | null;
        clinical_indication: string | null;
    } | null;
    encounter: { id: number; encounter_number: string } | null;
    released_by: string | null;
    released_at: string | null;
    printed_by: string | null;
    printed_at: string | null;
    downloaded_by: string | null;
    downloaded_at: string | null;
    created_at: string;
}

export interface LaboratoryReportVersionSummary {
    id: number;
    version: number;
    status: 'active' | 'superseded';
    released_at: string | null;
}

// ── Batch A: Parameter engine ────────────────────────────────────────────────

export type LaboratoryParameterDataType = 'numeric' | 'text' | 'option';

export type LaboratoryReferenceSex = 'male' | 'female' | 'any';

export interface LaboratoryReferenceRange {
    id: number;
    laboratory_test_parameter_id: number;
    sex: LaboratoryReferenceSex;
    age_min_days: number | null;
    age_max_days: number | null;
    low: string | null;
    high: string | null;
    critical_low: string | null;
    critical_high: string | null;
    notes: string | null;
    created_at: string;
    updated_at: string;
}

export interface LaboratoryTestParameter {
    id: number;
    laboratory_test_id: number;
    code: string;
    name: string;
    unit: string | null;
    data_type: LaboratoryParameterDataType;
    decimal_places: number;
    options_json: string[] | null;
    abnormal_options: string[] | null;
    display_order: number;
    is_calculated: boolean;
    calculation_formula: string | null;
    analyser_code: string | null;
    loinc_code: string | null;
    active: boolean;
    reference_ranges: LaboratoryReferenceRange[];
    created_at: string;
    updated_at: string;
}

export interface StoreLaboratoryTestParameterForm {
    code: string;
    name: string;
    unit: string;
    data_type: LaboratoryParameterDataType | '';
    decimal_places: string;
    options_json: string;
    abnormal_options: string;
    display_order: string;
    is_calculated: boolean;
    calculation_formula: string;
    analyser_code: string;
    loinc_code: string;
    [key: string]: string | boolean;
}

export interface StoreLaboratoryReferenceRangeForm {
    sex: LaboratoryReferenceSex | '';
    age_min_days: string;
    age_max_days: string;
    low: string;
    high: string;
    critical_low: string;
    critical_high: string;
    notes: string;
    [key: string]: string;
}
