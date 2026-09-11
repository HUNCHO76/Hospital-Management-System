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
    type RadiologyReport,
    type RadiologyReportStatus,
    type RadiologyVerificationFilters,
} from '@/types/radiology';
import { Head, Link, router } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Radiology', href: '/radiology' },
    { title: 'Verification', href: '/radiology/verification' },
];

interface RadiologyVerificationIndexProps {
    reports: Paginated<RadiologyReport>;
    filters: RadiologyVerificationFilters;
    reportStatuses: RadiologyReportStatus[];
    modalities: RadiologyModality[];
    priorities: RadiologyOrderPriority[];
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

/**
 * The radiology report verification worklist (Phase 5, Step 43, Part 6):
 * submitted reports awaiting independent review, sorted by clinical
 * priority then submission time (never a user-toggleable sort). Defaults to
 * the actionable SUBMITTED queue; the status filter can widen it to review
 * already-decided reports (VERIFIED/RETURNED) for reference.
 */
export default function RadiologyVerificationIndex({ reports, filters, reportStatuses, modalities, priorities }: RadiologyVerificationIndexProps) {
    const applyFilters = (overrides: Partial<RadiologyVerificationFilters>) => {
        const params: Record<string, string> = { ...filters, ...overrides };
        router.get(route('radiology-verification.index'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(
        filters.search || filters.status || filters.modality || filters.priority || filters.date || filters.date_from || filters.date_to,
    );

    const patientName = (report: RadiologyReport): string =>
        report.patient ? [report.patient.first_name, report.patient.middle_name, report.patient.last_name].filter(Boolean).join(' ') : '—';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Radiology Verification" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Radiology Verification</h1>
                    <p className="text-muted-foreground text-sm">Submitted reports awaiting independent review and verification.</p>
                </div>

                <div className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="button" size="sm" variant="outline" onClick={() => applyFilters({ status: '' })}>
                            Pending Verification
                        </Button>
                        <Button type="button" size="sm" variant="outline" onClick={() => applyFilters({ status: 'verified' })}>
                            Verified
                        </Button>
                        <Button type="button" size="sm" variant="outline" onClick={() => applyFilters({ status: 'returned' })}>
                            Returned
                        </Button>
                    </div>

                    <Input
                        value={filters.search || ''}
                        onChange={(e) => applyFilters({ search: e.target.value })}
                        placeholder="Search by order number, procedure, or patient"
                        aria-label="Search reports"
                        className="max-w-sm"
                    />

                    <div className="flex flex-wrap items-center gap-2">
                        <Select value={filters.status || 'all'} onValueChange={(value) => applyFilters({ status: value === 'all' ? '' : value })}>
                            <SelectTrigger className="w-[170px]">
                                <SelectValue placeholder="Pending Verification" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Pending Verification</SelectItem>
                                {reportStatuses
                                    .filter((status) => status !== 'draft')
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
                            <Button variant="ghost" size="sm" onClick={() => router.get(route('radiology-verification.index'))}>
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
                            <TableHead>Reporting Practitioner</TableHead>
                            <TableHead>Submitted</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Reviewer</TableHead>
                            <TableHead className="text-right">Action</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {reports.data.map((report) => (
                            <TableRow key={report.id}>
                                <TableCell>
                                    <div className="flex flex-col">
                                        <span>{patientName(report)}</span>
                                        {report.patient && <span className="text-muted-foreground text-xs">{report.patient.patient_number}</span>}
                                    </div>
                                </TableCell>
                                <TableCell>{report.order?.order_number ?? '—'}</TableCell>
                                <TableCell>{report.item?.procedure_name ?? '—'}</TableCell>
                                <TableCell>{report.examination?.modality ? radiologyModalityLabel[report.examination.modality] : '—'}</TableCell>
                                <TableCell>
                                    {report.order && (
                                        <Badge variant={radiologyOrderPriorityVariant[report.order.priority]}>
                                            {radiologyOrderPriorityLabel[report.order.priority].toUpperCase()}
                                        </Badge>
                                    )}
                                </TableCell>
                                <TableCell>{report.reporting_practitioner?.name ?? '—'}</TableCell>
                                <TableCell>{formatDateTime(report.submitted_at)}</TableCell>
                                <TableCell>
                                    <Badge variant={radiologyReportStatusVariant[report.status]}>{radiologyReportStatusLabel[report.status]}</Badge>
                                </TableCell>
                                <TableCell>{report.verifying_practitioner?.name ?? '—'}</TableCell>
                                <TableCell className="text-right">
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={route('radiology-verification.review', report.id)}>
                                            {report.status === 'submitted' ? 'Review' : 'View'}
                                        </Link>
                                    </Button>
                                </TableCell>
                            </TableRow>
                        ))}

                        {reports.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={10} className="p-0">
                                    <EmptyState
                                        icon={ShieldCheck}
                                        title={hasFilters ? 'No reports match your search or filters' : 'Nothing awaiting verification'}
                                        description={
                                            hasFilters
                                                ? 'Try a different search term or clear your filters.'
                                                : 'Submitted reports will appear here for independent review.'
                                        }
                                    />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>

                <Pagination links={reports.links} />
            </div>
        </AppLayout>
    );
}
