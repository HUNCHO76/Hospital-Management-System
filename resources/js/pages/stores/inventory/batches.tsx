import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, Edit3, Filter, Layers, Search, Sliders } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Store & Inventory', href: '/stores' },
    { title: 'Stock Ledger', href: '/stores/inventory' },
    { title: 'Batch Register', href: '/stores/inventory/batches' },
];

interface StockBatchRow {
    id: number;
    batch_number: string;
    quantity: number;
    unit_cost: number | null;
    expiry_date: string;
    status: string;
    store?: { id: number; name: string };
    vendor?: { id: number; name: string };
    stock_item?: {
        name: string;
        procurement_item?: { name: string; code: string; unit_of_measure: string };
        medication?: { generic_name: string; brand_name: string };
    };
}

interface Props {
    batches: {
        data: StockBatchRow[];
        links: any[];
        total: number;
    };
    stores: { id: number; name: string }[];
    filters: {
        store_id: number | null;
        status: string;
        search: string;
    };
}

export default function StoreBatchesIndex({ batches, stores, filters }: Props) {
    const { can } = usePermissions();
    const [search, setSearch] = useState(filters.search || '');
    const [storeId, setStoreId] = useState(filters.store_id ? String(filters.store_id) : '');
    const [status, setStatus] = useState(filters.status || '');

    const [selectedBatchForAdjustment, setSelectedBatchForAdjustment] = useState<StockBatchRow | null>(null);

    const { data: adjData, setData: setAdjData, post: postAdj, processing: adjProcessing } = useForm({
        batch_id: 0,
        adjustment_type: 'ADD',
        quantity: 1,
        reason: '',
    });

    const handleFilter = () => {
        router.get('/stores/inventory/batches', {
            search: search || undefined,
            store_id: storeId || undefined,
            status: status || undefined,
        }, { preserveState: true });
    };

    const openAdjustModal = (batch: StockBatchRow) => {
        setSelectedBatchForAdjustment(batch);
        setAdjData({
            batch_id: batch.id,
            adjustment_type: 'ADD',
            quantity: 1,
            reason: '',
        });
    };

    const handleAdjustSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        postAdj('/stores/inventory/adjust', {
            onSuccess: () => setSelectedBatchForAdjustment(null),
        });
    };

    const getStatusBadge = (st: string) => {
        switch (st) {
            case 'active':
                return <Badge variant="secondary" className="bg-green-100 text-green-800">Active</Badge>;
            case 'expired':
                return <Badge variant="destructive">Expired</Badge>;
            case 'damaged':
                return <Badge variant="destructive">Damaged</Badge>;
            default:
                return <Badge variant="outline">{st}</Badge>;
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Inventory Batch Register - Store" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <PageHeader
                        title="Stock Batch Register"
                        description="FEFO tracking per batch number, expiration dates, unit valuations, and physical stock count adjustments."
                    />
                    <Link href="/stores/inventory">
                        <Button variant="outline" size="sm">
                            <ArrowLeft className="mr-2 h-4 w-4" /> Back to Item Balances
                        </Button>
                    </Link>
                </div>

                <Card>
                    <CardContent className="p-4 flex flex-col md:flex-row gap-3">
                        <div className="relative flex-1">
                            <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
                            <Input
                                placeholder="Search by batch number or item name..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && handleFilter()}
                                className="pl-9"
                            />
                        </div>

                        <select
                            value={storeId}
                            onChange={(e) => setStoreId(e.target.value)}
                            className="rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option value="">All Stores</option>
                            {stores.map((s) => (
                                <option key={s.id} value={s.id}>{s.name}</option>
                            ))}
                        </select>

                        <select
                            value={status}
                            onChange={(e) => setStatus(e.target.value)}
                            className="rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option value="">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="expired">Expired</option>
                            <option value="damaged">Damaged</option>
                        </select>

                        <Button onClick={handleFilter} variant="secondary">
                            <Filter className="mr-2 h-4 w-4" /> Filter
                        </Button>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3">Batch / Lot #</th>
                                        <th className="px-4 py-3">Item Description</th>
                                        <th className="px-4 py-3">Store</th>
                                        <th className="px-4 py-3">Quantity</th>
                                        <th className="px-4 py-3">Unit Cost</th>
                                        <th className="px-4 py-3">Expiry Date</th>
                                        <th className="px-4 py-3">Supplier</th>
                                        <th className="px-4 py-3">Status</th>
                                        <th className="px-4 py-3 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {batches.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={9} className="py-8 text-center text-muted-foreground">
                                                No stock batches found.
                                            </td>
                                        </tr>
                                    ) : (
                                        batches.data.map((b) => {
                                            const itemName = b.stock_item?.procurement_item?.name || b.stock_item?.medication?.generic_name || b.stock_item?.name || 'Item';
                                            const uom = b.stock_item?.procurement_item?.unit_of_measure || 'units';

                                            return (
                                                <tr key={b.id} className="hover:bg-muted/50 transition">
                                                    <td className="px-4 py-3 font-mono font-medium text-primary">{b.batch_number}</td>
                                                    <td className="px-4 py-3 font-semibold">{itemName}</td>
                                                    <td className="px-4 py-3 text-muted-foreground">{b.store?.name}</td>
                                                    <td className="px-4 py-3 font-bold text-emerald-700">
                                                        {Number(b.quantity).toLocaleString()} {uom}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        {b.unit_cost ? `TZS ${Number(b.unit_cost).toLocaleString()}` : '—'}
                                                    </td>
                                                    <td className="px-4 py-3 text-muted-foreground">
                                                        {b.expiry_date ? b.expiry_date.split('T')[0] : '—'}
                                                    </td>
                                                    <td className="px-4 py-3 text-muted-foreground">{b.vendor?.name || 'Hospital Store'}</td>
                                                    <td className="px-4 py-3">{getStatusBadge(b.status)}</td>
                                                    <td className="px-4 py-3 text-right">
                                                        {can('stores-inventory.adjust') && (
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() => openAdjustModal(b)}
                                                            >
                                                                <Sliders className="mr-1 h-3.5 w-3.5" /> Adjust
                                                            </Button>
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {/* Adjust Modal */}
                <Dialog open={!!selectedBatchForAdjustment} onOpenChange={() => setSelectedBatchForAdjustment(null)}>
                    <DialogContent>
                        <form onSubmit={handleAdjustSubmit}>
                            <DialogHeader>
                                <DialogTitle>Adjust Physical Batch Stock</DialogTitle>
                                <DialogDescription>
                                    Record stock reconciliation adjustments, damage write-offs, or expiration disposals with full audit logging.
                                </DialogDescription>
                            </DialogHeader>

                            {selectedBatchForAdjustment && (
                                <div className="py-4 space-y-3">
                                    <div className="p-3 bg-muted/30 rounded text-xs space-y-1">
                                        <p><span className="font-semibold">Batch:</span> {selectedBatchForAdjustment.batch_number}</p>
                                        <p><span className="font-semibold">Current Count:</span> {selectedBatchForAdjustment.quantity}</p>
                                    </div>

                                    <div className="space-y-1">
                                        <Label htmlFor="adjustment_type">Adjustment Reason / Type *</Label>
                                        <select
                                            id="adjustment_type"
                                            value={adjData.adjustment_type}
                                            onChange={(e) => setAdjData('adjustment_type', e.target.value)}
                                            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                            required
                                        >
                                            <option value="ADD">Physical Count Surplus (+ Add)</option>
                                            <option value="SUBTRACT">Physical Count Deficit (- Deduct)</option>
                                            <option value="DAMAGE_WRITEOFF">Damaged Goods Write-off</option>
                                            <option value="EXPIRED_WRITEOFF">Expired Stock Write-off</option>
                                        </select>
                                    </div>

                                    <div className="space-y-1">
                                        <Label htmlFor="adj_qty">Adjustment Quantity *</Label>
                                        <Input
                                            id="adj_qty"
                                            type="number"
                                            min="1"
                                            required
                                            value={adjData.quantity}
                                            onChange={(e) => setAdjData('quantity', parseInt(e.target.value) || 1)}
                                        />
                                    </div>

                                    <div className="space-y-1">
                                        <Label htmlFor="adj_reason">Audit Justification / Variance Note *</Label>
                                        <Textarea
                                            id="adj_reason"
                                            required
                                            rows={2}
                                            placeholder="Explain reason for discrepancy (e.g. physical inventory count verification)..."
                                            value={adjData.reason}
                                            onChange={(e) => setAdjData('reason', e.target.value)}
                                        />
                                    </div>
                                </div>
                            )}

                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setSelectedBatchForAdjustment(null)}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={adjProcessing || !adjData.reason}>
                                    {adjProcessing ? 'Adjusting...' : 'Save Stock Adjustment'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
