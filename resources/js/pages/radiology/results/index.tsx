import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import {
    radiologyModalityLabel,
    radiologyOrderPriorityLabel,
    radiologyOrderPriorityVariant,
    radiologyReportStatusLabel,
    radiologyReportStatusVariant,
} from '@/lib/radiology-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import {
    type RadiologyModality,
    type RadiologyOrderPriority,
    type RadiologyReportStatus,
    type RadiologyReportWorklistAppointment,
    type RadiologyReportWorklistFilters,
} from '@/types/radiology';
import { Head, Link, router } from '@inertiajs/react';
import { FileText } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Radiology', href: '/radiology' },
    { title: 'Results', href: '/radiology/results' },
];

interface RadiologyReportIndexProps {
    appointments: Paginated<RadiologyReportWorklistAppointment>;
    filters: RadiologyReportWorklistFilters;
    reportStatuses: RadiologyReportStatus[];
    modalities: RadiologyModality[];
    priorities: RadiologyOrderPriority[];
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

/**
 * The radiology reporting worklist (Phase 5, Step 42, Part 5): completed
 * examinations awaiting interpretation, whether or not a report has been
 * started yet. STAT/URGENT priorities are always surfaced first
 * server-side (never a user-toggleable sort).
 */
export default function RadiologyReportIndex({ appointments, filters, reportStatuses, modalities, priorities }: RadiologyReportIndexProps) {
    const applyFilters = (overrides: Partial<RadiologyReportWorklistFilters>) => {
        const params: Record<string, string> = { ...filters, ...overrides };
        router.get(route('radiology-results.index'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(
        filters.search || filters.report_status || filters.modality || filters.priority || filters.date || filters.date_from || filters.date_to,
    );

    const quickFilters: { label: string; onClick: () => void }[] = [
        { label: 'All Completed', onClick: () => applyFilters({ report_status: '' }) },
        { label: 'Not Started', onClick: () => applyFilters({ report_status: 'none' }) },
        { label: 'Draft', onClick: () => applyFilters({ report_status: 'draft' }) },
        { label: 'Submitted', onClick: () => applyFilters({ report_status: 'submitted' }) },
    ];

    const patientName = (appointment: RadiologyReportWorklistAppointment): string =>
        appointment.patient
            ? [appointment.patient.first_name, appointment.patient.middle_name, appointment.patient.last_name].filter(Boolean).join(' ')
            : '—';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Radiology Results" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Radiology Results</h1>
                    <p className="text-muted-foreground text-sm">Completed examinations awaiting findings and interpretation.</p>
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
                        placeholder="Search by order number, procedure, or patient"
                        aria-label="Search results"
                        className="max-w-sm"
                    />

                    <div className="flex flex-wrap items-center gap-2">
                        <Select
                            value={filters.report_status || 'all'}
                            onValueChange={(value) => applyFilters({ report_status: value === 'all' ? '' : value })}
                        >
                            <SelectTrigger className="w-[160px]">
                                <SelectValue placeholder="All Completed" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Completed</SelectItem>
                                <SelectItem value="none">Not Started</SelectItem>
                                {reportStatuses
                                    .filter((status) => status !== 'returned')
                                    .map((status) => (
                                        <SelectItem key={status} value={status}>
                                            {radiologyReportStatusLabel[status]}
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
                            type="date"
                            value={filters.date || ''}
                            onChange={(e) => applyFilters({ date: e.target.value })}
                            className="w-[160px]"
                        />

                        {hasFilters && (
                            <Button variant="ghost" size="sm" onClick={() => router.get(route('radiology-results.index'))}>
                                Clear filters
                            </Button>
                        )}
                    </div>
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Patient</TableHead>
                            <TableHead>Order</TableHead>
                            <TableHead>Procedure</TableHead>
                            <TableHead>Modality</TableHead>
                            <TableHead>Priority</TableHead>
                            <TableHead>Examined</TableHead>
                            <TableHead>Examiner</TableHead>
                            <TableHead>Report Status</TableHead>
                            <TableHead>Reporting Practitioner</TableHead>
                            <TableHead className="text-right">Action</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {appointments.data.map((appointment) => (
                            <TableRow key={appointment.id}>
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
                                <TableCell>{appointment.modality ? radiologyModalityLabel[appointment.modality] : '—'}</TableCell>
                                <TableCell>
                                    {appointment.order && (
                                        <Badge variant={radiologyOrderPriorityVariant[appointment.order.priority]}>
                                            {radiologyOrderPriorityLabel[appointment.order.priority].toUpperCase()}
                                        </Badge>
                                    )}
                                </TableCell>
                                <TableCell>{formatDateTime(appointment.completed_at)}</TableCell>
                                <TableCell>{appointment.performed_by?.name ?? '—'}</TableCell>
                                <TableCell>
                                    <Badge variant={appointment.report ? radiologyReportStatusVariant[appointment.report.status] : 'outline'}>
                                        {appointment.report ? radiologyReportStatusLabel[appointment.report.status] : 'Not Started'}
                                    </Badge>
                                </TableCell>
                                <TableCell>{appointment.report?.reporting_practitioner?.name ?? '—'}</TableCell>
                                <TableCell className="text-right">
                                    {appointment.report ? (
                                        <Button asChild size="sm" variant="outline">
                                            <Link href={route('radiology-results.show', appointment.report.id)}>
                                                {appointment.report.status === 'draft' ? 'Continue' : 'View'}
                                            </Link>
                                        </Button>
                                    ) : (
                                        <Button asChild size="sm" variant="outline">
                                            <Link href={route('radiology-results.create', appointment.id)}>Start Report</Link>
                                        </Button>
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}

                        {appointments.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={10} className="p-0">
                                    <EmptyState
                                        icon={FileText}
                                        title={hasFilters ? 'No examinations match your search or filters' : 'Nothing awaiting reporting'}
                                        description={
                                            hasFilters
                                                ? 'Try a different search term or clear your filters.'
                                                : 'Completed examinations will appear here for reporting.'
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
