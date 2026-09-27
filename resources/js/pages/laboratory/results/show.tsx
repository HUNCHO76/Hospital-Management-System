import { AmendResultDialog } from '@/components/laboratory/amend-result-dialog';
import { LaboratoryResultDocumentSection } from '@/components/laboratory/laboratory-result-document-section';
import { AuthorizeResultDialog } from '@/components/laboratory/authorize-result-dialog';
import { LaboratoryParameterResultTable } from '@/components/laboratory/laboratory-parameter-result-table';
import { LaboratoryResultValueInput } from '@/components/laboratory/laboratory-result-value-input';
import { ReleaseResultDialog } from '@/components/laboratory/release-result-dialog';
import { ResultHistory } from '@/components/laboratory/result-history';
import { ReturnForCorrectionDialog } from '@/components/laboratory/return-for-correction-dialog';
import { VerifyResultDialog } from '@/components/laboratory/verify-result-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import {
    canAmendResult,
    canAmendVerifiedResult,
    canAuthorizeResult,
    canReleaseResult,
    canReturnForCorrection,
    canSaveResultDraft,
    canStartReview,
    canSubmitResult,
    canVerifyResult,
    laboratoryAbnormalFlagLabel,
    laboratoryOrderPriorityLabel,
    laboratoryOrderPriorityVariant,
    laboratoryResultStatusLabel,
    laboratoryResultStatusVariant,
    laboratoryReturnReasonLabel,
    laboratorySpecimenContainerLabel,
    laboratorySpecimenTypeLabel,
} from '@/lib/laboratory-status';
import { NhifUtilizationStatus } from '@/components/nhif/nhif-utilization-status';
import { type BreadcrumbItem } from '@/types';
import {
    type LaboratoryAbnormalFlag,
    type LaboratoryResult,
    type ParameterValueEntry,
    type SaveLaboratoryResultDraftFormData,
} from '@/types/laboratory';
import { type NhifUtilization } from '@/types/nhif';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { CheckCircle2, LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface LaboratoryResultShowProps {
    result: LaboratoryResult;
    nhifUtilizations?: NhifUtilization[];
}

const FLAGS = Object.keys(laboratoryAbnormalFlagLabel) as LaboratoryAbnormalFlag[];

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

function formatAge(dateOfBirth: string | null): string {
    if (!dateOfBirth) {
        return '—';
    }

    const years = Math.floor((Date.now() - new Date(dateOfBirth).getTime()) / (365.25 * 24 * 60 * 60 * 1000));

    return `${years}y`;
}

/**
 * The result entry/review page for a single test (Phase 4, Steps 33-34,
 * spec §11/§36) — patient/order/specimen/test context, the dynamic result
 * input while editable (RESULT_PENDING/DRAFT/RETURNED_FOR_CORRECTION), and
 * the full review/verify/return/authorize workflow once submitted. Locked
 * to read-only for ordinary editing from PENDING_VERIFICATION onward — the
 * backend enforces this independently of what buttons are shown here.
 */
export default function LaboratoryResultShow({ result, nhifUtilizations = [] }: LaboratoryResultShowProps) {
    const { can } = usePermissions();

    const patientName = result.patient
        ? [result.patient.first_name, result.patient.middle_name, result.patient.last_name].filter(Boolean).join(' ')
        : '—';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Laboratory', href: '/laboratory' },
        { title: 'Results', href: '/laboratory/results' },
        { title: result.item?.test_name ?? `Result #${result.id}`, href: `/laboratory/results/${result.id}` },
    ];

    const isParameterized = result.result_values && result.result_values.length > 0;

    const { data, setData, put, processing, errors } = useForm<SaveLaboratoryResultDraftFormData>({
        value_numeric: result.value_numeric ?? '',
        value_text: result.value_text ?? '',
        value_code: result.value_code ?? '',
        abnormal_flag: result.abnormal_flag ?? '',
        result_comment: result.result_comment ?? '',
        parameter_values: isParameterized
            ? result.result_values.map((rv) => ({
                  parameter_id: rv.laboratory_test_parameter_id,
                  value_numeric: rv.value_numeric ?? '',
                  value_text: rv.value_text ?? '',
                  value_code: rv.value_code ?? '',
                  result_comment: rv.result_comment ?? '',
              }))
            : [],
    });

    const handleParameterValueChange = (
        parameterId: number,
        field: keyof Omit<ParameterValueEntry, 'parameter_id'>,
        value: string,
    ) => {
        const updated = data.parameter_values.map((entry) =>
            entry.parameter_id === parameterId ? { ...entry, [field]: value } : entry,
        );
        setData('parameter_values', updated);
    };

    const editable = canSaveResultDraft(result.status);
    const canEdit = editable && can('laboratory-results.update');

    const saveDraft: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('laboratory.results.update', result.id), { preserveScroll: true });
    };

    const submitForVerification = () => {
        put(route('laboratory.results.update', result.id), {
            preserveScroll: true,
            onSuccess: () => router.post(route('laboratory.results.submit', result.id), {}, { preserveScroll: true }),
        });
    };

    const startReview = () => {
        router.post(route('laboratory.results.review', result.id), {}, { preserveScroll: true });
    };

    const canAmend =
        (canAmendResult(result.status) && can('laboratory-results.amend')) ||
        (canAmendVerifiedResult(result.status) && can('laboratory-results.amend_verified'));

    const canUploadDocument = can('laboratory-results.update');
    const canDeleteDocument = can('laboratory-results.update');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Result — ${result.item?.test_name ?? result.id}`} />

            <div className="flex flex-col gap-6 p-4">
                {nhifUtilizations.length > 0 && (
                    <div className="flex flex-col gap-2 rounded-md border p-3">
                        {nhifUtilizations.map((u) => (
                            <NhifUtilizationStatus key={u.id} utilization={u} linkable />
                        ))}
                    </div>
                )}

                {result.is_ready_for_release && (
                    <div className="border-primary bg-primary/5 flex items-center gap-2 rounded-md border p-3 text-sm">
                        <CheckCircle2 className="text-primary h-5 w-5 shrink-0" />
                        <span className="font-medium">Authorized — ready for release.</span>
                    </div>
                )}

                {result.is_released && (
                    <div className="border-primary bg-primary/5 flex flex-wrap items-center justify-between gap-2 rounded-md border p-3 text-sm">
                        <span className="flex items-center gap-2 font-medium">
                            <CheckCircle2 className="text-primary h-5 w-5 shrink-0" />
                            Released — an official laboratory report has been generated.
                        </span>
                        {result.active_report && (
                            <Button asChild size="sm" variant="outline">
                                <Link href={route('laboratory.reports.show', result.active_report.id)}>
                                    View Report ({result.active_report.report_number})
                                </Link>
                            </Button>
                        )}
                    </div>
                )}

                {result.status === 'returned_for_correction' && (
                    <div className="border-destructive bg-destructive/5 rounded-md border p-3 text-sm">
                        <p className="font-medium">Returned for correction</p>
                        <p className="text-muted-foreground">
                            {result.return_reason && laboratoryReturnReasonLabel[result.return_reason]}
                            {result.returned_by ? ` — ${result.returned_by.name}` : ''}
                        </p>
                        {result.return_notes && <p className="mt-1">{result.return_notes}</p>}
                    </div>
                )}

                <Card>
                    <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2 space-y-0">
                        <CardTitle className="text-base">
                            {result.item?.test_code} — {result.item?.test_name}
                        </CardTitle>
                        <Badge variant={laboratoryResultStatusVariant[result.status]}>{laboratoryResultStatusLabel[result.status]}</Badge>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <dt className="text-muted-foreground">Patient</dt>
                                <dd className="font-medium">
                                    {result.patient ? (
                                        <Link href={route('patients.show', result.patient.id)} className="hover:underline">
                                            {patientName}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Patient No. / DOB / Sex</dt>
                                <dd>
                                    {result.patient?.patient_number ?? '—'} · {formatAge(result.patient?.date_of_birth ?? null)} ·{' '}
                                    {result.patient?.gender ?? '—'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Order No.</dt>
                                <dd>{result.laboratory_order?.order_number ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Priority</dt>
                                <dd>
                                    {result.laboratory_order && (
                                        <Badge variant={laboratoryOrderPriorityVariant[result.laboratory_order.priority]}>
                                            {laboratoryOrderPriorityLabel[result.laboratory_order.priority]}
                                        </Badge>
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Sample No.</dt>
                                <dd>{result.sample?.sample_number ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Specimen / Container</dt>
                                <dd>
                                    {result.sample ? laboratorySpecimenTypeLabel[result.sample.specimen_type] : '—'}
                                    {result.sample?.container_type ? ` / ${laboratorySpecimenContainerLabel[result.sample.container_type]}` : ''}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Processing Completed</dt>
                                <dd>{result.sample?.received_at ? formatDateTime(result.sample.received_at) : '—'}</dd>
                            </div>
                            {result.reference_range && (
                                <div>
                                    <dt className="text-muted-foreground">Reference Range</dt>
                                    <dd>
                                        {result.reference_range}
                                        {result.unit ? ` ${result.unit}` : ''}
                                    </dd>
                                </div>
                            )}
                            <div>
                                <dt className="text-muted-foreground">Entered By</dt>
                                <dd>{result.entered_by?.name ?? '—'}</dd>
                            </div>
                            {result.reviewed_by && (
                                <div>
                                    <dt className="text-muted-foreground">Reviewed By</dt>
                                    <dd>{result.reviewed_by.name}</dd>
                                </div>
                            )}
                            {result.verified_by && (
                                <div>
                                    <dt className="text-muted-foreground">Verified By</dt>
                                    <dd>
                                        {result.verified_by.name}
                                        {result.verified_at ? ` — ${formatDateTime(result.verified_at)}` : ''}
                                    </dd>
                                </div>
                            )}
                            {result.authorized_by && (
                                <div>
                                    <dt className="text-muted-foreground">Authorized By</dt>
                                    <dd>
                                        {result.authorized_by.name}
                                        {result.authorized_at ? ` — ${formatDateTime(result.authorized_at)}` : ''}
                                    </dd>
                                </div>
                            )}
                            {result.released_by && (
                                <div>
                                    <dt className="text-muted-foreground">Released By</dt>
                                    <dd>
                                        {result.released_by.name}
                                        {result.released_at ? ` — ${formatDateTime(result.released_at)}` : ''}
                                    </dd>
                                </div>
                            )}
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Result</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {editable ? (
                            <form onSubmit={saveDraft} className="space-y-4">
                                {isParameterized ? (
                                    <LaboratoryParameterResultTable
                                        resultValues={result.result_values}
                                        parameterValues={data.parameter_values}
                                        onChangeParameterValue={handleParameterValueChange}
                                        disabled={!canEdit}
                                        errors={errors as Record<string, string>}
                                    />
                                ) : (
                                    <LaboratoryResultValueInput
                                        resultType={result.result_type}
                                        unit={result.unit}
                                        resultOptions={result.result_options}
                                        valueNumeric={data.value_numeric}
                                        valueText={data.value_text}
                                        valueCode={data.value_code}
                                        onChangeNumeric={(value) => setData('value_numeric', value)}
                                        onChangeText={(value) => setData('value_text', value)}
                                        onChangeCode={(value) => setData('value_code', value)}
                                        errors={errors}
                                        disabled={!canEdit}
                                    />
                                )}

                                {!isParameterized && (
                                    <div className="grid gap-2">
                                        <Label>Abnormal Flag</Label>
                                        <Select
                                            value={data.abnormal_flag || 'none'}
                                            onValueChange={(value) => setData('abnormal_flag', value === 'none' ? '' : value)}
                                            disabled={!canEdit}
                                        >
                                            <SelectTrigger className="max-w-xs">
                                                <SelectValue placeholder="Not flagged" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="none">Not flagged</SelectItem>
                                                {FLAGS.map((flag) => (
                                                    <SelectItem key={flag} value={flag}>
                                                        {laboratoryAbnormalFlagLabel[flag]}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                )}

                                <div className="grid gap-2">
                                    <Label htmlFor="result_comment">Comment</Label>
                                    <Textarea
                                        id="result_comment"
                                        rows={2}
                                        value={data.result_comment}
                                        onChange={(e) => setData('result_comment', e.target.value)}
                                        disabled={!canEdit}
                                    />
                                </div>

                                {canEdit && (
                                    <div className="flex gap-2">
                                        <Button type="submit" variant="outline" disabled={processing}>
                                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                            Save Draft
                                        </Button>
                                        {canSubmitResult(result.status) && can('laboratory-results.submit') && (
                                            <Button type="button" onClick={submitForVerification} disabled={processing}>
                                                Submit for Verification
                                            </Button>
                                        )}
                                    </div>
                                )}
                            </form>
                        ) : (
                            <div className="space-y-4">
                                {isParameterized ? (
                                    <LaboratoryParameterResultTable
                                        resultValues={result.result_values}
                                        parameterValues={[]}
                                        onChangeParameterValue={() => undefined}
                                        disabled={true}
                                    />
                                ) : (
                                    <dl className="grid gap-3 text-sm sm:grid-cols-2">
                                        <div>
                                            <dt className="text-muted-foreground">Value</dt>
                                            <dd className="font-medium">
                                                {result.value_numeric ?? result.value_text ?? result.value_code ?? '—'}
                                                {result.value_numeric && result.unit ? ` ${result.unit}` : ''}
                                            </dd>
                                        </div>
                                        {result.abnormal_flag && (
                                            <div>
                                                <dt className="text-muted-foreground">Flag</dt>
                                                <dd>
                                                    <Badge variant={result.abnormal_flag === 'critical' ? 'destructive' : 'default'}>
                                                        {laboratoryAbnormalFlagLabel[result.abnormal_flag]}
                                                    </Badge>
                                                </dd>
                                            </div>
                                        )}
                                    </dl>
                                )}
                                {result.result_comment && (
                                    <p className="text-muted-foreground text-sm">
                                        <span className="font-medium">Comment:</span> {result.result_comment}
                                    </p>
                                )}
                                {result.verification_comment && (
                                    <p className="text-muted-foreground text-sm">
                                        <span className="font-medium">Verification comment:</span> {result.verification_comment}
                                    </p>
                                )}

                                <div className="flex flex-wrap gap-2">
                                    {canStartReview(result.status) && can('laboratory-results.review') && (
                                        <Button onClick={startReview}>Start Review</Button>
                                    )}

                                    {canVerifyResult(result.status) && can('laboratory-results.verify') && (
                                        <VerifyResultDialog result={result} trigger={<Button>Verify Result</Button>} />
                                    )}

                                    {canReturnForCorrection(result.status) && can('laboratory-results.return') && (
                                        <ReturnForCorrectionDialog
                                            resultId={result.id}
                                            trigger={<Button variant="destructive">Return for Correction</Button>}
                                        />
                                    )}

                                    {canAuthorizeResult(result.status) && can('laboratory-results.authorize') && (
                                        <AuthorizeResultDialog result={result} trigger={<Button>Authorize Result</Button>} />
                                    )}

                                    {canReleaseResult(result.status) && can('laboratory-results.release') && (
                                        <ReleaseResultDialog result={result} trigger={<Button>Release Result</Button>} />
                                    )}

                                    {canAmend && <AmendResultDialog result={result} trigger={<Button variant="outline">Amend Result</Button>} />}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <LaboratoryResultDocumentSection
                    result={result}
                    canUpload={canUploadDocument}
                    canDelete={canDeleteDocument}
                />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">History</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ResultHistory history={result.history} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
