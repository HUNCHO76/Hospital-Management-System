import { StatCard } from '@/components/dashboard/stat-card';
import { EmptyState } from '@/components/empty-state';
import { RejectProcessingDialog } from '@/components/laboratory/reject-processing-dialog';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import {
    canAcceptSpecimen,
    canRejectDuringProcessing,
    laboratoryOrderPriorityLabel,
    laboratoryProcessingStatusLabel,
    laboratoryProcessingStatusVariant,
    laboratorySpecimenTypeLabel,
} from '@/lib/laboratory-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import {
    type LaboratoryBenchOption,
    type LaboratoryOrderPriority,
    type LaboratoryProcessingFilters,
    type LaboratoryProcessingStatus,
    type LaboratorySample,
    type LaboratorySpecimenType,
} from '@/types/laboratory';
import { Head, Link, router } from '@inertiajs/react';
import { FlaskConical } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Laboratory', href: '/laboratory' },
    { title: 'Processing', href: '/laboratory/processing' },
];

interface LaboratoryProcessingIndexProps {
    samples: Paginated<LaboratorySample>;
    filters: LaboratoryProcessingFilters;
    statusCounts: Partial<Record<LaboratoryProcessingStatus, number>>;
    statuses: LaboratoryProcessingStatus[];
    priorities: LaboratoryOrderPriority[];
    specimenTypes: LaboratorySpecimenType[];
    benches: LaboratoryBenchOption[];
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * The laboratory processing dashboard/worklist (Phase 4, Step 32, spec
 * §31-32) — real per-test status counts, plus one row per specimen
 * (accept/reject are whole-specimen decisions). Assign/start/complete/
 * transfer act on a single test and live on the detail page, reached via
 * "View".
 */
export default function LaboratoryProcessingIndex({
    samples,
    filters,
    statusCounts,
    statuses,
    priorities,
    specimenTypes,
    benches,
}: LaboratoryProcessingIndexProps) {
    const { can } = usePermissions();

    const applyFilters = (overrides: Partial<LaboratoryProcessingFilters>) => {
        const params: Record<string, string> = { ...filters, ...overrides };
        router.get(route('laboratory.processing.index'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(
        filters.search ||
            filters.status ||
            filters.priority ||
            filters.specimen_type ||
            filters.assigned ||
            filters.bench_id ||
            filters.date_from ||
            filters.date_to,
    );

    const patientName = (sample: LaboratorySample): string =>
        sample.patient ? [sample.patient.first_name, sample.patient.middle_name, sample.patient.last_name].filter(Boolean).join(' ') : '—';

    const acceptSpecimen = (sample: LaboratorySample) => {
        router.post(route('laboratory.processing.accept', sample.id), {}, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Laboratory Processing" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Laboratory Processing</h1>
                    <p className="text-muted-foreground text-sm">Received specimens moving through bench processing to result pending.</p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {statuses.map((status) => (
                        <StatCard
                            key={status}
                            label={laboratoryProcessingStatusLabel[status]}
                            value={statusCounts[status] ?? 0}
                            icon={FlaskConical}
                        />
                    ))}
                </div>

                <div className="flex flex-col gap-3">
                    <Input
                        value={filters.search || ''}
                        onChange={(e) => applyFilters({ search: e.target.value })}
                        placeholder="Search by sample number, order number, or patient"
                        aria-label="Search"
                        className="max-w-sm"
                    />

                    <div className="flex flex-wrap items-center gap-2">
                        <Select value={filters.status || 'all'} onValueChange={(value) => applyFilters({ status: value === 'all' ? '' : value })}>
                            <SelectTrigger className="w-[170px]">
                                <SelectValue placeholder="All statuses" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All statuses</SelectItem>
                                {statuses.map((status) => (
                                    <SelectItem key={status} value={status}>
                                        {laboratoryProcessingStatusLabel[status]}
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

                        <Select value={filters.bench_id || 'all'} onValueChange={(value) => applyFilters({ bench_id: value === 'all' ? '' : value })}>
                            <SelectTrigger className="w-[160px]">
                                <SelectValue placeholder="All benches" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All benches</SelectItem>
                                {benches.map((bench) => (
                                    <SelectItem key={bench.id} value={String(bench.id)}>
                                        {bench.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select value={filters.assigned || 'all'} onValueChange={(value) => applyFilters({ assigned: value === 'all' ? '' : value })}>
                            <SelectTrigger className="w-[150px]">
                                <SelectValue placeholder="Assigned/Unassigned" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All</SelectItem>
                                <SelectItem value="assigned">Assigned</SelectItem>
                                <SelectItem value="unassigned">Unassigned</SelectItem>
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
                            <Button variant="ghost" size="sm" onClick={() => router.get(route('laboratory.processing.index'))}>
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
                            <TableHead>Order No.</TableHead>
                            <TableHead>Tests</TableHead>
                            <TableHead>Priority</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Received</TableHead>
                            <TableHead />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {samples.data.map((sample) => (
                            <TableRow key={sample.id}>
                                <TableCell className="font-medium">{sample.sample_number}</TableCell>
                                <TableCell>
                                    <div className="flex flex-col">
                                        <span>{patientName(sample)}</span>
                                        {sample.patient && <span className="text-muted-foreground text-xs">{sample.patient.patient_number}</span>}
                                    </div>
                                </TableCell>
                                <TableCell>{sample.laboratory_order?.order_number ?? '—'}</TableCell>
                                <TableCell>{sample.items.map((i) => i.test_code).join(', ')}</TableCell>
                                <TableCell>
                                    {sample.laboratory_order ? laboratoryOrderPriorityLabel[sample.laboratory_order.priority] : '—'}
                                </TableCell>
                                <TableCell>
                                    {sample.processing_status && (
                                        <Badge variant={laboratoryProcessingStatusVariant[sample.processing_status]}>
                                            {laboratoryProcessingStatusLabel[sample.processing_status]}
                                        </Badge>
                                    )}
                                </TableCell>
                                <TableCell>{sample.received_at ? formatDateTime(sample.received_at) : '—'}</TableCell>
                                <TableCell>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Button asChild size="sm" variant="outline">
                                            <Link href={route('laboratory.processing.show', sample.id)}>View</Link>
                                        </Button>
                                        {canAcceptSpecimen(sample.status, sample.processing_status) && can('laboratory-processing.accept') && (
                                            <Button size="sm" onClick={() => acceptSpecimen(sample)}>
                                                Accept
                                            </Button>
                                        )}
                                        {canRejectDuringProcessing(sample.status, sample.processing_status) &&
                                            can('laboratory-processing.reject') && (
                                                <RejectProcessingDialog
                                                    sampleId={sample.id}
                                                    sampleNumber={sample.sample_number}
                                                    trigger={
                                                        <Button size="sm" variant="destructive">
                                                            Reject
                                                        </Button>
                                                    }
                                                />
                                            )}
                                    </div>
                                </TableCell>
                            </TableRow>
                        ))}

                        {samples.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={8} className="p-0">
                                    <EmptyState
                                        icon={FlaskConical}
                                        title={hasFilters ? 'No specimens match your search or filters' : 'Nothing in processing yet'}
                                        description={
                                            hasFilters
                                                ? 'Try a different search term or clear your filters.'
                                                : 'Specimens appear here once they have been received at the laboratory.'
                                        }
                                    />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>

                <Pagination links={samples.links} />
            </div>
        </AppLayout>
    );
}
