import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { radiologyModalityLabel, radiologyOrderPriorityLabel, radiologyOrderStatusLabel, radiologyOrderStatusVariant } from '@/lib/radiology-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import {
    type RadiologyModality,
    type RadiologyOrder,
    type RadiologyOrderFilters,
    type RadiologyOrderPriority,
    type RadiologyOrderStatus,
} from '@/types/radiology';
import { Head, Link, router } from '@inertiajs/react';
import { Scan } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Radiology', href: '/radiology' },
    { title: 'Orders', href: '/radiology/orders' },
];

interface RadiologyOrderDepartment {
    id: number;
    name: string;
    code: string;
}

interface RadiologyOrderPractitionerOption {
    id: number;
    practitioner_number: string;
    staff: { first_name: string; last_name: string } | null;
}

interface RadiologyOrdersIndexProps {
    orders: Paginated<RadiologyOrder>;
    filters: RadiologyOrderFilters & { practitioner_id?: string; department_id?: string };
    statuses: RadiologyOrderStatus[];
    priorities: RadiologyOrderPriority[];
    modalities: RadiologyModality[];
    departments: RadiologyOrderDepartment[];
    practitioners: RadiologyOrderPractitionerOption[];
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

function todayIso(): string {
    return new Date().toISOString().slice(0, 10);
}

/**
 * The full paginated, filterable radiology order worklist (Phase 5, Step
 * 36, Part 31) — mirrors LaboratoryOrdersIndex exactly, split out from the
 * dashboard so Dashboard/Orders are distinct nav destinations.
 */
export default function RadiologyOrdersIndex({
    orders,
    filters,
    statuses,
    priorities,
    modalities,
    departments,
    practitioners,
}: RadiologyOrdersIndexProps) {
    const applyFilters = (overrides: Partial<typeof filters>) => {
        const params: Record<string, string> = { ...filters, ...overrides };
        router.get(route('radiology.orders.index'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(
        filters.search ||
            filters.status ||
            filters.priority ||
            filters.modality ||
            filters.practitioner_id ||
            filters.department_id ||
            filters.date_from ||
            filters.date_to,
    );

    const patientName = (order: RadiologyOrder): string =>
        order.patient ? [order.patient.first_name, order.patient.middle_name, order.patient.last_name].filter(Boolean).join(' ') : '—';

    const quickFilters: { label: string; onClick: () => void }[] = [
        { label: 'Pending', onClick: () => applyFilters({ status: 'ordered', date_from: '', date_to: '' }) },
        { label: 'Today', onClick: () => applyFilters({ date_from: todayIso(), date_to: todayIso() }) },
        { label: 'Urgent', onClick: () => applyFilters({ priority: 'urgent' }) },
        { label: 'STAT', onClick: () => applyFilters({ priority: 'stat' }) },
        { label: 'Cancelled', onClick: () => applyFilters({ status: 'cancelled' }) },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Radiology Orders" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Radiology Orders</h1>
                    <p className="text-muted-foreground text-sm">Every radiology order placed across all clinical encounters.</p>
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
                        placeholder="Search by order number or patient"
                        aria-label="Search radiology orders"
                        className="max-w-sm"
                    />

                    <div className="flex flex-wrap items-center gap-2">
                        <Select value={filters.status || 'all'} onValueChange={(value) => applyFilters({ status: value === 'all' ? '' : value })}>
                            <SelectTrigger className="w-[180px]">
                                <SelectValue placeholder="All statuses" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All statuses</SelectItem>
                                {statuses.map((status) => (
                                    <SelectItem key={status} value={status}>
                                        {radiologyOrderStatusLabel[status]}
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

                        <Select
                            value={filters.department_id || 'all'}
                            onValueChange={(value) => applyFilters({ department_id: value === 'all' ? '' : value })}
                        >
                            <SelectTrigger className="w-[180px]">
                                <SelectValue placeholder="All departments" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All departments</SelectItem>
                                {departments.map((department) => (
                                    <SelectItem key={department.id} value={String(department.id)}>
                                        {department.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.practitioner_id || 'all'}
                            onValueChange={(value) => applyFilters({ practitioner_id: value === 'all' ? '' : value })}
                        >
                            <SelectTrigger className="w-[200px]">
                                <SelectValue placeholder="All practitioners" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All practitioners</SelectItem>
                                {practitioners.map((practitioner) => (
                                    <SelectItem key={practitioner.id} value={String(practitioner.id)}>
                                        {practitioner.staff
                                            ? `${practitioner.staff.first_name} ${practitioner.staff.last_name}`
                                            : practitioner.practitioner_number}
                                    </SelectItem>
                                ))}
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
                            <Button variant="ghost" size="sm" onClick={() => router.get(route('radiology.orders.index'))}>
                                Clear filters
                            </Button>
                        )}
                    </div>
                </div>

                <div>
                    <p className="text-muted-foreground mb-2 text-sm">
                        {orders.total > 0
                            ? `Showing ${orders.from ?? 0}–${orders.to ?? 0} of ${orders.total} order${orders.total === 1 ? '' : 's'}`
                            : null}
                    </p>

                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Order No.</TableHead>
                                <TableHead>Patient</TableHead>
                                <TableHead>Procedures</TableHead>
                                <TableHead>Priority</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Ordered</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {orders.data.map((order) => (
                                <TableRow key={order.id}>
                                    <TableCell>
                                        <Link href={route('radiology.orders.show', order.id)} className="font-medium hover:underline">
                                            {order.order_number}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex flex-col">
                                            <span>{patientName(order)}</span>
                                            {order.patient && <span className="text-muted-foreground text-xs">{order.patient.patient_number}</span>}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        {order.items
                                            .map((item) =>
                                                item.modality_snapshot
                                                    ? `${item.procedure_code} (${radiologyModalityLabel[item.modality_snapshot]})`
                                                    : item.procedure_code,
                                            )
                                            .join(', ') || '—'}
                                    </TableCell>
                                    <TableCell>{radiologyOrderPriorityLabel[order.priority]}</TableCell>
                                    <TableCell>
                                        <Badge variant={radiologyOrderStatusVariant[order.status]}>{radiologyOrderStatusLabel[order.status]}</Badge>
                                    </TableCell>
                                    <TableCell>{formatDateTime(order.ordered_at)}</TableCell>
                                </TableRow>
                            ))}

                            {orders.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={6} className="p-0">
                                        <EmptyState
                                            icon={Scan}
                                            title={hasFilters ? 'No orders match your search or filters' : 'No radiology orders yet'}
                                            description={
                                                hasFilters
                                                    ? 'Try a different search term or clear your filters.'
                                                    : 'Radiology orders appear here once a doctor orders a procedure from a clinical encounter.'
                                            }
                                        />
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>

                <Pagination links={orders.links} />
            </div>
        </AppLayout>
    );
}
