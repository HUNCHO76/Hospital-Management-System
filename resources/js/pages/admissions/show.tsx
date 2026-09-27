import { ApproveAdmissionDialog } from '@/components/admissions/approve-admission-dialog';
import { CancelAdmissionDialog } from '@/components/admissions/cancel-admission-dialog';
import { DischargeAdmissionDialog } from '@/components/admissions/discharge-admission-dialog';
import { RejectAdmissionDialog } from '@/components/admissions/reject-admission-dialog';
import { ReturnAdmissionDialog } from '@/components/admissions/return-admission-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { admissionStatusLabel, admissionStatusVariant, admissionTypeLabel, allowedAdmissionActions, dischargeTypeLabel } from '@/lib/admission-status';
import { type BreadcrumbItem } from '@/types';
import { type Admission, type DischargeType } from '@/types/admission';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, ClipboardCheck, LogOut, RotateCcw, Send, ShieldCheck, ThumbsDown, XCircle } from 'lucide-react';

interface AdmissionShowProps {
    admission: Admission;
    canReview: boolean;
    canApprove: boolean;
    canReject: boolean;
    canReturnForCorrection: boolean;
    canConfirm: boolean;
    canDischarge: boolean;
    canCancel: boolean;
    canSubmitNhif: boolean;
    dischargeTypes: DischargeType[];
}

function formatDateTime(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

export default function AdmissionShow({
    admission,
    canReview,
    canApprove,
    canReject,
    canReturnForCorrection,
    canConfirm,
    canDischarge,
    canCancel,
    canSubmitNhif,
    dischargeTypes,
}: AdmissionShowProps) {
    const patientName = admission.patient
        ? [admission.patient.first_name, admission.patient.middle_name, admission.patient.last_name].filter(Boolean).join(' ')
        : '—';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Admissions', href: '/admissions' },
        { title: admission.admission_number, href: `/admissions/${admission.id}` },
    ];

    const actions = allowedAdmissionActions(admission.status);

    const startReview = () => {
        router.post(route('admissions.review', admission.id), {}, { preserveScroll: true });
    };

    const confirmAdmission = () => {
        router.post(route('admissions.confirm', admission.id), {}, { preserveScroll: true });
    };

    const submitNhif = () => {
        router.post(route('admissions.nhif-submit', admission.id), {}, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Admission ${admission.admission_number}`} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{admission.admission_number}</h1>
                        <p className="text-muted-foreground text-sm">{patientName}</p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant={admissionStatusVariant[admission.status]}>{admissionStatusLabel[admission.status]}</Badge>

                        {actions.includes('review') && canReview && (
                            <Button size="sm" variant="outline" onClick={startReview}>
                                <ClipboardCheck className="h-4 w-4" />
                                Start Review
                            </Button>
                        )}

                        {actions.includes('approve') && canApprove && (
                            <ApproveAdmissionDialog
                                admissionId={admission.id}
                                trigger={
                                    <Button size="sm">
                                        <CheckCircle2 className="h-4 w-4" />
                                        Approve
                                    </Button>
                                }
                            />
                        )}

                        {actions.includes('reject') && canReject && (
                            <RejectAdmissionDialog
                                admissionId={admission.id}
                                trigger={
                                    <Button size="sm" variant="destructive">
                                        <ThumbsDown className="h-4 w-4" />
                                        Reject
                                    </Button>
                                }
                            />
                        )}

                        {actions.includes('return') && canReturnForCorrection && (
                            <ReturnAdmissionDialog
                                admissionId={admission.id}
                                trigger={
                                    <Button size="sm" variant="outline">
                                        <RotateCcw className="h-4 w-4" />
                                        Return for Correction
                                    </Button>
                                }
                            />
                        )}

                        {actions.includes('confirm') && canConfirm && (
                            <Button size="sm" onClick={confirmAdmission}>
                                <ShieldCheck className="h-4 w-4" />
                                Admit Patient
                            </Button>
                        )}

                        {actions.includes('discharge') && canDischarge && (
                            <DischargeAdmissionDialog
                                admissionId={admission.id}
                                dischargeTypes={dischargeTypes}
                                trigger={
                                    <Button size="sm" variant="outline">
                                        <LogOut className="h-4 w-4" />
                                        Discharge
                                    </Button>
                                }
                            />
                        )}

                        {actions.includes('cancel') && canCancel && (
                            <CancelAdmissionDialog
                                admissionId={admission.id}
                                trigger={
                                    <Button size="sm" variant="destructive">
                                        <XCircle className="h-4 w-4" />
                                        Cancel
                                    </Button>
                                }
                            />
                        )}

                        {canSubmitNhif && admission.patient_id && (
                            <Button size="sm" variant="outline" onClick={submitNhif}>
                                <Send className="h-4 w-4" />
                                Submit to NHIF
                            </Button>
                        )}
                    </div>
                </div>

                {admission.status === 'pending' && (
                    <Alert variant="default" className="border-amber-500/50 bg-amber-500/10 text-amber-800 dark:text-amber-300">
                        <AlertTriangle className="h-5 w-5 text-amber-600 dark:text-amber-400" />
                        <AlertDescription>
                            <p className="font-semibold">Awaiting review</p>
                            <p className="text-xs">A reviewer must place this request under review and approve it before the patient can be admitted.</p>
                        </AlertDescription>
                    </Alert>
                )}

                {admission.status === 'under_review' && (
                    <Alert variant="default" className="border-blue-500/50 bg-blue-500/10 text-blue-800 dark:text-blue-300">
                        <ClipboardCheck className="h-5 w-5 text-blue-600 dark:text-blue-400" />
                        <AlertDescription>
                            <p className="font-semibold">Under review</p>
                            <p className="text-xs">
                                Reviewer: {admission.reviewedBy?.UserName ?? '—'} · {formatDateTime(admission.reviewed_at)}
                            </p>
                        </AlertDescription>
                    </Alert>
                )}

                {admission.status === 'approved' && (
                    <Alert variant="default" className="border-green-500/50 bg-green-500/10 text-green-800 dark:text-green-300">
                        <CheckCircle2 className="h-5 w-5 text-green-600 dark:text-green-400" />
                        <AlertDescription>
                            <p className="font-semibold">Approved — awaiting admission confirmation</p>
                            <p className="text-xs">
                                Approved by {admission.approvedBy?.UserName ?? '—'} at {formatDateTime(admission.approved_at)}. Nursing staff
                                must confirm billing/NHIF clearance and admit the patient.
                            </p>
                        </AlertDescription>
                    </Alert>
                )}

                {admission.status === 'rejected' && (
                    <Alert variant="destructive">
                        <XCircle className="h-5 w-5" />
                        <AlertDescription>
                            <p className="font-semibold">Request rejected</p>
                            <p className="text-xs">
                                Rejected by {admission.rejectedBy?.UserName ?? '—'} at {formatDateTime(admission.rejected_at)}.
                                {admission.rejection_reason && ` Reason: ${admission.rejection_reason}`}
                            </p>
                        </AlertDescription>
                    </Alert>
                )}

                {admission.status === 'active' && (
                    <Alert variant="default" className="border-green-500 bg-green-500/10 text-green-800 dark:text-green-300">
                        <ShieldCheck className="h-5 w-5 text-green-600 dark:text-green-400" />
                        <AlertDescription>
                            <span className="font-semibold">Patient admitted</span> — cleared and admitted at {formatDateTime(admission.admitted_at)}.
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Admission Details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Hospital</dt>
                                    <dd>{admission.hospital?.name ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Department / Ward</dt>
                                    <dd>{admission.department?.name ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Admission Type</dt>
                                    <dd>{admission.admission_type ? (admissionTypeLabel[admission.admission_type] ?? admission.admission_type) : '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Ward</dt>
                                    <dd>{admission.ward_type ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Room</dt>
                                    <dd>{admission.room_type ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Billing Tier</dt>
                                    <dd>{admission.service ? `${admission.service.code} — ${admission.service.name}` : '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Daily Rate</dt>
                                    <dd>
                                        {admission.charges_per_day
                                            ? Number(admission.charges_per_day).toLocaleString(undefined, { minimumFractionDigits: 2 })
                                            : '—'}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Admitting Practitioner</dt>
                                    <dd>
                                        {admission.practitioner?.staff
                                            ? `${admission.practitioner.staff.first_name} ${admission.practitioner.staff.last_name}`
                                            : (admission.practitioner?.practitioner_number ?? '—')}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Diagnosis / Reason</dt>
                                    <dd>{admission.diagnosis_at_admission ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Notes</dt>
                                    <dd>{admission.notes ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Requested By</dt>
                                    <dd>{admission.createdBy?.UserName ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Requested At</dt>
                                    <dd>{formatDateTime(admission.created_at)}</dd>
                                </div>

                                {(admission.status === 'under_review' || admission.reviewedBy) && (
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Reviewed By</dt>
                                        <dd>
                                            {admission.reviewedBy?.UserName ?? '—'} · {formatDateTime(admission.reviewed_at)}
                                        </dd>
                                    </div>
                                )}

                                {(admission.status === 'approved' || admission.approvedBy) && (
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Approved By</dt>
                                        <dd>
                                            {admission.approvedBy?.UserName ?? '—'} · {formatDateTime(admission.approved_at)}
                                        </dd>
                                    </div>
                                )}

                                {admission.status === 'rejected' && (
                                    <>
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-muted-foreground">Rejected By</dt>
                                            <dd>
                                                {admission.rejectedBy?.UserName ?? '—'} · {formatDateTime(admission.rejected_at)}
                                            </dd>
                                        </div>
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-muted-foreground">Rejection Reason</dt>
                                            <dd>{admission.rejection_reason ?? '—'}</dd>
                                        </div>
                                    </>
                                )}

                                {admission.status === 'active' && (
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Admitted By</dt>
                                        <dd>{admission.admittedBy?.UserName ?? '—'}</dd>
                                    </div>
                                )}

                                {admission.status === 'discharged' && (
                                    <>
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-muted-foreground">Discharged At</dt>
                                            <dd>{formatDateTime(admission.discharged_at)}</dd>
                                        </div>
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-muted-foreground">Discharged By</dt>
                                            <dd>{admission.dischargedBy?.UserName ?? '—'}</dd>
                                        </div>
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-muted-foreground">Discharge Type</dt>
                                            <dd>{admission.discharge_type ? (dischargeTypeLabel[admission.discharge_type] ?? admission.discharge_type) : '—'}</dd>
                                        </div>
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-muted-foreground">Discharge Reason</dt>
                                            <dd>{admission.discharge_reason ?? '—'}</dd>
                                        </div>
                                    </>
                                )}

                                {admission.status === 'cancelled' && (
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Cancellation Reason</dt>
                                        <dd>{admission.cancellation_reason ?? '—'}</dd>
                                    </div>
                                )}

                                {admission.nhifAdmission && (
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">NHIF Status</dt>
                                        <dd className="capitalize">{admission.nhifAdmission.nhif_status}</dd>
                                    </div>
                                )}
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Patient</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {admission.patient ? (
                                <dl className="space-y-2 text-sm">
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Name</dt>
                                        <dd>
                                            <Link href={route('patients.show', admission.patient.id)} className="font-medium hover:underline">
                                                {patientName}
                                            </Link>
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Patient Number</dt>
                                        <dd>{admission.patient.patient_number}</dd>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Phone</dt>
                                        <dd>{admission.patient.phone ?? '—'}</dd>
                                    </div>
                                    <div className="pt-2">
                                        <Link
                                            href={route('admissions.index', { patient_id: admission.patient.id })}
                                            className="text-primary text-xs underline-offset-4 hover:underline"
                                        >
                                            View this patient's admission history →
                                        </Link>
                                    </div>
                                </dl>
                            ) : (
                                <p className="text-muted-foreground text-sm">Patient information unavailable.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
