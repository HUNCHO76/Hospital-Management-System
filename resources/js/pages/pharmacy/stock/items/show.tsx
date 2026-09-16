import { QuarantineBatchDialog } from '@/components/pharmacy/quarantine-batch-dialog';
import { ReleaseBatchButton } from '@/components/pharmacy/release-batch-button';
import { StockAdjustmentDialog } from '@/components/pharmacy/stock-adjustment-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { stockBatchStatusLabel, stockBatchStatusVariant, stockTransactionTypeLabel } from '@/lib/stock-status';
import { type BreadcrumbItem } from '@/types';
import { type StockItem, type StockTransaction } from '@/types/stock';
import { Head } from '@inertiajs/react';

interface PharmacyStockItemShowProps {
    item: StockItem;
    transactions: StockTransaction[];
    canAdjust: boolean;
    canQuarantine: boolean;
    canRelease: boolean;
    canViewTransactions: boolean;
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
 * A single stock item's detail page (Phase 6, Step 49, spec §25) — batches
 * table (with adjust/quarantine/release actions per row) and the transaction
 * ledger, giving full traceability from current balance back through every
 * receipt/adjustment/dispense that produced it.
 */
export default function PharmacyStockItemShow({
    item,
    transactions,
    canAdjust,
    canQuarantine,
    canRelease,
    canViewTransactions,
}: PharmacyStockItemShowProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Pharmacy', href: '/pharmacy' },
        { title: 'Stock', href: '/pharmacy/stock' },
        { title: 'Stock Items', href: '/pharmacy/stock/items' },
        { title: medicationLabel(item), href: `/pharmacy/stock/items/${item.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={medicationLabel(item)} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{medicationLabel(item)}</h1>
                        <p className="text-muted-foreground text-sm">
                            {item.medication?.code} · {item.hospital?.name ?? '—'}
                        </p>
                    </div>
                    {item.is_low_stock && <Badge variant="secondary">Low Stock</Badge>}
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-sm font-medium">Available Quantity</p>
                            <p className="text-2xl font-semibold tracking-tight">{item.available_quantity}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-sm font-medium">Total Quantity</p>
                            <p className="text-2xl font-semibold tracking-tight">{item.total_quantity}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-sm font-medium">Reorder Level</p>
                            <p className="text-2xl font-semibold tracking-tight">{item.reorder_level}</p>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Batches</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Batch</TableHead>
                                    <TableHead>Expiry</TableHead>
                                    <TableHead className="text-right">Quantity</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Supplier</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {(item.batches ?? []).map((batch) => (
                                    <TableRow key={batch.id}>
                                        <TableCell>{batch.batch_number}</TableCell>
                                        <TableCell>
                                            {batch.expiry_date}
                                            {batch.is_expired && (
                                                <Badge variant="destructive" className="ml-2">
                                                    Expired
                                                </Badge>
                                            )}
                                            {!batch.is_expired && batch.is_expiring_soon && (
                                                <Badge variant="secondary" className="ml-2">
                                                    Expiring Soon
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">{batch.quantity}</TableCell>
                                        <TableCell>
                                            <Badge variant={stockBatchStatusVariant[batch.status]}>{stockBatchStatusLabel[batch.status]}</Badge>
                                        </TableCell>
                                        <TableCell>{batch.supplier_name ?? '—'}</TableCell>
                                        <TableCell className="text-right">
                                            <div className="flex justify-end gap-2">
                                                {canAdjust && batch.status !== 'expired' && batch.status !== 'damaged' && (
                                                    <StockAdjustmentDialog
                                                        stockBatchId={batch.id}
                                                        currentQuantity={batch.quantity}
                                                        trigger={
                                                            <Button size="sm" variant="outline">
                                                                Adjust
                                                            </Button>
                                                        }
                                                    />
                                                )}
                                                {canQuarantine && batch.status === 'active' && (
                                                    <QuarantineBatchDialog
                                                        stockBatchId={batch.id}
                                                        trigger={
                                                            <Button size="sm" variant="outline">
                                                                Quarantine
                                                            </Button>
                                                        }
                                                    />
                                                )}
                                                {canRelease && batch.status === 'quarantined' && <ReleaseBatchButton stockBatchId={batch.id} />}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}

                                {(item.batches ?? []).length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-muted-foreground py-6 text-center text-sm">
                                            No batches received yet.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {canViewTransactions && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Recent Transactions</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Batch</TableHead>
                                        <TableHead className="text-right">Quantity</TableHead>
                                        <TableHead className="text-right">Before</TableHead>
                                        <TableHead className="text-right">After</TableHead>
                                        <TableHead>Reference</TableHead>
                                        <TableHead>Actor</TableHead>
                                        <TableHead>When</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {transactions.map((transaction) => (
                                        <TableRow key={transaction.id}>
                                            <TableCell>
                                                <Badge variant="outline">{stockTransactionTypeLabel[transaction.transaction_type]}</Badge>
                                            </TableCell>
                                            <TableCell>{transaction.stock_batch?.batch_number ?? '—'}</TableCell>
                                            <TableCell className="text-right">{transaction.quantity}</TableCell>
                                            <TableCell className="text-right">{transaction.balance_before}</TableCell>
                                            <TableCell className="text-right">{transaction.balance_after}</TableCell>
                                            <TableCell className="text-muted-foreground text-xs">
                                                {transaction.reference_type
                                                    ? `${transaction.reference_type} #${transaction.reference_id}`
                                                    : transaction.transaction_number}
                                            </TableCell>
                                            <TableCell>{transaction.performed_by?.name ?? '—'}</TableCell>
                                            <TableCell>{formatDateTime(transaction.occurred_at)}</TableCell>
                                        </TableRow>
                                    ))}

                                    {transactions.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={8} className="text-muted-foreground py-6 text-center text-sm">
                                                No stock movements recorded yet.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
