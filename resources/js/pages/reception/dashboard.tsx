import { CheckInAppointmentDialog } from '@/components/reception/check-in-appointment-dialog';
import { Can } from '@/components/authorization/can';
import { StatCard } from '@/components/dashboard/stat-card';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { insuranceIndicatorLabel, insuranceIndicatorVariant, receptionStatusLabel, receptionStatusVariant } from '@/lib/reception-status';
import { type BreadcrumbItem } from '@/types';
import { type ReceptionDashboardSummary, type ReceptionVisit, type TodaysAppointmentRow } from '@/types/reception';
import { Head, Link } from '@inertiajs/react';
import { CalendarClock, ClipboardList, ListChecks, Search, UserPlus, Users } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Reception', href: '/reception' }];

interface ReceptionDashboardProps {
    summary: ReceptionDashboardSummary;
    todaysAppointments: TodaysAppointmentRow[];
    todaysWalkIns: ReceptionVisit[];
}

export default function ReceptionDashboard({ summary, todaysAppointments, todaysWalkIns }: ReceptionDashboardProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Reception" />

            <div className="flex flex-col gap-6 p-4">
                <PageHeader
                    title="Reception"
                    description="Front-desk overview for today's appointments and walk-ins."
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link href={route('patients.index')}>
                                    <Search className="h-4 w-4" />
                                    Search Patient
                                </Link>
                            </Button>
                            <Can permission="reception.create_walkin">
                                <Button asChild>
                                    <Link href={route('reception.walk-in.create')}>
                                        <UserPlus className="h-4 w-4" />
                                        Register Walk-in
                                    </Link>
                                </Button>
                            </Can>
                            <Button variant="outline" asChild>
                                <Link href={route('reception.visits.index')}>
                                    <ListChecks className="h-4 w-4" />
                                    View Today's Visits
                                </Link>
                            </Button>
                        </>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard label="Total Appointments" value={summary.total_appointments} icon={CalendarClock} />
                    <StatCard label="Checked In" value={summary.checked_in} icon={Users} />
                    <StatCard label="Waiting" value={summary.waiting} icon={Users} />
                    <StatCard label="Ready for Service" value={summary.ready_for_service} icon={Users} />
                    <StatCard label="Sent to Service" value={summary.sent_to_service} icon={Users} />
                    <StatCard label="Completed" value={summary.completed} icon={ClipboardList} />
                    <StatCard label="Cancelled" value={summary.cancelled} icon={ClipboardList} />
                    <StatCard label="No Show" value={summary.no_show} icon={ClipboardList} />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Today's Appointments</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Time</TableHead>
                                    <TableHead>Patient</TableHead>
                                    <TableHead>Department</TableHead>
                                    <TableHead>Practitioner</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Appointment Status</TableHead>
                                    <TableHead>Reception Status</TableHead>
                                    <TableHead>Coverage</TableHead>
                                    <TableHead>Payment</TableHead>
                                    <TableHead>Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {todaysAppointments.map((appointment) => (
                                    <TableRow key={appointment.id}>
                                        <TableCell>
                                            {new Date(appointment.scheduled_start).toLocaleTimeString(undefined, {
                                                hour: '2-digit',
                                                minute: '2-digit',
                                            })}
                                        </TableCell>
                                        <TableCell>
                                            {appointment.patient ? (
                                                <>
                                                    {[appointment.patient.first_name, appointment.patient.middle_name, appointment.patient.last_name]
                                                        .filter(Boolean)
                                                        .join(' ')}
                                                    <span className="text-muted-foreground block text-xs">{appointment.patient.patient_number}</span>
                                                </>
                                            ) : (
                                                '—'
                                            )}
                                        </TableCell>
                                        <TableCell>{appointment.department?.name ?? '—'}</TableCell>
                                        <TableCell>
                                            {appointment.practitioner ? `${appointment.practitioner.first_name} ${appointment.practitioner.last_name}` : '—'}
                                        </TableCell>
                                        <TableCell className="capitalize">{appointment.appointment_type.replace(/_/g, ' ')}</TableCell>
                                        <TableCell className="capitalize">{appointment.status.replace(/_/g, ' ')}</TableCell>
                                        <TableCell>
                                            {appointment.reception_visit ? (
                                                <Badge variant={receptionStatusVariant[appointment.reception_visit.status]}>
                                                    {receptionStatusLabel[appointment.reception_visit.status]}
                                                </Badge>
                                            ) : (
                                                <span className="text-muted-foreground text-sm">Not arrived</span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={insuranceIndicatorVariant[appointment.insurance_indicator]}>
                                                {insuranceIndicatorLabel[appointment.insurance_indicator]}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            {appointment.financial_status === 'payment_required' ? (
                                                <Badge variant="destructive" className="bg-amber-600 text-white dark:bg-amber-700">
                                                    PAYMENT REQUIRED
                                                </Badge>
                                            ) : appointment.financial_status === 'authorized' ? (
                                                <Badge variant="default" className="bg-green-600 text-white dark:bg-green-700">
                                                    AUTHORIZED
                                                </Badge>
                                            ) : (
                                                <span className="text-muted-foreground text-xs">—</span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {appointment.reception_visit ? (
                                                <Button variant="ghost" size="sm" asChild>
                                                    <Link href={route('reception.visits.show', appointment.reception_visit.id)}>View</Link>
                                                </Button>
                                            ) : (
                                                <Can permission="reception.checkin">
                                                    {['scheduled', 'confirmed'].includes(appointment.status) && (
                                                        <CheckInAppointmentDialog
                                                            appointment={appointment}
                                                            trigger={
                                                                <Button size="sm" variant="outline">
                                                                    Check In
                                                                </Button>
                                                            }
                                                        />
                                                    )}
                                                </Can>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}

                                {todaysAppointments.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={10} className="p-0">
                                            <EmptyState icon={CalendarClock} title="No appointments today" />
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Today's Walk-ins</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Visit No</TableHead>
                                    <TableHead>Patient</TableHead>
                                    <TableHead>Department</TableHead>
                                    <TableHead>Arrival Time</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Payment</TableHead>
                                    <TableHead>Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {todaysWalkIns.map((visit) => (
                                    <TableRow key={visit.id}>
                                        <TableCell>
                                            <Link href={route('reception.visits.show', visit.id)} className="font-medium hover:underline">
                                                {visit.visit_number}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            {visit.patient
                                                ? [visit.patient.first_name, visit.patient.middle_name, visit.patient.last_name].filter(Boolean).join(' ')
                                                : '—'}
                                        </TableCell>
                                        <TableCell>{visit.department?.name ?? '—'}</TableCell>
                                        <TableCell>{new Date(visit.created_at).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })}</TableCell>
                                        <TableCell>
                                            <Badge variant={receptionStatusVariant[visit.status]}>{receptionStatusLabel[visit.status]}</Badge>
                                        </TableCell>
                                        <TableCell>
                                            {visit.financial_status === 'payment_required' ? (
                                                <Badge variant="destructive" className="bg-amber-600 text-white dark:bg-amber-700">
                                                    PAYMENT REQUIRED
                                                </Badge>
                                            ) : visit.financial_status === 'authorized' ? (
                                                <Badge variant="default" className="bg-green-600 text-white dark:bg-green-700">
                                                    AUTHORIZED
                                                </Badge>
                                            ) : (
                                                <span className="text-muted-foreground text-xs">—</span>
                                            )}
                                        </TableCell>
                                        <TableCell className="flex items-center gap-1">
                                            {visit.financial_status === 'payment_required' && visit.patient_account_id ? (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                    className="border-amber-500/50 text-amber-700 dark:text-amber-400"
                                                >
                                                    {/*<Link href={route('billing.accounts.show', visit.patient_account_id)}>Pay Bill</Link>*/}
                                                </Button>
                                            ) : null}
                                            <Button variant="ghost" size="sm" asChild>
                                                <Link href={route('reception.visits.show', visit.id)}>View</Link>
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}

                                {todaysWalkIns.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="p-0">
                                            <EmptyState icon={Users} title="No walk-ins today" />
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
