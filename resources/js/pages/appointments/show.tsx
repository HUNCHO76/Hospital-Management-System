import { CancelAppointmentDialog } from '@/components/appointments/cancel-appointment-dialog';
import { RescheduleAppointmentDialog } from '@/components/appointments/reschedule-appointment-dialog';
import { Can } from '@/components/authorization/can';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { allowedAppointmentActions, appointmentStatusLabel, appointmentStatusVariant } from '@/lib/appointment-status';
import { type BreadcrumbItem } from '@/types';
import { type Appointment } from '@/types/appointment';
import { Head, Link, router } from '@inertiajs/react';
import { CalendarClock, CheckCircle2, LogIn, Pencil, PlayCircle, UserX, XCircle } from 'lucide-react';

const APPOINTMENT_TYPE_LABELS: Record<string, string> = {
    initial_consultation: 'Initial Consultation',
    follow_up: 'Follow-up',
    review: 'Review',
    procedure: 'Procedure',
    specialist_consultation: 'Specialist Consultation',
    other: 'Other',
};

interface AppointmentShowProps {
    appointment: Appointment;
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

export default function AppointmentShow({ appointment }: AppointmentShowProps) {
    const { can } = usePermissions();
    const patientName = appointment.patient
        ? [appointment.patient.first_name, appointment.patient.middle_name, appointment.patient.last_name].filter(Boolean).join(' ')
        : '—';
    const practitionerName = appointment.practitioner?.staff
        ? `${appointment.practitioner.staff.first_name} ${appointment.practitioner.staff.last_name}`
        : (appointment.practitioner?.practitioner_number ?? '—');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Appointments', href: '/appointments' },
        { title: appointment.appointment_number, href: `/appointments/${appointment.id}` },
    ];

    const actions = allowedAppointmentActions(appointment.status);

    const runAction = (action: string) => {
        router.post(route(`appointments.${action}`, appointment.id), {}, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Appointment ${appointment.appointment_number}`} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{appointment.appointment_number}</h1>
                        <p className="text-muted-foreground text-sm">{patientName}</p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant={appointmentStatusVariant[appointment.status]}>{appointmentStatusLabel[appointment.status]}</Badge>

                        <Can permission="appointments.update">
                            <Button variant="outline" size="sm" asChild>
                                <Link href={route('appointments.edit', appointment.id)}>
                                    <Pencil className="h-4 w-4" />
                                    Edit
                                </Link>
                            </Button>
                        </Can>

                        {actions.includes('confirm') && can('appointments.confirm') && (
                            <Button size="sm" onClick={() => runAction('confirm')}>
                                <CheckCircle2 className="h-4 w-4" />
                                Confirm
                            </Button>
                        )}

                        {actions.includes('checkIn') && can('appointments.checkin') && (
                            <Button size="sm" onClick={() => runAction('check-in')}>
                                <LogIn className="h-4 w-4" />
                                Check In
                            </Button>
                        )}

                        {actions.includes('start') && can('appointments.complete') && (
                            <Button size="sm" onClick={() => runAction('start')}>
                                <PlayCircle className="h-4 w-4" />
                                Start
                            </Button>
                        )}

                        {actions.includes('complete') && can('appointments.complete') && (
                            <Button size="sm" onClick={() => runAction('complete')}>
                                <CheckCircle2 className="h-4 w-4" />
                                Complete
                            </Button>
                        )}

                        {actions.includes('cancel') && can('appointments.cancel') && (
                            <CancelAppointmentDialog
                                appointmentId={appointment.id}
                                trigger={
                                    <Button size="sm" variant="destructive">
                                        <XCircle className="h-4 w-4" />
                                        Cancel
                                    </Button>
                                }
                            />
                        )}

                        {actions.includes('noShow') && can('appointments.no_show') && (
                            <Button size="sm" variant="secondary" onClick={() => runAction('no-show')}>
                                <UserX className="h-4 w-4" />
                                No Show
                            </Button>
                        )}

                        <Can permission="appointments.reschedule">
                            {(appointment.status === 'scheduled' || appointment.status === 'confirmed') && (
                                <RescheduleAppointmentDialog
                                    appointmentId={appointment.id}
                                    currentStart={appointment.scheduled_start}
                                    currentEnd={appointment.scheduled_end}
                                    trigger={
                                        <Button size="sm" variant="outline">
                                            <CalendarClock className="h-4 w-4" />
                                            Reschedule
                                        </Button>
                                    }
                                />
                            )}
                        </Can>
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Appointment Details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Type</dt>
                                    <dd>{APPOINTMENT_TYPE_LABELS[appointment.appointment_type] ?? appointment.appointment_type}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Department</dt>
                                    <dd>{appointment.department?.name ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Practitioner</dt>
                                    <dd>{practitionerName}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Start</dt>
                                    <dd>{formatDateTime(appointment.scheduled_start)}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">End</dt>
                                    <dd>{formatDateTime(appointment.scheduled_end)}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Reason</dt>
                                    <dd>{appointment.reason ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Notes</dt>
                                    <dd>{appointment.notes ?? '—'}</dd>
                                </div>
                                {appointment.status === 'cancelled' && (
                                    <>
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-muted-foreground">Cancelled At</dt>
                                            <dd>{appointment.cancelled_at ? formatDateTime(appointment.cancelled_at) : '—'}</dd>
                                        </div>
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-muted-foreground">Cancellation Reason</dt>
                                            <dd>{appointment.cancellation_reason ?? '—'}</dd>
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
                            {appointment.patient ? (
                                <dl className="space-y-2 text-sm">
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Name</dt>
                                        <dd>
                                            <Link href={route('patients.show', appointment.patient.id)} className="font-medium hover:underline">
                                                {patientName}
                                            </Link>
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Patient Number</dt>
                                        <dd>{appointment.patient.patient_number}</dd>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Phone</dt>
                                        <dd>{appointment.patient.phone ?? '—'}</dd>
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
