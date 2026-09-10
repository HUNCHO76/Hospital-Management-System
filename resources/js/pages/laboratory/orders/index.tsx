import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { laboratoryOrderPriorityLabel, laboratoryOrderStatusLabel, laboratoryOrderStatusVariant } from '@/lib/laboratory-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type LaboratoryOrder, type LaboratoryOrderFilters, type LaboratoryOrderPriority, type LaboratoryOrderStatus } from '@/types/laboratory';
import { Head, Link, router } from '@inertiajs/react';
import { FlaskConical } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Laboratory', href: '/laboratory' },
    { title: 'Orders', href: '/laboratory/orders' },
];

interface LaboratoryOrderDepartment {
    id: number;
    name: string;
    code: string;
}

interface LaboratoryOrderPractitionerOption {
    id: number;
    practitioner_number: string;
    staff: { first_name: string; last_name: string } | null;
}

interface LaboratoryOrdersIndexProps {
    orders: Paginated<LaboratoryOrder>;
    filters: LaboratoryOrderFilters & { practitioner_id?: string; department_id?: string };
    statuses: LaboratoryOrderStatus[];
    priorities: LaboratoryOrderPriority[];
    departments: LaboratoryOrderDepartment[];
    practitioners: LaboratoryOrderPractitionerOption[];
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

function todayIso(): string {
    return new Date().toISOString().slice(0, 10);
}

/**
 * The full paginated, filterable laboratory order worklist (Phase 4, Step
 * 30, spec §28-29) — split out from the dashboard so Dashboard/Orders/Tests
 * are three distinct nav destinations.
 */
export default function LaboratoryOrdersIndex({ orders, filters, statuses, priorities, departments, practitioners }: LaboratoryOrdersIndexProps) {
    const applyFilters = (overrides: Partial<typeof filters>) => {
        const params: Record<string, string> = { ...filters, ...overrides };
        router.get(route('laboratory.orders.index'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(
        filters.search ||
            filters.status ||
            filters.priority ||
            filters.practitioner_id ||
            filters.department_id ||
            filters.date_from ||
            filters.date_to,
    );

    const patientName = (order: LaboratoryOrder): string =>
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
            <Head title="Laboratory Orders" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Laboratory Orders</h1>
                    <p className="text-muted-foreground text-sm">Every laboratory order placed across all clinical encounters.</p>
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
                        aria-label="Search laboratory orders"
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
                                        {laboratoryOrderStatusLabel[status]}
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
                                        {laboratoryOrderPriorityLabel[priority]}
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
                            <Button variant="ghost" size="sm" onClick={() => router.get(route('laboratory.orders.index'))}>
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
                                <TableHead>Tests</TableHead>
                                <TableHead>Priority</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Ordered</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {orders.data.map((order) => (
                                <TableRow key={order.id}>
                                    <TableCell>
                                        <Link href={route('laboratory.orders.show', order.id)} className="font-medium hover:underline">
                                            {order.order_number}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex flex-col">
                                            <span>{patientName(order)}</span>
                                            {order.patient && <span className="text-muted-foreground text-xs">{order.patient.patient_number}</span>}
                                        </div>
                                    </TableCell>
                                    <TableCell>{order.items.map((item) => item.test_code).join(', ') || '—'}</TableCell>
                                    <TableCell>{laboratoryOrderPriorityLabel[order.priority]}</TableCell>
                                    <TableCell>
                                        <Badge variant={laboratoryOrderStatusVariant[order.status]}>{laboratoryOrderStatusLabel[order.status]}</Badge>
                                    </TableCell>
                                    <TableCell>{formatDateTime(order.ordered_at)}</TableCell>
                                </TableRow>
                            ))}

                            {orders.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={6} className="p-0">
                                        <EmptyState
                                            icon={FlaskConical}
                                            title={hasFilters ? 'No orders match your search or filters' : 'No laboratory orders yet'}
                                            description={
                                                hasFilters
                                                    ? 'Try a different search term or clear your filters.'
                                                    : 'Laboratory orders appear here once a doctor orders a test from a clinical encounter.'
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
