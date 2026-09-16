import { ReportExportButton } from '@/components/pharmacy/report-export-button';
import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type StockItem } from '@/types/stock';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pharmacy', href: '/pharmacy' },
    { title: 'Reports', href: '/pharmacy/reports' },
    { title: 'Low Stock', href: '/pharmacy/reports/low-stock' },
];

interface LowStockReportProps {
    items: Paginated<StockItem>;
    filters: { search?: string };
}

/**
 * Medications at or below their reorder level (Phase 6, Step 50, spec §8) —
 * reuses StockSearchService::itemsQuery(['status' => 'low_stock']), the
 * exact classification the stock dashboard/list already use.
 */
export default function LowStockReport({ items, filters }: LowStockReportProps) {
    const applyFilters = (search: string) => {
        router.get(route('pharmacy-reports.low-stock'), { search }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Low Stock Report" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Low Stock Report</h1>
                        <p className="text-muted-foreground text-sm">Medications at or below their configured reorder level.</p>
                    </div>
                    <ReportExportButton routeName="pharmacy-reports.low-stock" filters={filters} />
                </div>

                <Input
                    value={filters.search || ''}
                    onChange={(e) => applyFilters(e.target.value)}
                    placeholder="Search by medication"
                    className="max-w-sm"
                />

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Medication</TableHead>
                            <TableHead>Available</TableHead>
                            <TableHead>Reorder Level</TableHead>
                            <TableHead>Shortage</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {items.data.map((item) => (
                            <TableRow key={item.id}>
                                <TableCell>
                                    <Link href={route('pharmacy-stock.items.show', item.id)} className="hover:underline">
                                        {item.medication?.generic_name ?? '—'}
                                    </Link>
                                    <p className="text-muted-foreground text-xs">{item.medication?.code}</p>
                                </TableCell>
                                <TableCell>{item.available_quantity}</TableCell>
                                <TableCell>{item.reorder_level}</TableCell>
                                <TableCell className="text-destructive font-medium">
                                    {Math.max(0, item.reorder_level - item.available_quantity)}
                                </TableCell>
                            </TableRow>
                        ))}

                        {items.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={4} className="p-0">
                                    <EmptyState icon={AlertTriangle} title="Nothing is low on stock" description="Every medication is above its reorder level." />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>

                <Pagination links={items.links} />
            </div>
        </AppLayout>
    );
}
