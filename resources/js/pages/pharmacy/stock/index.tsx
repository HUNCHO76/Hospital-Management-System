import { StatCard } from '@/components/dashboard/stat-card';
import { EmptyState } from '@/components/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { stockTransactionTypeLabel } from '@/lib/stock-status';
import { type BreadcrumbItem } from '@/types';
import { type StockDashboardCounts, type StockItem, type StockTransaction } from '@/types/stock';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, Boxes, CalendarClock, PackageX, PillBottle, ShieldAlert } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pharmacy', href: '/pharmacy' },
    { title: 'Stock', href: '/pharmacy/stock' },
];

interface PharmacyStockIndexProps {
    counts: StockDashboardCounts;
    lowStockItems: StockItem[];
    expiringItems: StockItem[];
    recentTransactions: StockTransaction[];
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

function medicationLabel(item: StockItem): string {
    if (!item.medication) {
        return '—';
    }

    return item.medication.brand_name ? `${item.medication.generic_name} (${item.medication.brand_name})` : item.medication.generic_name;
}

/**
 * The pharmacy inventory dashboard (Phase 6, Step 49, spec §24) — real
 * counts computed by StockSearchService (never fabricated), plus low-stock
 * and expiring-soon previews and a unified recent-movements feed (covering
 * receipts, dispensing, and adjustments together — each row shows its own
 * type badge rather than three separate, near-empty queries).
 */
export default function PharmacyStockIndex({ counts, lowStockItems, expiringItems, recentTransactions }: PharmacyStockIndexProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pharmacy Stock" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Stock</h1>
                        <p className="text-muted-foreground text-sm">Pharmacy inventory across batches and locations.</p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button asChild variant="outline">
                            <Link href={route('pharmacy-stock.items.index')}>
                                <Boxes className="h-4 w-4" />
                                Stock Items
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href={route('pharmacy-stock.receive.create')}>
                                <PillBottle className="h-4 w-4" />
                                Receive Stock
                            </Link>
                        </Button>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Stock Items" value={counts.total_stock_items} icon={Boxes} />
                    <StatCard label="Low Stock" value={counts.low_stock} icon={AlertTriangle} />
                    <StatCard label="Out of Stock" value={counts.out_of_stock} icon={PackageX} />
                    <StatCard label="Expiring Soon" value={counts.expiring_soon} icon={CalendarClock} />
                    <StatCard label="Expired" value={counts.expired} icon={ShieldAlert} />
                    <StatCard label="Quarantined" value={counts.quarantined} icon={ShieldAlert} />
                    <StatCard label="Active Medications" value={counts.total_medications} icon={PillBottle} />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Low Stock</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {lowStockItems.length > 0 ? (
                                <ul className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                                    {lowStockItems.map((item) => (
                                        <li key={item.id} className="flex items-center justify-between gap-2 py-2 text-sm first:pt-0 last:pb-0">
                                            <Link href={route('pharmacy-stock.items.show', item.id)} className="hover:underline">
                                                {medicationLabel(item)}
                                            </Link>
                                            <span className="text-muted-foreground">
                                                {item.available_quantity} available · reorder at {item.reorder_level}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-muted-foreground text-sm">Nothing is below its reorder level.</p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Expiring Soon</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {expiringItems.length > 0 ? (
                                <ul className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                                    {expiringItems.map((item) => (
                                        <li key={item.id} className="flex items-center justify-between gap-2 py-2 text-sm first:pt-0 last:pb-0">
                                            <Link href={route('pharmacy-stock.items.show', item.id)} className="hover:underline">
                                                {medicationLabel(item)}
                                            </Link>
                                            <span className="text-muted-foreground">Nearest expiry {item.nearest_expiry_date ?? '—'}</span>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-muted-foreground text-sm">Nothing is expiring within the warning window.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Recent Stock Movements</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Quantity</TableHead>
                                    <TableHead>Batch</TableHead>
                                    <TableHead>Performed By</TableHead>
                                    <TableHead>When</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {recentTransactions.map((transaction) => (
                                    <TableRow key={transaction.id}>
                                        <TableCell>
                                            <Badge variant="outline">{stockTransactionTypeLabel[transaction.transaction_type]}</Badge>
                                        </TableCell>
                                        <TableCell>{transaction.quantity}</TableCell>
                                        <TableCell>{transaction.stock_batch?.batch_number ?? '—'}</TableCell>
                                        <TableCell>{transaction.performed_by?.name ?? '—'}</TableCell>
                                        <TableCell>{formatDateTime(transaction.occurred_at)}</TableCell>
                                    </TableRow>
                                ))}

                                {recentTransactions.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={5} className="p-0">
                                            <EmptyState
                                                icon={Boxes}
                                                title="No stock movements yet"
                                                description="Receipts, dispensing, and adjustments will appear here as they happen."
                                            />
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
