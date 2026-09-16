import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/billing-status';
import { adjustmentDirectionVariant, adjustmentTypeLabel } from '@/lib/correction-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type AdjustmentDirection, type AdjustmentType, type BillingAdjustment } from '@/types/correction';
import { Head, Link, router } from '@inertiajs/react';
import { ListChecks } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing', href: '/billing' },
    { title: 'Adjustments', href: '/billing/adjustments' },
];

interface AdjustmentFilters {
    search: string;
    type: string;
    direction: string;
}

interface BillingAdjustmentsIndexProps {
    adjustments: Paginated<BillingAdjustment>;
    filters: AdjustmentFilters;
    types: AdjustmentType[];
    directions: AdjustmentDirection[];
}

export default function BillingAdjustmentsIndex({ adjustments, filters, types, directions }: BillingAdjustmentsIndexProps) {
    const applyFilters = (overrides: Partial<AdjustmentFilters>) => {
        const params: Record<string, string> = { ...filters, ...overrides };
        router.get(route('billing.adjustments.index'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(filters.search || filters.type || filters.direction);

    const patientName = (adj: BillingAdjustment) => {
        if (!adj.patient) {
            return '—';
        }

        return [adj.patient.first_name, adj.patient.middle_name, adj.patient.last_name].filter(Boolean).join(' ');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Billing Adjustments" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Billing Adjustments</h1>
                        <p className="text-muted-foreground text-sm">Auditable credit and debit corrections posted against patient invoices.</p>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        placeholder="Search number, patient, reason…"
                        className="w-64"
                        value={filters.search ?? ''}
                        onChange={(e) => applyFilters({ search: e.target.value })}
                    />
                    <Select value={filters.type || 'all'} onValueChange={(v) => applyFilters({ type: v === 'all' ? '' : v })}>
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="All types" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All types</SelectItem>
                            {types.map((t) => (
                                <SelectItem key={t} value={t}>
                                    {adjustmentTypeLabel[t]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select value={filters.direction || 'all'} onValueChange={(v) => applyFilters({ direction: v === 'all' ? '' : v })}>
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="All directions" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All directions</SelectItem>
                            {directions.map((d) => (
                                <SelectItem key={d} value={d}>
                                    {d === 'credit' ? 'Credit' : 'Debit'}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {hasFilters && (
                        <Button variant="ghost" size="sm" onClick={() => applyFilters({ search: '', type: '', direction: '' })}>
                            Clear
                        </Button>
                    )}
                </div>

                {adjustments.data.length === 0 ? (
                    <EmptyState
                        icon={ListChecks}
                        title="No adjustments found"
                        description={hasFilters ? 'No adjustments match your current filters.' : 'No billing adjustments have been posted yet.'}
                    />
                ) : (
                    <>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Number</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Direction</TableHead>
                                    <TableHead className="text-right">Amount</TableHead>
                                    <TableHead>Patient</TableHead>
                                    <TableHead>Invoice</TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead className="text-right">Action</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {adjustments.data.map((adj) => (
                                    <TableRow key={adj.id}>
                                        <TableCell className="font-mono">{adj.adjustment_number}</TableCell>
                                        <TableCell>{adjustmentTypeLabel[adj.type]}</TableCell>
                                        <TableCell>
                                            <Badge variant={adjustmentDirectionVariant[adj.direction]}>
                                                {adj.direction === 'credit' ? 'Credit' : 'Debit'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right">{formatMoney(adj.amount, adj.currency)}</TableCell>
                                        <TableCell>{patientName(adj)}</TableCell>
                                        <TableCell>
                                            {adj.invoice ? (
                                                <Link href={route('billing.invoices.show', adj.invoice.id)} className="hover:underline">
                                                    {adj.invoice.invoice_number}
                                                </Link>
                                            ) : (
                                                '—'
                                            )}
                                        </TableCell>
                                        <TableCell>{new Date(adj.adjusted_at).toLocaleDateString()}</TableCell>
                                        <TableCell className="text-right">
                                            <Button asChild size="sm" variant="ghost">
                                                <Link href={route('billing.adjustments.show', adj.id)}>View</Link>
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>

                        <Pagination paginator={adjustments} />
                    </>
                )}
            </div>
        </AppLayout>
    );
}
