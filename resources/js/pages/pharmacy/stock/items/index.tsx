import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type StockFilters, type StockItem } from '@/types/stock';
import { Head, Link, router } from '@inertiajs/react';
import { Boxes } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pharmacy', href: '/pharmacy' },
    { title: 'Stock', href: '/pharmacy/stock' },
    { title: 'Stock Items', href: '/pharmacy/stock/items' },
];

interface PharmacyStockItemsIndexProps {
    items: Paginated<StockItem>;
    filters: StockFilters;
}

const statusTabs: { value: string; label: string }[] = [
    { value: '', label: 'All' },
    { value: 'low_stock', label: 'Low Stock' },
    { value: 'out_of_stock', label: 'Out of Stock' },
    { value: 'expiring_soon', label: 'Expiring Soon' },
    { value: 'expired', label: 'Expired' },
    { value: 'quarantined', label: 'Quarantined' },
];

function medicationLabel(item: StockItem): string {
    if (!item.medication) {
        return '—';
    }

    return item.medication.brand_name ? `${item.medication.generic_name} (${item.medication.brand_name})` : item.medication.generic_name;
}

/**
 * The searchable pharmacy stock list (Phase 6, Step 49, spec §23) — every
 * filter (search + status tab) is applied server-side by
 * StockSearchService::itemsQuery(), never client-side, so this page never
 * loads more than one page of rows regardless of catalog size.
 */
export default function PharmacyStockItemsIndex({ items, filters }: PharmacyStockItemsIndexProps) {
    const applyFilters = (overrides: Partial<StockFilters>) => {
        const params: Record<string, string> = { ...filters, ...overrides };
        router.get(route('pharmacy-stock.items.index'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(filters.search || filters.status);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pharmacy Stock Items" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Stock Items</h1>
                        <p className="text-muted-foreground text-sm">Every medication currently tracked in pharmacy inventory.</p>
                    </div>
                    <Button asChild>
                        <Link href={route('pharmacy-stock.receive.create')}>Receive Stock</Link>
                    </Button>
                </div>

                <div className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        {statusTabs.map((tab) => (
                            <Button
                                key={tab.value}
                                type="button"
                                size="sm"
                                variant={filters.status === tab.value || (!filters.status && tab.value === '') ? 'default' : 'outline'}
                                onClick={() => applyFilters({ status: tab.value })}
                            >
                                {tab.label}
                            </Button>
                        ))}
                    </div>

                    <Input
                        value={filters.search || ''}
                        onChange={(e) => applyFilters({ search: e.target.value })}
                        placeholder="Search by medication name or code"
                        aria-label="Search stock items"
                        className="max-w-sm"
                    />

                    {hasFilters && (
                        <Button variant="ghost" size="sm" className="w-fit" onClick={() => router.get(route('pharmacy-stock.items.index'))}>
                            Clear filters
                        </Button>
                    )}
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Medication</TableHead>
                            <TableHead>Category</TableHead>
                            <TableHead className="text-right">Available</TableHead>
                            <TableHead className="text-right">Reorder Level</TableHead>
                            <TableHead>Nearest Expiry</TableHead>
                            <TableHead className="text-right">Action</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {items.data.map((item) => (
                            <TableRow key={item.id}>
                                <TableCell>
                                    <div className="flex flex-col">
                                        <span>{medicationLabel(item)}</span>
                                        {item.medication && <span className="text-muted-foreground text-xs">{item.medication.code}</span>}
                                    </div>
                                </TableCell>
                                <TableCell>{item.medication?.category ?? '—'}</TableCell>
                                <TableCell className="text-right">
                                    {item.available_quantity}
                                    {item.is_low_stock && (
                                        <Badge variant="secondary" className="ml-2">
                                            Low
                                        </Badge>
                                    )}
                                </TableCell>
                                <TableCell className="text-right">{item.reorder_level}</TableCell>
                                <TableCell>{item.nearest_expiry_date ?? '—'}</TableCell>
                                <TableCell className="text-right">
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={route('pharmacy-stock.items.show', item.id)}>View</Link>
                                    </Button>
                                </TableCell>
                            </TableRow>
                        ))}

                        {items.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={6} className="p-0">
                                    <EmptyState
                                        icon={Boxes}
                                        title={hasFilters ? 'No stock items match your search or filters' : 'No stock items yet'}
                                        description={
                                            hasFilters
                                                ? 'Try a different search term or clear your filters.'
                                                : 'Received medication will appear here once stock has been recorded.'
                                        }
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
