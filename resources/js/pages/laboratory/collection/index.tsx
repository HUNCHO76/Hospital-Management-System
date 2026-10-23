import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import {
    laboratoryOrderPriorityLabel,
    laboratoryOrderStatusLabel,
    laboratoryOrderStatusVariant,
    laboratorySpecimenTypeLabel,
} from '@/lib/laboratory-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type LaboratoryCollectionFilters, type LaboratoryOrder, type LaboratoryOrderPriority } from '@/types/laboratory';
import { Head, Link, router } from '@inertiajs/react';
import { AlertCircle, TestTubes } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Laboratory', href: '/laboratory' },
    { title: 'Sample Collection', href: '/laboratory/collection' },
];

interface LaboratoryCollectionIndexProps {
    orders: Paginated<LaboratoryOrder>;
    filters: LaboratoryCollectionFilters;
    priorities: LaboratoryOrderPriority[];
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * The specimen collection worklist (Phase 4, Step 31, spec §25) — every
 * order that still has at least one test awaiting or mid-collection. An
 * order drops off this list automatically once every one of its tests has
 * been received (the aggregate LaboratoryOrder::status rollup handles
 * that) — never a separate manual "done" action.
 */
export default function LaboratoryCollectionIndex({ orders, filters, priorities }: LaboratoryCollectionIndexProps) {
    const applyFilters = (overrides: Partial<LaboratoryCollectionFilters>) => {
        const params: Record<string, string> = { ...filters, ...overrides };
        router.get(route('laboratory.collection.index'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(filters.search || filters.priority || filters.status || filters.date_from || filters.date_to);

    const patientName = (order: LaboratoryOrder): string =>
        order.patient ? [order.patient.first_name, order.patient.middle_name, order.patient.last_name].filter(Boolean).join(' ') : '—';

    const requiredSpecimens = (order: LaboratoryOrder): string => {
        const types = Array.from(new Set(order.items.map((item) => item.specimen_type).filter(Boolean))) as string[];

        return types.length > 0 ? types.map((type) => laboratorySpecimenTypeLabel[type as keyof typeof laboratorySpecimenTypeLabel]).join(', ') : '—';
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sample Collection" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Sample Collection</h1>
                    <p className="text-muted-foreground text-sm">Laboratory orders awaiting or mid-way through specimen collection.</p>
                </div>

                <div className="flex flex-col gap-3">
                    <Input
                        value={filters.search || ''}
                        onChange={(e) => applyFilters({ search: e.target.value })}
                        placeholder="Search by order number or patient"
                        aria-label="Search orders"
                        className="max-w-sm"
                    />

                    <div className="flex flex-wrap items-center gap-2">
                        <Select value={filters.status || 'all'} onValueChange={(value) => applyFilters({ status: value === 'all' ? '' : value })}>
                            <SelectTrigger className="w-[180px]">
                                <SelectValue placeholder="All statuses" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All statuses</SelectItem>
                                <SelectItem value="ordered">Not started</SelectItem>
                                <SelectItem value="sample_pending">Partially collected</SelectItem>
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
                            <Button variant="ghost" size="sm" onClick={() => router.get(route('laboratory.collection.index'))}>
                                Clear filters
                            </Button>
                        )}
                    </div>
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Order No.</TableHead>
                            <TableHead>Patient</TableHead>
                            <TableHead>Tests</TableHead>
                            <TableHead>Required Specimens</TableHead>
                            <TableHead>Priority</TableHead>
                            <TableHead>Ordered At</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Payment</TableHead>
                            <TableHead />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {orders.data.map((order) => {
                            const isCancelled = order.status === 'cancelled' || order.financial_status === 'cancelled';
                            const isNhifSentNotIssued = order.financial_status === 'nhif_sent_not_issued';
                            const isNhifPending = order.financial_status === 'nhif_pending_request';
                            const paymentRequired = order.financial_status === 'payment_required';
                            const isBlocked = paymentRequired || isNhifSentNotIssued || isNhifPending;
                            const activeItemsCount = order.items.filter((i) => i.status !== 'cancelled').length;

                            return (
                            <TableRow key={order.id} className={isCancelled ? 'opacity-60 bg-muted/20' : isBlocked ? 'bg-amber-500/5' : undefined}>
                                <TableCell className="font-medium">{order.order_number}</TableCell>
                                <TableCell>
                                    <div className="flex flex-col">
                                        <span>{patientName(order)}</span>
                                        {order.patient && <span className="text-muted-foreground text-xs">{order.patient.patient_number}</span>}
                                    </div>
                                </TableCell>
                                <TableCell>{activeItemsCount}</TableCell>
                                <TableCell>{requiredSpecimens(order)}</TableCell>
                                <TableCell>{laboratoryOrderPriorityLabel[order.priority]}</TableCell>
                                <TableCell>{formatDateTime(order.ordered_at)}</TableCell>
                                <TableCell>
                                    <Badge variant={laboratoryOrderStatusVariant[order.status]}>{laboratoryOrderStatusLabel[order.status]}</Badge>
                                </TableCell>
                                <TableCell>
                                    {isCancelled ? (
                                        <Badge variant="secondary" className="text-muted-foreground">
                                            Cancelled
                                        </Badge>
                                    ) : isNhifSentNotIssued ? (
                                        <Badge variant="outline" className="gap-1 border-amber-500 bg-amber-500/10 text-amber-700 dark:text-amber-400 font-medium whitespace-nowrap">
                                            <AlertCircle className="h-3 w-3" />
                                            Request sent but not issued
                                        </Badge>
                                    ) : isNhifPending ? (
                                        <Badge variant="outline" className="gap-1 border-amber-500 bg-amber-500/10 text-amber-700 dark:text-amber-400 font-medium whitespace-nowrap">
                                            <AlertCircle className="h-3 w-3" />
                                            Pre-approval Required
                                        </Badge>
                                    ) : paymentRequired ? (
                                        <Badge variant="destructive" className="gap-1 bg-amber-600 dark:bg-amber-700">
                                            <AlertCircle className="h-3 w-3" />
                                            Unpaid
                                        </Badge>
                                    ) : (
                                        <Badge variant="outline" className="text-green-700 border-green-500/50 dark:text-green-400">
                                            Cleared
                                        </Badge>
                                    )}
                                </TableCell>
                                <TableCell>
                                    <Button asChild size="sm" variant={isBlocked || isCancelled ? 'outline' : 'default'}>
                                        <Link href={route('laboratory.collection.show', order.id)}>
                                            {isBlocked || isCancelled ? 'View' : 'Collect'}
                                        </Link>
                                    </Button>
                                </TableCell>
                            </TableRow>
                            );
                        })}

                        {orders.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={9} className="p-0">
                                    <EmptyState
                                        icon={TestTubes}
                                        title={hasFilters ? 'No orders match your search or filters' : 'Nothing awaiting collection'}
                                        description={
                                            hasFilters
                                                ? 'Try a different search term or clear your filters.'
                                                : 'Orders appear here once a doctor places a laboratory order that still needs a specimen.'
                                        }
                                    />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>

                <Pagination links={orders.links} />
            </div>
        </AppLayout>
    );
}
