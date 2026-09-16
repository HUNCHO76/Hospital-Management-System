import { ReportExportButton } from '@/components/pharmacy/report-export-button';
import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { stockTransactionTypeLabel } from '@/lib/stock-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type StockMovementReportFilters } from '@/types/pharmacy-report';
import { type StockTransaction, type StockTransactionType } from '@/types/stock';
import { Head, router } from '@inertiajs/react';
import { Boxes } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pharmacy', href: '/pharmacy' },
    { title: 'Reports', href: '/pharmacy/reports' },
    { title: 'Stock Movements', href: '/pharmacy/reports/stock-movements' },
];

interface StockMovementsReportProps {
    transactions: Paginated<StockTransaction>;
    filters: StockMovementReportFilters;
    transactionTypes: StockTransactionType[];
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

/**
 * The full stock movement ledger (Phase 6, Step 50, spec §10) — the same
 * immutable StockTransaction rows the stock item detail page shows, here
 * searchable/filterable across every medication and batch.
 */
export default function StockMovementsReport({ transactions, filters, transactionTypes }: StockMovementsReportProps) {
    const applyFilters = (overrides: Partial<StockMovementReportFilters>) => {
        const params: Record<string, string | undefined> = { ...filters, ...overrides };
        router.get(route('pharmacy-reports.stock-movements'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(filters.search || filters.transaction_type || filters.date_from || filters.date_to);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stock Movement Report" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Stock Movement Report</h1>
                        <p className="text-muted-foreground text-sm">The complete, append-only stock transaction ledger.</p>
                    </div>
                    <ReportExportButton routeName="pharmacy-reports.stock-movements" filters={filters} />
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        value={filters.search || ''}
                        onChange={(e) => applyFilters({ search: e.target.value })}
                        placeholder="Search by transaction #, batch, or medication"
                        className="max-w-sm"
                    />
                    <Select
                        value={filters.transaction_type || 'all'}
                        onValueChange={(value) => applyFilters({ transaction_type: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-[180px]">
                            <SelectValue placeholder="All types" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All types</SelectItem>
                            {transactionTypes.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {stockTransactionTypeLabel[type]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Input type="date" value={filters.date_from || ''} onChange={(e) => applyFilters({ date_from: e.target.value })} className="w-[160px]" />
                    <Input type="date" value={filters.date_to || ''} onChange={(e) => applyFilters({ date_to: e.target.value })} className="w-[160px]" />

                    {hasFilters && (
                        <Button variant="ghost" size="sm" onClick={() => router.get(route('pharmacy-reports.stock-movements'))}>
                            Clear filters
                        </Button>
                    )}
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Transaction #</TableHead>
                            <TableHead>When</TableHead>
                            <TableHead>Medication</TableHead>
                            <TableHead>Type</TableHead>
                            <TableHead>Quantity</TableHead>
                            <TableHead>Before → After</TableHead>
                            <TableHead>Batch</TableHead>
                            <TableHead>Performed By</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {transactions.data.map((transaction) => (
                            <TableRow key={transaction.id}>
                                <TableCell>{transaction.transaction_number}</TableCell>
                                <TableCell>{formatDateTime(transaction.occurred_at)}</TableCell>
                                <TableCell>{transaction.medication_name ?? '—'}</TableCell>
                                <TableCell>
                                    <Badge variant="outline">{stockTransactionTypeLabel[transaction.transaction_type]}</Badge>
                                </TableCell>
                                <TableCell>{transaction.quantity}</TableCell>
                                <TableCell>
                                    {transaction.balance_before} → {transaction.balance_after}
                                </TableCell>
                                <TableCell>{transaction.stock_batch?.batch_number ?? '—'}</TableCell>
                                <TableCell>{transaction.performed_by?.name ?? '—'}</TableCell>
                            </TableRow>
                        ))}

                        {transactions.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={8} className="p-0">
                                    <EmptyState icon={Boxes} title="No movements match your filters" description="Try a different search term or clear your filters." />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>

                <Pagination links={transactions.links} />
            </div>
        </AppLayout>
    );
}
