import { CancelVisitDialog } from '@/components/reception/cancel-visit-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { allowedReceptionActions, receptionStatusLabel, receptionStatusVariant } from '@/lib/reception-status';
import { type BreadcrumbItem } from '@/types';
import { type ReceptionVisit } from '@/types/reception';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, ClipboardPlus, PlayCircle, Send, XCircle } from 'lucide-react';

interface VisitShowProps {
    visit: ReceptionVisit;
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

export default function VisitShow({ visit }: VisitShowProps) {
    const { can } = usePermissions();
    const patientName = visit.patient
        ? [visit.patient.first_name, visit.patient.middle_name, visit.patient.last_name].filter(Boolean).join(' ')
        : '—';

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
                            <Button size="sm" onClick={() => runAction('send-to-service')}>
                                <Send className="h-4 w-4" />
                                Send to Service
                            </Button>
                        )}

                        {(visit.status === 'ready_for_service' || visit.status === 'sent_to_service') && can('clinical-encounters.create') && (
                            <Button size="sm" variant="outline" asChild>
                                <Link href={route('clinical-encounters.create', { patient_id: visit.patient_id, reception_visit_id: visit.id })}>
                                    <ClipboardPlus className="h-4 w-4" />
                                    Open Clinical Encounter
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
