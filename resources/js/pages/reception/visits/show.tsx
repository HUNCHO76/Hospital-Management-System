import { CancelVisitDialog } from '@/components/reception/cancel-visit-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { allowedReceptionActions, receptionStatusLabel, receptionStatusVariant } from '@/lib/reception-status';
import { type BreadcrumbItem } from '@/types';
import { type ConsultationCharge, type ConsultationFinancialStatus, type NhifPreApprovalItem, type ReceptionVisit } from '@/types/reception';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Baby, Bed, CheckCircle2, ClipboardPlus, Clock, PlayCircle, Send, ShieldCheck, ShieldX, XCircle } from 'lucide-react';

interface VisitShowProps {
    visit: ReceptionVisit;
    consultationFinancialStatus: ConsultationFinancialStatus;
    consultationCharge: ConsultationCharge | null;
    nhifPreApprovalItem: NhifPreApprovalItem | null;
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

const VISIT_TYPE_LABELS: Record<string, string> = {
    appointment: 'Appointment',
    walk_in: 'Walk-in',
    follow_up: 'Follow-up',
    other: 'Other',
};

export default function VisitShow({ visit, consultationFinancialStatus, consultationCharge, nhifPreApprovalItem }: VisitShowProps) {
    const { can } = usePermissions();
    const patientName = visit.patient
        ? [visit.patient.first_name, visit.patient.middle_name, visit.patient.last_name].filter(Boolean).join(' ')
        : '—';
    const serviceBlocked = consultationFinancialStatus !== 'authorized';
    const paymentRequired = consultationFinancialStatus === 'payment_required';
    const nhifPending = consultationFinancialStatus === 'nhif_pending';
    const nhifRejected = consultationFinancialStatus === 'nhif_rejected';
    const nhifStatus = nhifPreApprovalItem?.approval_status ?? null;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Reception', href: '/reception' },
        { title: 'Visits', href: '/reception/visits' },
        { title: visit.visit_number, href: `/reception/visits/${visit.id}` },
    ];

    const actions = allowedReceptionActions(visit.status);

    const runAction = (action: string) => {
        router.post(route(`reception.visits.${action}`, visit.id), {}, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Visit ${visit.visit_number}`} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{visit.visit_number}</h1>
                        <p className="text-muted-foreground text-sm">{patientName}</p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant={receptionStatusVariant[visit.status]}>{receptionStatusLabel[visit.status]}</Badge>

                        {actions.includes('waiting') && can('reception.update') && (
                            <Button size="sm" onClick={() => runAction('waiting')}>
                                Mark Waiting
                            </Button>
                        )}

                        {actions.includes('ready') && can('reception.update') && (
                            <Button size="sm" onClick={() => runAction('ready')}>
                                <CheckCircle2 className="h-4 w-4" />
                                Mark Ready
                            </Button>
                        )}

                        {actions.includes('sendToService') && can('reception.update') && (
                            <Button
                                size="sm"
                                onClick={() => runAction('send-to-service')}
                                disabled={serviceBlocked}
                                title={
                                    nhifPending ? 'NHIF authorization is pending — cannot send to service'
                                        : nhifRejected ? 'NHIF authorization was rejected — convert to cash or resubmit'
                                            : paymentRequired ? 'Payment must be cleared before sending to service'
                                                : undefined
                                }
                            >
                                <Send className="h-4 w-4" />
                                Send to Service
                            </Button>
                        )}

                        {(visit.status === 'ready_for_service' || visit.status === 'sent_to_service') && can('clinical-encounters.create') && !serviceBlocked && (
                            <Button size="sm" variant="outline" asChild>
                                <Link href={route('clinical-encounters.create', { patient_id: visit.patient_id, reception_visit_id: visit.id })}>
                                    <ClipboardPlus className="h-4 w-4" />
                                    Open Clinical Encounter
                                </Link>
                            </Button>
                        )}

                        {visit.status !== 'cancelled' && can('admissions.create') && (
                            <Button
                                size="sm"
                                variant={visit.department_id === 7 || visit.department?.name?.toLowerCase().includes('maternity') ? 'default' : 'outline'}
                                className={visit.department_id === 7 || visit.department?.name?.toLowerCase().includes('maternity') ? 'bg-pink-600 hover:bg-pink-700 text-white' : ''}
                                asChild
                            >
                                <Link
                                    href={route('admissions.create', {
                                        patient_id: visit.patient_id,
                                        department_id: visit.department_id,
                                        reception_visit_id: visit.id,
                                        practitioner_id: visit.practitioner_id || undefined,
                                        preset: (visit.department_id === 7 || visit.department?.name?.toLowerCase().includes('maternity')) ? 'maternity' : undefined,
                                    })}
                                >
                                    {(visit.department_id === 7 || visit.department?.name?.toLowerCase().includes('maternity')) ? (
                                        <Baby className="h-4 w-4" />
                                    ) : (
                                        <Bed className="h-4 w-4" />
                                    )}
                                    {(visit.department_id === 7 || visit.department?.name?.toLowerCase().includes('maternity')) ? 'Admit to Maternity Ward' : 'Admit Patient'}
                                </Link>
                            </Button>
                        )}

                        {actions.includes('complete') && can('reception.complete') && (
                            <Button size="sm" onClick={() => runAction('complete')}>
                                <PlayCircle className="h-4 w-4" />
                                Complete
                            </Button>
                        )}

                        {actions.includes('cancel') && can('reception.cancel') && (
                            <CancelVisitDialog
                                visitId={visit.id}
                                trigger={
                                    <Button size="sm" variant="destructive">
                                        <XCircle className="h-4 w-4" />
                                        Cancel
                                    </Button>
                                }
                            />
                        )}
                    </div>
                </div>

                {consultationCharge && (
                    <div className="flex flex-col gap-3">
                        {/* NHIF: accepted — fully or partially covered */}
                        {(nhifStatus === 'accepted' || nhifStatus === 'approved') && (
                            <Alert className="border-green-500 bg-green-500/10 text-green-800 dark:text-green-300">
                                <ShieldCheck className="h-5 w-5 text-green-600 dark:text-green-400" />
                                <AlertDescription className="space-y-1">
                                    <div className="flex items-center gap-2 font-semibold">
                                        <span>NHIF SERVICE AUTHORIZED</span>
                                        <Badge variant="default" className="bg-green-600 dark:bg-green-700 text-white">NHIF APPROVED</Badge>
                                    </div>
                                    <p className="text-xs">
                                        Consultation charge {consultationCharge.charge_number} is covered by NHIF.
                                        {nhifPreApprovalItem?.nhif_request_no && ` NHIF Request No: ${nhifPreApprovalItem.nhif_request_no}.`}
                                        {nhifPreApprovalItem?.nhif_percent_covered !== null && nhifPreApprovalItem?.nhif_percent_covered !== undefined && nhifPreApprovalItem.nhif_percent_covered < 100
                                            ? ` NHIF covers ${nhifPreApprovalItem.nhif_percent_covered}% — patient may owe the remainder.`
                                            : ' Patient may proceed to the clinical queue.'}
                                    </p>
                                </AlertDescription>
                            </Alert>
                        )}

                        {/* NHIF: pending approval */}
                        {nhifStatus === 'pending_approval' && (
                            <Alert className="border-blue-500/50 bg-blue-500/10 text-blue-800 dark:text-blue-300">
                                <Clock className="h-5 w-5 text-blue-600 dark:text-blue-400" />
                                <AlertDescription className="space-y-1">
                                    <div className="flex items-center gap-2 font-semibold">
                                        <span>NHIF AUTHORIZATION PENDING</span>
                                        <Badge className="bg-blue-600 dark:bg-blue-700 text-white">PENDING APPROVAL</Badge>
                                    </div>
                                    <p className="text-xs">
                                        Consultation is awaiting NHIF approval.
                                        {nhifPreApprovalItem?.nhif_request_no && ` NHIF Request No: ${nhifPreApprovalItem.nhif_request_no}.`}
                                        {' '}Patient cannot enter the clinical queue until NHIF approves the service.
                                    </p>
                                </AlertDescription>
                            </Alert>
                        )}

                        {/* NHIF: rejected */}
                        {nhifStatus === 'rejected' && (
                            <Alert variant="destructive" className="border-red-500/50 bg-red-500/10 text-red-800 dark:text-red-300">
                                <ShieldX className="h-5 w-5 text-red-600 dark:text-red-400" />
                                <AlertDescription className="space-y-1">
                                    <div className="flex items-center gap-2 font-semibold">
                                        <span>NHIF SERVICE REJECTED</span>
                                        <Badge variant="destructive">REJECTED</Badge>
                                    </div>
                                    <p className="text-xs">
                                        Consultation was not authorized by NHIF.
                                        {nhifPreApprovalItem?.rejection_reason && ` Reason: ${nhifPreApprovalItem.rejection_reason}.`}
                                        {nhifPreApprovalItem?.nhif_request_no && ` NHIF Request No: ${nhifPreApprovalItem.nhif_request_no}.`}
                                    </p>
                                </AlertDescription>
                            </Alert>
                        )}

                        {/* NHIF partial coverage — patient co-payment informational */}
                        {(nhifStatus === 'accepted' || nhifStatus === 'approved') && nhifPreApprovalItem && nhifPreApprovalItem.nhif_percent_covered !== null && nhifPreApprovalItem.nhif_percent_covered < 100 && (
                            <Alert className="border-amber-500/50 bg-amber-500/10 text-amber-800 dark:text-amber-300">
                                <AlertTriangle className="h-5 w-5 text-amber-600 dark:text-amber-400" />
                                <AlertDescription className="space-y-1">
                                    <div className="flex items-center gap-2 font-semibold">
                                        <span>PATIENT PAYMENT REQUIRED</span>
                                        <Badge variant="destructive" className="bg-amber-600 dark:bg-amber-700 text-white">COPAYMENT DUE</Badge>
                                    </div>
                                    <p className="text-xs">
                                        NHIF has approved this service with patient co-payment. Please proceed to the cashier for the outstanding balance.
                                    </p>
                                </AlertDescription>
                            </Alert>
                        )}

                        {/* Cash / no NHIF item — standard payment status */}
                        {!nhifStatus && !nhifPending && !nhifRejected && (
                            <Alert
                                variant={paymentRequired ? 'destructive' : 'default'}
                                className={paymentRequired ? 'border-amber-500/50 bg-amber-500/10 text-amber-800 dark:text-amber-300' : 'border-green-500 bg-green-500/10 text-green-800 dark:text-green-300'}
                            >
                                {paymentRequired ? <AlertTriangle className="h-5 w-5 text-amber-600 dark:text-amber-400" /> : <CheckCircle2 className="h-5 w-5 text-green-600 dark:text-green-400" />}
                                <AlertDescription className="space-y-1">
                                    <div className="flex items-center gap-2 font-semibold">
                                        <span>Financial Status: {paymentRequired ? 'PAYMENT REQUIRED' : 'AUTHORIZED'}</span>
                                        {paymentRequired ? (
                                            <Badge variant="destructive" className="bg-amber-600 dark:bg-amber-700 text-white">PAYMENT REQUIRED</Badge>
                                        ) : (
                                            <Badge variant="default" className="bg-green-600 dark:bg-green-700 text-white">READY FOR SPECIALIST</Badge>
                                        )}
                                    </div>
                                    <p className="text-xs">
                                        {paymentRequired
                                            ? `Consultation charge ${consultationCharge.charge_number} (${consultationCharge.currency} ${Number(consultationCharge.net_amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}) must be paid at the cashier before patient can enter clinical queue.`
                                            : `Consultation charge ${consultationCharge.charge_number} is settled. Patient is authorized to see specialist.`}
                                    </p>
                                </AlertDescription>
                            </Alert>
                        )}
                    </div>
                )}

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Visit Details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Type</dt>
                                    <dd>{VISIT_TYPE_LABELS[visit.visit_type] ?? visit.visit_type}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Department</dt>
                                    <dd>{visit.department?.name ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Created</dt>
                                    <dd>{formatDateTime(visit.created_at)}</dd>
                                </div>
                                {visit.appointment && (
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Appointment</dt>
                                        <dd>{visit.appointment.appointment_number}</dd>
                                    </div>
                                )}
                                {visit.status === 'completed' && (
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Completed At</dt>
                                        <dd>{visit.completed_at ? formatDateTime(visit.completed_at) : '—'}</dd>
                                    </div>
                                )}
                                {visit.status === 'cancelled' && (
                                    <>
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-muted-foreground">Cancelled At</dt>
                                            <dd>{visit.cancelled_at ? formatDateTime(visit.cancelled_at) : '—'}</dd>
                                        </div>
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-muted-foreground">Cancellation Reason</dt>
                                            <dd>{visit.cancellation_reason ?? '—'}</dd>
                                        </div>
                                    </>
                                )}
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Patient</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {visit.patient ? (
                                <dl className="space-y-2 text-sm">
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Name</dt>
                                        <dd>
                                            <Link href={route('patients.show', visit.patient.id)} className="font-medium hover:underline">
                                                {patientName}
                                            </Link>
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Patient Number</dt>
                                        <dd>{visit.patient.patient_number}</dd>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Phone</dt>
                                        <dd>{visit.patient.phone ?? '—'}</dd>
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
