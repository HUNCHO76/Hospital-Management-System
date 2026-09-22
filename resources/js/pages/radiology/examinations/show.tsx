import { RadiologyExaminationAbortDialog } from '@/components/radiology/radiology-examination-abort-dialog';
import { RadiologyExaminationCompleteDialog } from '@/components/radiology/radiology-examination-complete-dialog';
import { RadiologyExaminationStartDialog } from '@/components/radiology/radiology-examination-start-dialog';
import { RadiologyExaminationTechnicalForm } from '@/components/radiology/radiology-examination-technical-form';
import { RadiologyImageGallery } from '@/components/radiology/radiology-image-gallery';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import {
    radiologyAbortReasonLabel,
    radiologyAppointmentStatusLabel,
    radiologyAppointmentStatusVariant,
    radiologyModalityLabel,
    radiologyOrderPriorityLabel,
} from '@/lib/radiology-status';
import { NhifUtilizationStatus } from '@/components/nhif/nhif-utilization-status';
import { type BreadcrumbItem } from '@/types';
import { type NhifUtilization } from '@/types/nhif';
import { type RadiologyAppointment, type RadiologyTechnicalFieldsByModality } from '@/types/radiology';
import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, CircleDot, XCircle } from 'lucide-react';

interface RadiologyExaminationShowProps {
    appointment: RadiologyAppointment;
    abortReasons: string[];
    technicalFields: RadiologyTechnicalFieldsByModality;
    canManageImages: boolean;
    nhifUtilizations?: NhifUtilization[];
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

/**
 * Radiology examination detail (Phase 5, Step 41, Part 24) — patient
 * identity, encounter/order/procedure context, scheduling/preparation
 * recap, and the examination itself (status, performer, timestamps,
 * technical information). Every mutating action shown here still has its
 * ability independently re-checked server-side (Part 21) — hiding a button
 * is a convenience, never the actual guard.
 */
export default function RadiologyExaminationShow({ appointment, technicalFields, canManageImages, nhifUtilizations = [] }: RadiologyExaminationShowProps) {
    const { can } = usePermissions();

    const patientName = appointment.patient
        ? [appointment.patient.first_name, appointment.patient.middle_name, appointment.patient.last_name].filter(Boolean).join(' ')
        : '—';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Radiology', href: '/radiology' },
        { title: 'Examinations', href: '/radiology/examinations' },
        { title: appointment.order?.order_number ?? `#${appointment.id}`, href: `/radiology/examinations/${appointment.id}` },
    ];

    const canStart = can('radiology-examination.start') && appointment.status === 'ready';
    const canUpdate = can('radiology-examination.update') && appointment.status === 'in_progress';
    const canComplete = can('radiology-examination.complete') && appointment.status === 'in_progress';
    const canAbort = can('radiology-examination.abort') && appointment.status === 'in_progress';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Examination — ${patientName}`} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{patientName}</h1>
                        <p className="text-muted-foreground text-sm">
                            {appointment.patient?.patient_number} — {appointment.item?.procedure_name}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Badge variant={radiologyAppointmentStatusVariant[appointment.status]}>
                            {radiologyAppointmentStatusLabel[appointment.status]}
                        </Badge>
                        {canStart && <RadiologyExaminationStartDialog appointment={appointment} trigger={<Button>Start Examination</Button>} />}
                        {canComplete && (
                            <RadiologyExaminationCompleteDialog appointmentId={appointment.id} trigger={<Button>Complete Examination</Button>} />
                        )}
                        {canAbort && <RadiologyExaminationAbortDialog appointmentId={appointment.id} />}
                    </div>
                </div>

                {nhifUtilizations.length > 0 && (
                    <div className="flex flex-col gap-2 rounded-md border p-3">
                        {nhifUtilizations.map((u) => (
                            <NhifUtilizationStatus key={u.id} utilization={u} linkable />
                        ))}
                    </div>
                )}

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Patient &amp; Encounter</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Patient</dt>
                                    <dd>
                                        {appointment.patient ? (
                                            <Link href={route('patients.show', appointment.patient.id)} className="font-medium hover:underline">
                                                {patientName}
                                            </Link>
                                        ) : (
                                            '—'
                                        )}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Date of Birth</dt>
                                    <dd>{appointment.patient?.date_of_birth ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Sex</dt>
                                    <dd>{appointment.patient?.gender ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Encounter</dt>
                                    <dd>
                                        {appointment.encounter ? (
                                            <Button asChild variant="link" size="sm" className="h-auto p-0">
                                                <Link href={route('clinical-encounters.show', appointment.encounter.id)}>
                                                    {appointment.encounter.encounter_number}
                                                </Link>
                                            </Button>
                                        ) : (
                                            '—'
                                        )}
                                    </dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Order &amp; Procedure</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Order Number</dt>
                                    <dd>{appointment.order?.order_number ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Priority</dt>
                                    <dd>{appointment.order ? radiologyOrderPriorityLabel[appointment.order.priority] : '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Clinical Indication</dt>
                                    <dd className="text-right">{appointment.order?.clinical_indication ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Procedure</dt>
                                    <dd className="text-right">{appointment.item?.procedure_name ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Modality</dt>
                                    <dd>{appointment.modality ? radiologyModalityLabel[appointment.modality] : '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Resource / Room</dt>
                                    <dd>{appointment.resource_name}</dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Preparation &amp; Readiness</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                            <dt className="text-muted-foreground">Verified</dt>
                            <dd>{formatDateTime(appointment.verified_at)}</dd>
                            <dt className="text-muted-foreground">Preparation Completed</dt>
                            <dd>{formatDateTime(appointment.preparation_completed_at)}</dd>
                            <dt className="text-muted-foreground">Consent</dt>
                            <dd>{appointment.consent_status}</dd>
                            <dt className="text-muted-foreground">Marked Ready</dt>
                            <dd>{formatDateTime(appointment.ready_at)}</dd>
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Examination</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                            <dt className="text-muted-foreground">Performer</dt>
                            <dd>{appointment.performed_by?.name ?? '—'}</dd>
                            <dt className="text-muted-foreground">Started</dt>
                            <dd>{formatDateTime(appointment.started_at)}</dd>
                            <dt className="text-muted-foreground">Completed</dt>
                            <dd>{formatDateTime(appointment.completed_at)}</dd>
                            <dt className="text-muted-foreground">Completed By</dt>
                            <dd>{appointment.completed_by?.name ?? '—'}</dd>
                        </dl>

                        {appointment.status === 'aborted' && (
                            <div className="bg-muted mt-4 rounded-md p-3 text-sm">
                                <p className="font-medium">Aborted</p>
                                <p>
                                    {appointment.abort_reason ? radiologyAbortReasonLabel[appointment.abort_reason] : '—'} — by{' '}
                                    {appointment.aborted_by?.name ?? '—'} at {formatDateTime(appointment.aborted_at)}
                                </p>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {(appointment.status === 'in_progress' || appointment.status === 'completed') && (
                    <RadiologyExaminationTechnicalForm appointment={appointment} technicalFields={technicalFields} canEdit={canUpdate} />
                )}

                {(appointment.status === 'in_progress' || appointment.status === 'completed') && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Imaging / X-Ray Images</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <RadiologyImageGallery
                                appointmentId={appointment.id}
                                images={appointment.images ?? []}
                                canManage={canManageImages && appointment.status === 'in_progress'}
                                caption={appointment.item?.procedure_name}
                            />
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Timeline</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ol className="space-y-3 text-sm">
                            <li className="flex items-center gap-2">
                                <CircleDot className="text-muted-foreground h-4 w-4" />
                                <span>Scheduled — {formatDateTime(appointment.scheduled_start)}</span>
                            </li>
                            {appointment.ready_at && (
                                <li className="flex items-center gap-2">
                                    <CheckCircle2 className="h-4 w-4 text-green-600" />
                                    <span>Ready — {formatDateTime(appointment.ready_at)}</span>
                                </li>
                            )}
                            {appointment.started_at && (
                                <li className="flex items-center gap-2">
                                    <CheckCircle2 className="h-4 w-4 text-green-600" />
                                    <span>Examination Started — {formatDateTime(appointment.started_at)}</span>
                                </li>
                            )}
                            {appointment.completed_at && (
                                <li className="flex items-center gap-2">
                                    <CheckCircle2 className="h-4 w-4 text-green-600" />
                                    <span>Examination Completed — {formatDateTime(appointment.completed_at)}</span>
                                </li>
                            )}
                            {appointment.aborted_at && (
                                <li className="flex items-center gap-2">
                                    <XCircle className="text-destructive h-4 w-4" />
                                    <span>Examination Aborted — {formatDateTime(appointment.aborted_at)}</span>
                                </li>
                            )}
                        </ol>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
