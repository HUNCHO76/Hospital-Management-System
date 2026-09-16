import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { RadiologyReceptionDialog } from '@/components/radiology/radiology-reception-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import {
    radiologyAppointmentStatusLabel,
    radiologyAppointmentStatusVariant,
    radiologyOrderPriorityLabel,
    radiologyOrderPriorityVariant,
} from '@/lib/radiology-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type RadiologyReceptionAppointment, type RadiologyReceptionFilters } from '@/types/radiology';
import { Head, router } from '@inertiajs/react';
import { ClipboardCheck } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Radiology', href: '/radiology' },
    { title: 'Reception', href: '/radiology/reception' },
];

interface RadiologyReceptionIndexProps {
    appointments: Paginated<RadiologyReceptionAppointment>;
    filters: RadiologyReceptionFilters;
}

function formatTime(value: string): string {
    const localValue = value.replace(/(Z|[+-]\d{2}:\d{2})$/, '');
    return new Date(localValue).toLocaleTimeString(undefined, { timeStyle: 'short' });
}

function isLate(appointment: RadiologyReceptionAppointment): boolean {
    if (appointment.arrived_at) {
        return new Date(appointment.arrived_at) > new Date(appointment.scheduled_start);
    }

    return !['ready', 'cancelled', 'no_show'].includes(appointment.status) && new Date(appointment.scheduled_start) < new Date();
}

/**
 * The radiology reception dashboard (Phase 5, Step 40, Part 19): today's
 * radiology patients, their arrival/preparation status, and the action
 * needed next. Ends at READY — the actual examination is a later step.
 */
export default function RadiologyReceptionIndex({ appointments, filters }: RadiologyReceptionIndexProps) {
    const applyFilters = (overrides: Partial<RadiologyReceptionFilters>) => {
        const params: Record<string, string> = { ...filters, ...overrides };
        router.get(route('radiology-reception.index'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(filters.search || filters.status || filters.arrival_status || filters.date || filters.date_from || filters.date_to);

    const quickFilters: { label: string; onClick: () => void }[] = [
        { label: 'All', onClick: () => applyFilters({ status: '', arrival_status: '' }) },
        { label: 'Not Arrived', onClick: () => applyFilters({ arrival_status: 'not_arrived', status: '' }) },
        { label: 'Arrived', onClick: () => applyFilters({ arrival_status: 'arrived', status: '' }) },
        { label: 'Preparation Pending', onClick: () => applyFilters({ status: 'preparation_pending', arrival_status: '' }) },
        { label: 'Ready', onClick: () => applyFilters({ status: 'ready', arrival_status: '' }) },
        { label: 'Not Ready', onClick: () => applyFilters({ status: 'not_ready', arrival_status: '' }) },
    ];

    const patientName = (appointment: RadiologyReceptionAppointment): string =>
        appointment.patient
            ? [appointment.patient.first_name, appointment.patient.middle_name, appointment.patient.last_name].filter(Boolean).join(' ')
            : '—';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Radiology Reception" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Radiology Reception</h1>
                    <p className="text-muted-foreground text-sm">Today's radiology patients — arrival, verification, and preparation.</p>
                </div>

                <div className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        {quickFilters.map((quick) => (
                            <Button key={quick.label} type="button" size="sm" variant="outline" onClick={quick.onClick}>
                                {quick.label}
                            </Button>
                        ))}
                    </div>

                    <Input
                        value={filters.search || ''}
                        onChange={(e) => applyFilters({ search: e.target.value })}
                        placeholder="Search by patient number, name, order number, or phone"
                        aria-label="Search radiology reception"
                        className="max-w-sm"
                    />

                    <div className="flex flex-wrap items-center gap-2">
                        <Input
                            type="date"
                            value={filters.date || ''}
                            onChange={(e) => applyFilters({ date: e.target.value })}
                            className="w-[160px]"
                        />
                        {hasFilters && (
                            <Button variant="ghost" size="sm" onClick={() => router.get(route('radiology-reception.index'))}>
                                Clear filters
                            </Button>
                        )}
                    </div>
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Time</TableHead>
                            <TableHead>Patient</TableHead>
                            <TableHead>Order</TableHead>
                            <TableHead>Procedure</TableHead>
                            <TableHead>Priority</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead className="text-right">Action</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {appointments.data.map((appointment) => (
                            <TableRow key={appointment.id}>
                                <TableCell>
                                    <div className="flex flex-col">
                                        <span>{formatTime(appointment.scheduled_start)}</span>
                                        {isLate(appointment) && <span className="text-destructive text-xs">Late</span>}
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <div className="flex flex-col">
                                        <span>{patientName(appointment)}</span>
                                        {appointment.patient && (
                                            <span className="text-muted-foreground text-xs">{appointment.patient.patient_number}</span>
                                        )}
                                    </div>
                                </TableCell>
                                <TableCell>{appointment.order?.order_number ?? '—'}</TableCell>
                                <TableCell>{appointment.item?.procedure_name ?? '—'}</TableCell>
                                <TableCell>
                                    {appointment.order && (
                                        <Badge variant={radiologyOrderPriorityVariant[appointment.order.priority]}>
                                            {radiologyOrderPriorityLabel[appointment.order.priority].toUpperCase()}
                                        </Badge>
                                    )}
                                </TableCell>
                                <TableCell>
                                    <Badge variant={radiologyAppointmentStatusVariant[appointment.status]}>
                                        {radiologyAppointmentStatusLabel[appointment.status]}
                                    </Badge>
                                </TableCell>
                                <TableCell className="text-right">
                                    <RadiologyReceptionDialog
                                        appointment={appointment}
                                        trigger={
                                            <Button size="sm" variant="outline">
                                                Manage
                                            </Button>
                                        }
                                    />
                                </TableCell>
                            </TableRow>
                        ))}

                        {appointments.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={7} className="p-0">
                                    <EmptyState
                                        icon={ClipboardCheck}
                                        title={hasFilters ? 'No appointments match your search or filters' : 'No radiology patients today'}
                                        description={
                                            hasFilters
                                                ? 'Try a different search term or clear your filters.'
                                                : 'Scheduled radiology appointments for today will appear here.'
                                        }
                                    />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>

                <Pagination links={appointments.links} />
            </div>
        </AppLayout>
    );
}
