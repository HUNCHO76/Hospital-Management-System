import { ReportExportButton } from '@/components/pharmacy/report-export-button';
import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { stockBatchStatusLabel, stockBatchStatusVariant } from '@/lib/stock-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type StockBatchReportFilters, type StockBatchReportRow } from '@/types/pharmacy-report';
import { type StockBatchStatus } from '@/types/stock';
import { Head, Link, router } from '@inertiajs/react';
import { Boxes } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pharmacy', href: '/pharmacy' },
    { title: 'Reports', href: '/pharmacy/reports' },
    { title: 'Stock Batches', href: '/pharmacy/reports/stock-batches' },
];

interface StockBatchesReportProps {
    batches: Paginated<StockBatchReportRow>;
    filters: StockBatchReportFilters;
    statuses: StockBatchStatus[];
}

export default function StockBatchesReport({ batches, filters, statuses }: StockBatchesReportProps) {
    const applyFilters = (overrides: Partial<StockBatchReportFilters>) => {
        const params: Record<string, string | undefined> = { ...filters, ...overrides };
        router.get(route('pharmacy-reports.stock-batches'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(filters.search || filters.status || filters.expiry_from || filters.expiry_to);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stock Batch Report" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Stock Batch Report</h1>
                        <p className="text-muted-foreground text-sm">Every batch across every medication.</p>
                    </div>
                    <ReportExportButton routeName="pharmacy-reports.stock-batches" filters={filters} />
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        value={filters.search || ''}
                        onChange={(e) => applyFilters({ search: e.target.value })}
                        placeholder="Search by medication or batch number"
                        className="max-w-sm"
                    />
                    <Select value={filters.status || 'all'} onValueChange={(value) => applyFilters({ status: value === 'all' ? '' : value })}>
                        <SelectTrigger className="w-[160px]">
                            <SelectValue placeholder="All statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            <SelectItem value="expiring_soon">Expiring Soon</SelectItem>
                            <SelectItem value="expired">Expired</SelectItem>
                            {statuses.map((status) => (
                                <SelectItem key={status} value={status}>
                                    {stockBatchStatusLabel[status]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Input type="date" value={filters.expiry_from || ''} onChange={(e) => applyFilters({ expiry_from: e.target.value })} className="w-[160px]" />
                    <Input type="date" value={filters.expiry_to || ''} onChange={(e) => applyFilters({ expiry_to: e.target.value })} className="w-[160px]" />

                    {hasFilters && (
                        <Button variant="ghost" size="sm" onClick={() => router.get(route('pharmacy-reports.stock-batches'))}>
                            Clear filters
                        </Button>
                    )}
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Medication</TableHead>
                            <TableHead>Batch</TableHead>
                            <TableHead>Expiry</TableHead>
                            <TableHead>Quantity</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Hospital</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {batches.data.map((batch) => (
                            <TableRow key={batch.id}>
                                <TableCell>
                                    <Link href={route('pharmacy-stock.items.show', batch.stock_item_id)} className="hover:underline">
                                        {batch.medication?.generic_name ?? '—'}
                                    </Link>
                                    <p className="text-muted-foreground text-xs">{batch.medication?.code}</p>
                                </TableCell>
                                <TableCell>{batch.batch_number}</TableCell>
                                <TableCell>
                                    {batch.expiry_date}
                                    {batch.is_expired && <span className="text-destructive ml-2 text-xs">Expired</span>}
                                    {!batch.is_expired && batch.is_expiring_soon && <span className="ml-2 text-xs text-amber-600">Expiring soon</span>}
                                </TableCell>
                                <TableCell>{batch.quantity}</TableCell>
                                <TableCell>
                                    <Badge variant={stockBatchStatusVariant[batch.status]}>{stockBatchStatusLabel[batch.status]}</Badge>
                                </TableCell>
                                <TableCell>{batch.hospital?.name ?? '—'}</TableCell>
                            </TableRow>
                        ))}

                        {batches.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={6} className="p-0">
                                    <EmptyState icon={Boxes} title="No batches match your filters" description="Try a different search term or clear your filters." />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>

                <Pagination links={batches.links} />
            </div>
        </AppLayout>
    );
}
