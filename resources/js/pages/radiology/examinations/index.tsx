import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import {
    radiologyAppointmentStatusLabel,
    radiologyAppointmentStatusVariant,
    radiologyModalityLabel,
    radiologyOrderPriorityLabel,
    radiologyOrderPriorityVariant,
} from '@/lib/radiology-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import {
    type RadiologyAppointment,
    type RadiologyAppointmentStatus,
    type RadiologyExaminationWorklistFilters,
    type RadiologyModality,
    type RadiologyOrderPriority,
} from '@/types/radiology';
import { Head, Link, router } from '@inertiajs/react';
import { Scan } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Radiology', href: '/radiology' },
    { title: 'Examinations', href: '/radiology/examinations' },
];

interface RadiologyExaminationIndexProps {
    appointments: Paginated<RadiologyAppointment>;
    filters: RadiologyExaminationWorklistFilters;
    statuses: RadiologyAppointmentStatus[];
    modalities: RadiologyModality[];
    priorities: RadiologyOrderPriority[];
}

function formatTime(value: string): string {
    const localValue = value.replace(/(Z|[+-]\d{2}:\d{2})$/, '');
    return new Date(localValue).toLocaleTimeString(undefined, { timeStyle: 'short' });
}

/**
 * The radiology examination worklist (Phase 5, Step 41, Part 15): "what is
 * ready for, or currently under, examination" — defaults to READY/
 * IN_PROGRESS unless an explicit status filter widens it to the COMPLETED/
 * ABORTED history. STAT/URGENT priorities are always surfaced first
 * server-side, and each priority carries an explicit text badge, never
 * color alone.
 */
export default function RadiologyExaminationIndex({ appointments, filters, statuses, modalities, priorities }: RadiologyExaminationIndexProps) {
    const applyFilters = (overrides: Partial<RadiologyExaminationWorklistFilters>) => {
        const params: Record<string, string> = { ...filters, ...overrides };
        router.get(route('radiology-examinations.index'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(
        filters.search || filters.status || filters.modality || filters.resource_name || filters.priority || filters.date || filters.date_from || filters.date_to,
    );

    const quickFilters: { label: string; onClick: () => void }[] = [
        { label: 'Active', onClick: () => applyFilters({ status: '' }) },
        { label: 'Ready', onClick: () => applyFilters({ status: 'ready' }) },
        { label: 'In Progress', onClick: () => applyFilters({ status: 'in_progress' }) },
        { label: 'Completed', onClick: () => applyFilters({ status: 'completed' }) },
        { label: 'Aborted', onClick: () => applyFilters({ status: 'aborted' }) },
    ];

    const patientName = (appointment: RadiologyAppointment): string =>
        appointment.patient
            ? [appointment.patient.first_name, appointment.patient.middle_name, appointment.patient.last_name].filter(Boolean).join(' ')
            : '—';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Radiology Examinations" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Radiology Examinations</h1>
                    <p className="text-muted-foreground text-sm">Patients ready for, or currently under, examination.</p>
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
                        placeholder="Search by order number, procedure, patient, or resource"
                        aria-label="Search examinations"
                        className="max-w-sm"
                    />

                    <div className="flex flex-wrap items-center gap-2">
                        <Select value={filters.status || 'all'} onValueChange={(value) => applyFilters({ status: value === 'all' ? '' : value })}>
                            <SelectTrigger className="w-[160px]">
                                <SelectValue placeholder="Active" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Active</SelectItem>
                                {statuses.map((status) => (
                                    <SelectItem key={status} value={status}>
                                        {radiologyAppointmentStatusLabel[status]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select value={filters.modality || 'all'} onValueChange={(value) => applyFilters({ modality: value === 'all' ? '' : value })}>
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

                        <Select value={filters.priority || 'all'} onValueChange={(value) => applyFilters({ priority: value === 'all' ? '' : value })}>
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

                        <Input
                            value={filters.resource_name || ''}
                            onChange={(e) => applyFilters({ resource_name: e.target.value })}
                            placeholder="Resource"
                            className="w-[180px]"
                            aria-label="Resource"
                        />

                        <Input type="date" value={filters.date || ''} onChange={(e) => applyFilters({ date: e.target.value })} className="w-[160px]" />

                        {hasFilters && (
                            <Button variant="ghost" size="sm" onClick={() => router.get(route('radiology-examinations.index'))}>
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
                            <TableHead>Procedure</TableHead>
                            <TableHead>Modality</TableHead>
                            <TableHead>Priority</TableHead>
                            <TableHead>Order</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Resource</TableHead>
                            <TableHead>Performer</TableHead>
                            <TableHead className="text-right">Action</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {appointments.data.map((appointment) => (
                            <TableRow key={appointment.id}>
                                <TableCell>{formatTime(appointment.scheduled_start)}</TableCell>
                                <TableCell>
                                    <div className="flex flex-col">
                                        <span>{patientName(appointment)}</span>
                                        {appointment.patient && (
                                            <span className="text-muted-foreground text-xs">{appointment.patient.patient_number}</span>
                                        )}
                                    </div>
                                </TableCell>
                                <TableCell>{appointment.item?.procedure_name ?? '—'}</TableCell>
                                <TableCell>{appointment.modality ? radiologyModalityLabel[appointment.modality] : '—'}</TableCell>
                                <TableCell>
                                    {appointment.order && (
                                        <Badge variant={radiologyOrderPriorityVariant[appointment.order.priority]}>
                                            {radiologyOrderPriorityLabel[appointment.order.priority].toUpperCase()}
                                        </Badge>
                                    )}
                                </TableCell>
                                <TableCell>{appointment.order?.order_number ?? '—'}</TableCell>
                                <TableCell>
                                    <Badge variant={radiologyAppointmentStatusVariant[appointment.status]}>
                                        {radiologyAppointmentStatusLabel[appointment.status]}
                                    </Badge>
                                </TableCell>
                                <TableCell>{appointment.resource_name}</TableCell>
                                <TableCell>{appointment.performed_by?.name ?? '—'}</TableCell>
                                <TableCell className="text-right">
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={route('radiology-examinations.show', appointment.id)}>
                                            {appointment.status === 'ready' ? 'Start' : 'View'}
                                        </Link>
                                    </Button>
                                </TableCell>
                            </TableRow>
                        ))}

                        {appointments.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={10} className="p-0">
                                    <EmptyState
                                        icon={Scan}
                                        title={hasFilters ? 'No examinations match your search or filters' : 'Nothing ready for examination'}
                                        description={
                                            hasFilters
                                                ? 'Try a different search term or clear your filters.'
                                                : 'Patients marked ready by radiology reception will appear here.'
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
