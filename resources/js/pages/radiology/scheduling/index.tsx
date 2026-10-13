import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { CancelRadiologyAppointmentDialog } from '@/components/radiology/cancel-radiology-appointment-dialog';
import { RescheduleRadiologyAppointmentDialog } from '@/components/radiology/reschedule-radiology-appointment-dialog';
import { ScheduleRadiologyItemDialog } from '@/components/radiology/schedule-radiology-item-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import {
    radiologyAppointmentStatusLabel,
    radiologyAppointmentStatusVariant,
    radiologyModalityLabel,
    radiologyOrderPriorityLabel,
} from '@/lib/radiology-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import {
    type RadiologyAppointment,
    type RadiologyModality,
    type RadiologyOrderPriority,
    type RadiologySchedulingUnscheduledFilters,
    type UnscheduledRadiologyOrderItem,
} from '@/types/radiology';
import { Head, Link, router } from '@inertiajs/react';
import { CalendarClock, CheckCircle2, UserX } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Radiology', href: '/radiology' },
    { title: 'Scheduling', href: '/radiology/scheduling' },
];

interface RadiologySchedulingIndexProps {
    unscheduledItems: Paginated<UnscheduledRadiologyOrderItem>;
    unscheduledFilters: RadiologySchedulingUnscheduledFilters;
    existingBookings: RadiologyAppointment[];
    selectedDate: string;
    selectedResource: string | null;
    knownResources: string[];
    modalities: RadiologyModality[];
    priorities: RadiologyOrderPriority[];
}

function formatTime(value: string): string {
    const localValue = value.replace(/(Z|[+-]\d{2}:\d{2})$/, '');
    return new Date(localValue).toLocaleTimeString(undefined, { timeStyle: 'short' });
}

/**
 * The radiology scheduling dashboard (Phase 5, Step 39): orders awaiting
 * scheduling on one side, the chosen day/resource's existing bookings on
 * the other so staff can see what's already taken before picking a free
 * time. No synthetic slot grid is generated — see the backend controller's
 * own doc-comment for why (no working-hours/room-registry config exists).
 */
export default function RadiologySchedulingIndex({
    unscheduledItems,
    unscheduledFilters,
    existingBookings,
    selectedDate,
    selectedResource,
    knownResources,
    modalities,
    priorities,
}: RadiologySchedulingIndexProps) {
    const { can } = usePermissions();

    const applyUnscheduledFilters = (overrides: Partial<RadiologySchedulingUnscheduledFilters>) => {
        router.get(
            route('radiology-scheduling.index'),
            { ...unscheduledFilters, date: selectedDate, resource_name: selectedResource ?? '', ...overrides },
            { preserveState: true, replace: true },
        );
    };

    const applyBookingFilters = (overrides: { date?: string; resource_name?: string }) => {
        router.get(
            route('radiology-scheduling.index'),
            { ...unscheduledFilters, date: selectedDate, resource_name: selectedResource ?? '', ...overrides },
            { preserveState: true, replace: true },
        );
    };

    const patientName = (patient: { first_name: string; middle_name: string | null; last_name: string } | null): string =>
        patient ? [patient.first_name, patient.middle_name, patient.last_name].filter(Boolean).join(' ') : '—';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Radiology Scheduling" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Radiology Scheduling</h1>
                    <p className="text-muted-foreground text-sm">Schedule ordered procedures onto a resource and time slot.</p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Orders Awaiting Scheduling</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="flex flex-wrap items-center gap-2">
                            <Input
                                value={unscheduledFilters.search || ''}
                                onChange={(e) => applyUnscheduledFilters({ search: e.target.value })}
                                placeholder="Search by order number, procedure, or patient"
                                aria-label="Search unscheduled orders"
                                className="max-w-sm"
                            />

                            <Select
                                value={unscheduledFilters.modality || 'all'}
                                onValueChange={(value) => applyUnscheduledFilters({ modality: value === 'all' ? '' : value })}
                            >
                                <SelectTrigger className="w-[160px]">
                                    <SelectValue placeholder="All modalities" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All modalities</SelectItem>
                                    {modalities.map((modality) => (
                                        <SelectItem key={modality} value={modality}>
                                            {radiologyModalityLabel[modality]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            <Select
                                value={unscheduledFilters.priority || 'all'}
                                onValueChange={(value) => applyUnscheduledFilters({ priority: value === 'all' ? '' : value })}
                            >
                                <SelectTrigger className="w-[160px]">
                                    <SelectValue placeholder="All priorities" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All priorities</SelectItem>
                                    {priorities.map((priority) => (
                                        <SelectItem key={priority} value={priority}>
                                            {radiologyOrderPriorityLabel[priority]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Order</TableHead>
                                    <TableHead>Patient</TableHead>
                                    <TableHead>Procedure</TableHead>
                                    <TableHead>Priority</TableHead>
                                    <TableHead>Payment / Auth</TableHead>
                                    <TableHead className="text-right">Action</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {unscheduledItems.data.map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell>{item.order?.order_number ?? '—'}</TableCell>
                                        <TableCell>
                                            <div className="flex flex-col">
                                                <span>{patientName(item.patient)}</span>
                                                {item.patient && <span className="text-muted-foreground text-xs">{item.patient.patient_number}</span>}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            {item.procedure_code} — {item.procedure_name}
                                            {item.modality_snapshot && (
                                                <span className="text-muted-foreground"> ({radiologyModalityLabel[item.modality_snapshot]})</span>
                                            )}
                                        </TableCell>
                                        <TableCell>{item.order ? radiologyOrderPriorityLabel[item.order.priority] : '—'}</TableCell>
                                        <TableCell>
                                            {(item as any).financial_status === 'payment_required' ? (
                                                <Badge variant="destructive" className="bg-amber-600 dark:bg-amber-700 text-white">
                                                    PAYMENT REQUIRED
                                                </Badge>
                                            ) : (
                                                <Badge variant="default" className="bg-green-600 dark:bg-green-700 text-white">
                                                    AUTHORIZED
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right flex items-center justify-end gap-1">
                                            {(item as any).financial_status === 'payment_required' && (item as any).patient_account_id ? (
                                                <Button variant="outline" size="sm" asChild className="text-amber-700 dark:text-amber-400 border-amber-500/50">
                                                    <Link href={route('billing.accounts.show', (item as any).patient_account_id)}>Pay Bill</Link>
                                                </Button>
                                            ) : null}
                                            {can('radiology-scheduling.create') && (
                                                <ScheduleRadiologyItemDialog
                                                    item={item}
                                                    knownResources={knownResources}
                                                    trigger={
                                                        <Button size="sm" disabled={(item as any).financial_status === 'payment_required'} title={(item as any).financial_status === 'payment_required' ? 'Payment required before scheduling' : undefined}>
                                                            <CalendarClock className="h-4 w-4" />
                                                            Schedule
                                                        </Button>
                                                    }
                                                />
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}

                                {unscheduledItems.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="p-0">
                                            <EmptyState
                                                icon={CalendarClock}
                                                title="No orders awaiting scheduling"
                                                description="Every active radiology order has been scheduled."
                                            />
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>

                        <Pagination links={unscheduledItems.links} />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Existing Bookings</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="flex flex-wrap items-center gap-2">
                            <Input
                                type="date"
                                value={selectedDate}
                                onChange={(e) => applyBookingFilters({ date: e.target.value })}
                                className="w-[160px]"
                                aria-label="Date"
                            />
                            <Input
                                list="known-radiology-resources-filter"
                                value={selectedResource ?? ''}
                                onChange={(e) => applyBookingFilters({ resource_name: e.target.value })}
                                placeholder="All resources"
                                className="w-[220px]"
                                aria-label="Resource"
                            />
                            <datalist id="known-radiology-resources-filter">
                                {knownResources.map((resource) => (
                                    <option key={resource} value={resource} />
                                ))}
                            </datalist>
                        </div>

                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Time</TableHead>
                                    <TableHead>Resource</TableHead>
                                    <TableHead>Patient</TableHead>
                                    <TableHead>Procedure</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {existingBookings.map((appointment) => (
                                    <TableRow key={appointment.id}>
                                        <TableCell>
                                            {formatTime(appointment.scheduled_start)} – {formatTime(appointment.scheduled_end)}
                                        </TableCell>
                                        <TableCell>{appointment.resource_name}</TableCell>
                                        <TableCell>{patientName(appointment.patient)}</TableCell>
                                        <TableCell>{appointment.item?.procedure_name ?? '—'}</TableCell>
                                        <TableCell>
                                            <Badge variant={radiologyAppointmentStatusVariant[appointment.status]}>
                                                {radiologyAppointmentStatusLabel[appointment.status]}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <div className="flex justify-end gap-1">
                                                {appointment.status === 'scheduled' && can('radiology-scheduling.confirm') && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            router.post(
                                                                route('radiology-scheduling.confirm', appointment.id),
                                                                {},
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                    >
                                                        <CheckCircle2 className="h-4 w-4" />
                                                        Confirm
                                                    </Button>
                                                )}

                                                {(appointment.status === 'scheduled' || appointment.status === 'confirmed') &&
                                                    can('radiology-scheduling.reschedule') && (
                                                        <RescheduleRadiologyAppointmentDialog
                                                            appointmentId={appointment.id}
                                                            currentStart={appointment.scheduled_start}
                                                            currentEnd={appointment.scheduled_end}
                                                            trigger={
                                                                <Button size="sm" variant="outline">
                                                                    Reschedule
                                                                </Button>
                                                            }
                                                        />
                                                    )}

                                                {(appointment.status === 'scheduled' || appointment.status === 'confirmed') &&
                                                    can('radiology-scheduling.no_show') && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                router.post(
                                                                    route('radiology-scheduling.no-show', appointment.id),
                                                                    {},
                                                                    { preserveScroll: true },
                                                                )
                                                            }
                                                        >
                                                            <UserX className="h-4 w-4" />
                                                            No-show
                                                        </Button>
                                                    )}

                                                {(appointment.status === 'scheduled' || appointment.status === 'confirmed') &&
                                                    can('radiology-scheduling.cancel') && (
                                                        <CancelRadiologyAppointmentDialog
                                                            appointmentId={appointment.id}
                                                            trigger={
                                                                <Button size="sm" variant="destructive">
                                                                    Cancel
                                                                </Button>
                                                            }
                                                        />
                                                    )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}

                                {existingBookings.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="p-0">
                                            <EmptyState
                                                icon={CalendarClock}
                                                title="No bookings for this day"
                                                description="Nothing is scheduled yet for the selected date and resource."
                                            />
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
