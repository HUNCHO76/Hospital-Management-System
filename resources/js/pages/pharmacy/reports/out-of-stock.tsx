import { ReportExportButton } from '@/components/pharmacy/report-export-button';
import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type StockItem } from '@/types/stock';
import { Head, Link, router } from '@inertiajs/react';
import { PackageX } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pharmacy', href: '/pharmacy' },
    { title: 'Reports', href: '/pharmacy/reports' },
    { title: 'Out of Stock', href: '/pharmacy/reports/out-of-stock' },
];

interface OutOfStockReportProps {
    items: Paginated<StockItem>;
    filters: { search?: string };
}

/**
 * Medications with zero dispensable stock (Phase 6, Step 50) — reuses
 * StockSearchService::itemsQuery(['status' => 'out_of_stock']).
 */
export default function OutOfStockReport({ items, filters }: OutOfStockReportProps) {
    const applyFilters = (search: string) => {
        router.get(route('pharmacy-reports.out-of-stock'), { search }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Out of Stock Report" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Out of Stock Report</h1>
                        <p className="text-muted-foreground text-sm">Medications with no dispensable stock remaining.</p>
                    </div>
                    <ReportExportButton routeName="pharmacy-reports.out-of-stock" filters={filters} />
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
                            <TableHead>Total Quantity</TableHead>
                            <TableHead>Reorder Level</TableHead>
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
                                <TableCell>{item.total_quantity}</TableCell>
                                <TableCell>{item.reorder_level}</TableCell>
                            </TableRow>
                        ))}

                        {items.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={3} className="p-0">
                                    <EmptyState icon={PackageX} title="Nothing is out of stock" description="Every medication has dispensable stock available." />
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
