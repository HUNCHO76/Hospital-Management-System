import { StatCard } from '@/components/dashboard/stat-card';
import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import {
    laboratoryAbnormalFlagLabel,
    laboratoryOrderPriorityLabel,
    laboratoryResultStatusLabel,
    laboratoryResultStatusVariant,
    laboratorySpecimenTypeLabel,
} from '@/lib/laboratory-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import {
    type LaboratoryOrderPriority,
    type LaboratoryResult,
    type LaboratoryResultFilters,
    type LaboratoryResultStatus,
    type LaboratorySpecimenType,
} from '@/types/laboratory';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, FlaskConical } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Laboratory', href: '/laboratory' },
    { title: 'Results', href: '/laboratory/results' },
];

interface LaboratoryResultsIndexProps {
    results: Paginated<LaboratoryResult>;
    filters: LaboratoryResultFilters;
    statusCounts: Partial<Record<LaboratoryResultStatus, number>>;
    submittedToday: number;
    verifiedToday: number;
    releasedToday: number;
    criticalPending: number;
    reportsGeneratedToday: number;
    amendedReportVersions: number;
    statuses: LaboratoryResultStatus[];
    priorities: LaboratoryOrderPriority[];
    specimenTypes: LaboratorySpecimenType[];
}

/**
 * The result-entry/verification/release dashboard/worklist (Phase 4, Steps
 * 33-35, spec §9-10/Part 33-34) — real per-status counts plus a filterable,
 * paginated table. One row per test's own result (a specimen carrying
 * several tests appears once per test). This is deliberately the ONE
 * worklist spanning the full lifecycle through release — no separate
 * "release worklist" page was built (spec's own anti-duplication
 * requirement), since 90% of it would otherwise repeat this page.
 */
export default function LaboratoryResultsIndex({
    results,
    filters,
    statusCounts,
    submittedToday,
    verifiedToday,
    releasedToday,
    criticalPending,
    reportsGeneratedToday,
    amendedReportVersions,
    statuses,
    priorities,
    specimenTypes,
}: LaboratoryResultsIndexProps) {
    const applyFilters = (overrides: Partial<LaboratoryResultFilters>) => {
        const params: Record<string, string> = { ...filters, ...overrides };
        router.get(route('laboratory.results.index'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(
        filters.search || filters.status || filters.priority || filters.specimen_type || filters.abnormal || filters.date_from || filters.date_to,
    );

    const patientName = (result: LaboratoryResult): string =>
        result.patient ? [result.patient.first_name, result.patient.middle_name, result.patient.last_name].filter(Boolean).join(' ') : '—';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Laboratory Results" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Laboratory Results</h1>
                    <p className="text-muted-foreground text-sm">Result entry through verification, authorization, and release readiness.</p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {statuses.map((status) => (
                        <StatCard key={status} label={laboratoryResultStatusLabel[status]} value={statusCounts[status] ?? 0} icon={FlaskConical} />
                    ))}
                    <StatCard label="Submitted Today" value={submittedToday} icon={FlaskConical} hint="Submitted for verification today" />
                    <StatCard label="Verified Today" value={verifiedToday} icon={FlaskConical} hint="Verified today" />
                    <StatCard label="Released Today" value={releasedToday} icon={FlaskConical} hint="Released today" />
                    <StatCard
                        label="Critical Results Pending"
                        value={criticalPending}
                        icon={AlertTriangle}
                        hint="Flagged critical, not yet released"
                        className={criticalPending > 0 ? 'border-destructive' : undefined}
                    />
                    <StatCard label="Reports Generated Today" value={reportsGeneratedToday} icon={FlaskConical} hint="New report versions today" />
                    <StatCard
                        label="Amended Reports"
                        value={amendedReportVersions}
                        icon={FlaskConical}
                        hint="Report versions issued after a correction"
                    />
                </div>

                <div className="flex flex-col gap-3">
                    <Input
                        value={filters.search || ''}
                        onChange={(e) => applyFilters({ search: e.target.value })}
                        placeholder="Search by sample number, order number, test, or patient"
                        aria-label="Search"
                        className="max-w-sm"
                    />

                    <div className="flex flex-wrap items-center gap-2">
                        <Select value={filters.status || 'all'} onValueChange={(value) => applyFilters({ status: value === 'all' ? '' : value })}>
                            <SelectTrigger className="w-[190px]">
                                <SelectValue placeholder="All statuses" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All statuses</SelectItem>
                                {statuses.map((status) => (
                                    <SelectItem key={status} value={status}>
                                        {laboratoryResultStatusLabel[status]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select value={filters.priority || 'all'} onValueChange={(value) => applyFilters({ priority: value === 'all' ? '' : value })}>
                            <SelectTrigger className="w-[150px]">
                                <SelectValue placeholder="All priorities" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All priorities</SelectItem>
                                {priorities.map((priority) => (
                                    <SelectItem key={priority} value={priority}>
                                        {laboratoryOrderPriorityLabel[priority]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.specimen_type || 'all'}
                            onValueChange={(value) => applyFilters({ specimen_type: value === 'all' ? '' : value })}
                        >
                            <SelectTrigger className="w-[160px]">
                                <SelectValue placeholder="All specimens" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All specimens</SelectItem>
                                {specimenTypes.map((type) => (
                                    <SelectItem key={type} value={type}>
                                        {laboratorySpecimenTypeLabel[type]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select value={filters.abnormal || 'all'} onValueChange={(value) => applyFilters({ abnormal: value === 'all' ? '' : value })}>
                            <SelectTrigger className="w-[150px]">
                                <SelectValue placeholder="All results" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All results</SelectItem>
                                <SelectItem value="abnormal">Abnormal only</SelectItem>
                                <SelectItem value="critical">Critical only</SelectItem>
                            </SelectContent>
                        </Select>

                        <Input
                            type="date"
                            className="w-[160px]"
                            value={filters.date_from || ''}
                            onChange={(e) => applyFilters({ date_from: e.target.value })}
                            aria-label="From date"
                        />
                        <Input
                            type="date"
                            className="w-[160px]"
                            value={filters.date_to || ''}
                            onChange={(e) => applyFilters({ date_to: e.target.value })}
                            aria-label="To date"
                        />

                        {hasFilters && (
                            <Button variant="ghost" size="sm" onClick={() => router.get(route('laboratory.results.index'))}>
                                Clear filters
                            </Button>
                        )}
                    </div>
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Sample No.</TableHead>
                            <TableHead>Patient</TableHead>
                            <TableHead>Test</TableHead>
                            <TableHead>Priority</TableHead>
                            <TableHead>Flag</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Entered By</TableHead>
                            <TableHead />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {results.data.map((result) => (
                            <TableRow key={result.id}>
                                <TableCell className="font-medium">{result.sample?.sample_number ?? '—'}</TableCell>
                                <TableCell>
                                    <div className="flex flex-col">
                                        <span>{patientName(result)}</span>
                                        {result.patient && <span className="text-muted-foreground text-xs">{result.patient.patient_number}</span>}
                                    </div>
                                </TableCell>
                                <TableCell>
                                    {result.item?.test_code} — {result.item?.test_name}
                                </TableCell>
                                <TableCell>
                                    {result.laboratory_order ? laboratoryOrderPriorityLabel[result.laboratory_order.priority] : '—'}
                                </TableCell>
                                <TableCell>
                                    {result.abnormal_flag && result.abnormal_flag !== 'normal' && (
                                        <Badge variant={result.abnormal_flag === 'critical' ? 'destructive' : 'default'}>
                                            {laboratoryAbnormalFlagLabel[result.abnormal_flag]}
                                        </Badge>
                                    )}
                                </TableCell>
                                <TableCell>
                                    <Badge variant={laboratoryResultStatusVariant[result.status]}>{laboratoryResultStatusLabel[result.status]}</Badge>
                                </TableCell>
                                <TableCell>{result.entered_by?.name ?? '—'}</TableCell>
                                <TableCell>
                                    <div className="flex flex-wrap gap-2">
                                        <Button asChild size="sm" variant="outline">
                                            <Link href={route('laboratory.results.show', result.id)}>Open</Link>
                                        </Button>
                                        {result.is_released && result.active_report && (
                                            <Button asChild size="sm" variant="outline">
                                                <Link href={route('laboratory.reports.show', result.active_report.id)}>Report</Link>
                                            </Button>
                                        )}
                                    </div>
                                </TableCell>
                            </TableRow>
                        ))}

                        {results.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={8} className="p-0">
                                    <EmptyState
                                        icon={FlaskConical}
                                        title={hasFilters ? 'No results match your search or filters' : 'No results yet'}
                                        description={
                                            hasFilters
                                                ? 'Try a different search term or clear your filters.'
                                                : "Results appear here once a test's processing reaches result pending."
                                        }
                                    />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>

                <Pagination links={results.links} />
            </div>
        </AppLayout>
    );
}
