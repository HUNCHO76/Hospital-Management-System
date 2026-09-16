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
    type RadiologyModality,
    type RadiologyOrderPriority,
    type RadiologyWorklistFilters,
} from '@/types/radiology';
import { Head, Link, router } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Radiology', href: '/radiology' },
    { title: 'Worklist', href: '/radiology/worklist' },
];

interface RadiologyWorklistIndexProps {
    appointments: Paginated<RadiologyAppointment>;
    filters: RadiologyWorklistFilters;
    statuses: RadiologyAppointmentStatus[];
    modalities: RadiologyModality[];
    priorities: RadiologyOrderPriority[];
}

function formatTime(value: string): string {
    const localValue = value.replace(/(Z|[+-]\d{2}:\d{2})$/, '');
    return new Date(localValue).toLocaleTimeString(undefined, { timeStyle: 'short' });
}

function formatDate(value: string): string {
    return new Date(value).toLocaleDateString(undefined, { dateStyle: 'medium' });
}

function todayIso(): string {
    return new Date().toISOString().slice(0, 10);
}

function tomorrowIso(): string {
    const d = new Date();
    d.setDate(d.getDate() + 1);
    return d.toISOString().slice(0, 10);
}

/**
 * The radiology worklist (Phase 5, Step 39, Part 14): "what does the
 * department need to handle, and when" — STAT/URGENT priorities are always
 * surfaced first server-side (never a user-toggleable sort, Part 16), and
 * each priority is labeled with an explicit text badge, never color alone
 * (Part 17).
 */
export default function RadiologyWorklistIndex({ appointments, filters, statuses, modalities, priorities }: RadiologyWorklistIndexProps) {
    const applyFilters = (overrides: Partial<typeof filters>) => {
        const params: Record<string, string> = { ...filters, ...overrides };
        router.get(route('radiology-worklist.index'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(
        filters.search ||
            filters.status ||
            filters.modality ||
            filters.resource_name ||
            filters.priority ||
            filters.date ||
            filters.date_from ||
            filters.date_to ||
            filters.overdue,
    );

    const patientName = (appointment: RadiologyAppointment): string =>
        appointment.patient
            ? [appointment.patient.first_name, appointment.patient.middle_name, appointment.patient.last_name].filter(Boolean).join(' ')
            : '—';

    const quickFilters: { label: string; onClick: () => void }[] = [
        { label: 'Today', onClick: () => applyFilters({ date: todayIso(), date_from: '', date_to: '', overdue: '' }) },
        { label: 'Tomorrow', onClick: () => applyFilters({ date: tomorrowIso(), date_from: '', date_to: '', overdue: '' }) },
        { label: 'Upcoming', onClick: () => applyFilters({ date: '', date_from: todayIso(), date_to: '', overdue: '' }) },
        { label: 'Overdue', onClick: () => applyFilters({ overdue: '1', date: '', date_from: '', date_to: '' }) },
        { label: 'Cancelled', onClick: () => applyFilters({ status: 'cancelled', overdue: '' }) },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Radiology Worklist" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Radiology Worklist</h1>
                    <p className="text-muted-foreground text-sm">What the department needs to handle, ordered by priority then time.</p>
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
                        aria-label="Search worklist"
                        className="max-w-sm"
                    />

                    <div className="flex flex-wrap items-center gap-2">
                        <Select value={filters.status || 'all'} onValueChange={(value) => applyFilters({ status: value === 'all' ? '' : value })}>
                            <SelectTrigger className="w-[160px]">
                                <SelectValue placeholder="All statuses" />
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

                        {hasFilters && (
                            <Button variant="ghost" size="sm" onClick={() => router.get(route('radiology-worklist.index'))}>
                                Clear filters
                            </Button>
                        )}
                    </div>
                </div>

                <div>
                    <p className="text-muted-foreground mb-2 text-sm">
                        {appointments.total > 0 ? `Showing ${appointments.from ?? 0}–${appointments.to ?? 0} of ${appointments.total}` : null}
                    </p>

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
                                <TableHead className="text-right">Action</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {appointments.data.map((appointment) => (
                                <TableRow key={appointment.id}>
                                    <TableCell>
                                        <div className="flex flex-col">
                                            <span>{formatTime(appointment.scheduled_start)}</span>
                                            <span className="text-muted-foreground text-xs">{formatDate(appointment.scheduled_start)}</span>
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
                                    <TableCell className="text-right">
                                        {appointment.encounter && (
                                            <Button asChild size="sm" variant="link" className="h-auto p-0">
                                                <Link href={route('clinical-encounters.show', appointment.encounter.id)}>View</Link>
                                            </Button>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}

                            {appointments.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={9} className="p-0">
                                        <EmptyState
                                            icon={ClipboardList}
                                            title={hasFilters ? 'No appointments match your search or filters' : 'Nothing on the worklist'}
                                            description={
                                                hasFilters
                                                    ? 'Try a different search term or clear your filters.'
                                                    : 'Scheduled radiology appointments appear here once staff schedule an order from the queue.'
                                            }
                                        />
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>

                <Pagination links={appointments.links} />
            </div>
        </AppLayout>
    );
}
