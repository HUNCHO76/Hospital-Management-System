import { ReportExportButton } from '@/components/pharmacy/report-export-button';
import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type StockItem } from '@/types/stock';
import { Head, Link, router } from '@inertiajs/react';
import { CalendarClock } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pharmacy', href: '/pharmacy' },
    { title: 'Reports', href: '/pharmacy/reports' },
    { title: 'Expiry', href: '/pharmacy/reports/expiry' },
];

interface ExpiryReportProps {
    items: Paginated<StockItem>;
    filters: { search?: string };
    view: 'expired' | 'expiring_soon';
}

/**
 * Expired and expiring-soon batches (Phase 6, Step 50, spec §9) — two tabs
 * over the same StockSearchService::itemsQuery() classification the stock
 * list already uses; the expiring-soon window is the configurable
 * `pharmacy.stock_expiry_warning_days` setting, never a hard-coded number.
 */
export default function ExpiryReport({ items, filters, view }: ExpiryReportProps) {
    const applyFilters = (search: string) => {
        router.get(route('pharmacy-reports.expiry'), { search, view }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Expiry Report" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Expiry Report</h1>
                        <p className="text-muted-foreground text-sm">Expired stock is never dispensable, regardless of what the UI would otherwise show.</p>
                    </div>
                    <ReportExportButton routeName="pharmacy-reports.expiry" filters={{ ...filters, view }} />
                </div>

                <div className="flex gap-2">
                    <Button
                        size="sm"
                        variant={view === 'expiring_soon' ? 'default' : 'outline'}
                        onClick={() => router.get(route('pharmacy-reports.expiry'), { view: 'expiring_soon' })}
                    >
                        Expiring Soon
                    </Button>
                    <Button
                        size="sm"
                        variant={view === 'expired' ? 'default' : 'outline'}
                        onClick={() => router.get(route('pharmacy-reports.expiry'), { view: 'expired' })}
                    >
                        Expired
                    </Button>
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
                            <TableHead>Nearest Expiry</TableHead>
                            <TableHead>Available Quantity</TableHead>
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
                                <TableCell className={cn(view === 'expired' && 'text-destructive')}>{item.nearest_expiry_date ?? '—'}</TableCell>
                                <TableCell>{item.available_quantity}</TableCell>
                            </TableRow>
                        ))}

                        {items.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={3} className="p-0">
                                    <EmptyState
                                        icon={CalendarClock}
                                        title={view === 'expired' ? 'Nothing has expired' : 'Nothing is expiring soon'}
                                        description="No batches match this view right now."
                                    />
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
