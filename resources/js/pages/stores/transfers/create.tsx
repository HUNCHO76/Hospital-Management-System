import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, ArrowRightLeft, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Store & Inventory', href: '/stores' },
    { title: 'Inter-Store Transfers', href: '/stores/transfers' },
    { title: 'New Transfer', href: '/stores/transfers/create' },
];

interface StockBatch {
    id: number;
    batch_number: string;
    quantity: number;
    expiry_date: string;
    stock_item?: {
        name: string;
        procurement_item_id?: number;
        procurement_item?: { id: number; name: string; code: string; unit_of_measure: string };
    };
}

interface Props {
    stores: { id: number; name: string }[];
    selected_from_store_id: number | null;
    available_batches: StockBatch[];
}

interface TransferLineItem {
    [key: string]: any;
    procurement_item_id: number;
    from_stock_batch_id: number;
    item_name: string;
    batch_label: string;
    quantity: number;
    quantity_transferred: number;
    max_available: number;
    notes: string;
    remarks: string;
}

interface TransferForm {
    [key: string]: any;
    from_store_id: string | number;
    to_store_id: string | number;
    transfer_date: string;
    reason: string;
    notes: string;
    items: TransferLineItem[];
}

export default function CreateStoreTransfer({ stores, selected_from_store_id, available_batches }: Props) {
    const fromId = selected_from_store_id || stores[0]?.id || '';
    const otherStore = stores.find((s) => s.id !== Number(fromId));

    const { data, setData, post, processing, errors } = useForm<TransferForm>({
        from_store_id: fromId,
        to_store_id: otherStore?.id || '',
        transfer_date: new Date().toISOString().split('T')[0],
        reason: '',
        notes: '',
        items: [] as TransferLineItem[],
    });

    const [selectedBatchId, setSelectedBatchId] = useState<string>('');

    const handleFromStoreChange = (newFromId: string) => {
        setData('from_store_id', Number(newFromId));
        setData('items', []);
        router.get('/stores/transfers/create', { from_store_id: newFromId }, { preserveState: true });
    };

    const addBatchLine = (batch: StockBatch) => {
        const item = batch.stock_item?.procurement_item;
        const itemId = item?.id || batch.stock_item?.procurement_item_id || 1;
        const itemName = item?.name || batch.stock_item?.name || 'Stock Item';

        setData('items', [
            ...data.items,
            {
                procurement_item_id: itemId,
                from_stock_batch_id: batch.id,
                item_name: itemName,
                batch_label: `${itemName} - Lot: ${batch.batch_number} (Exp: ${batch.expiry_date?.split('T')[0]})`,
                quantity: 1,
                quantity_transferred: 1,
                max_available: Number(batch.quantity) || 1,
                notes: '',
                remarks: '',
            },
        ]);
        setSelectedBatchId('');
    };

    const removeItemLine = (index: number) => {
        const next = [...data.items];
        next.splice(index, 1);
        setData('items', next);
    };

    const updateLine = (index: number, field: keyof TransferLineItem, value: any) => {
        const next = [...data.items];
        const updated = { ...next[index], [field]: value };
        if (field === 'quantity') {
            updated.quantity_transferred = value;
        } else if (field === 'quantity_transferred') {
            updated.quantity = value;
        } else if (field === 'notes') {
            updated.remarks = value;
        } else if (field === 'remarks') {
            updated.notes = value;
        }
        next[index] = updated;
        setData('items', next);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        // Ensure both aliases and fields are properly synced
        const payload = {
            ...data,
            notes: data.notes || data.reason,
            reason: data.reason || data.notes,
            items: data.items.map((it) => ({
                ...it,
                quantity_transferred: it.quantity_transferred || it.quantity,
                quantity: it.quantity || it.quantity_transferred,
                remarks: it.remarks || it.notes,
                notes: it.notes || it.remarks,
            })),
        };
        router.post('/stores/transfers', payload);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New Inter-Store Transfer - Store" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <PageHeader
                        title="New Inter-Store Transfer"
                        description="Move verified inventory batches between storage facilities with separation of dispatch and arrival confirmation."
                    />
                    <Link href="/stores/transfers">
                        <Button variant="outline" size="sm">
                            <ArrowLeft className="mr-2 h-4 w-4" /> Cancel & Return
                        </Button>
                    </Link>
                </div>

                {Object.keys(errors).length > 0 && (
                    <div className="rounded-lg border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive">
                        <p className="font-semibold">Unable to submit store transfer. Please correct the following errors:</p>
                        <ul className="list-disc list-inside mt-1.5 space-y-1">
                            {Object.entries(errors).map(([key, msg]) => (
                                <li key={key}>{msg}</li>
                            ))}
                        </ul>
                    </div>
                )}

                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base font-semibold">Source & Destination Stores</CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div className="space-y-1">
                                <Label htmlFor="from_store_id">Source Store (From) *</Label>
                                <select
                                    id="from_store_id"
                                    value={data.from_store_id}
                                    onChange={(e) => handleFromStoreChange(e.target.value)}
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                                    required
                                >
                                    {stores.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                        </option>
                                    ))}
                                </select>
                                {errors.from_store_id && <p className="text-xs text-red-500">{errors.from_store_id}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="to_store_id">Destination Store (To) *</Label>
                                <select
                                    id="to_store_id"
                                    value={data.to_store_id}
                                    onChange={(e) => setData('to_store_id', Number(e.target.value))}
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                                    required
                                >
                                    {stores
                                        .filter((s) => s.id !== Number(data.from_store_id))
                                        .map((s) => (
                                            <option key={s.id} value={s.id}>
                                                {s.name}
                                            </option>
                                        ))}
                                </select>
                                {errors.to_store_id && <p className="text-xs text-red-500">{errors.to_store_id}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="transfer_date">Transfer Date *</Label>
                                <Input
                                    id="transfer_date"
                                    type="date"
                                    required
                                    value={data.transfer_date}
                                    onChange={(e) => setData('transfer_date', e.target.value)}
                                />
                            </div>

                            <div className="space-y-1 md:col-span-3">
                                <Label htmlFor="reason">Transfer Justification / Reason</Label>
                                <Textarea
                                    id="reason"
                                    rows={2}
                                    placeholder="Explain why stock is being moved (e.g. balancing emergency ward buffer)..."
                                    value={data.reason || data.notes}
                                    onChange={(e) => {
                                        const val = e.target.value;
                                        setData((prev) => ({
                                            ...prev,
                                            reason: val,
                                            notes: val,
                                        }));
                                    }}
                                />
                                {errors.notes && <p className="text-xs text-red-500">{errors.notes}</p>}
                                {errors.reason && <p className="text-xs text-red-500">{errors.reason}</p>}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Transfer Batches */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-3">
                            <CardTitle className="text-base font-semibold">Stock Batches to Move ({data.items.length})</CardTitle>
                            <div className="flex items-center gap-2">
                                <select
                                    value={selectedBatchId}
                                    onChange={(e) => setSelectedBatchId(e.target.value)}
                                    className="rounded-md border border-input bg-background px-3 py-1.5 text-sm"
                                >
                                    <option value="">-- Choose available batch in source store --</option>
                                    {available_batches.map((b) => (
                                        <option key={b.id} value={b.id}>
                                            {b.stock_item?.name} - Lot: {b.batch_number} (Avail: {b.quantity})
                                        </option>
                                    ))}
                                </select>
                                <Button
                                    type="button"
                                    size="sm"
                                    disabled={!selectedBatchId}
                                    onClick={() => {
                                        const found = available_batches.find((b) => b.id === Number(selectedBatchId));
                                        if (found) addBatchLine(found);
                                    }}
                                >
                                    <Plus className="mr-1 h-4 w-4" /> Add Batch
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            {errors.items && <p className="mb-2 text-xs text-red-500">{errors.items}</p>}
                            {data.items.length === 0 ? (
                                <div className="py-8 text-center text-sm text-muted-foreground border-2 border-dashed rounded-lg">
                                    No batches selected. Choose available batches from the source store above.
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-sm">
                                        <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                            <tr>
                                                <th className="px-3 py-2">Batch Details</th>
                                                <th className="px-3 py-2 w-28">Max Avail</th>
                                                <th className="px-3 py-2 w-32">Qty to Move</th>
                                                <th className="px-3 py-2">Notes</th>
                                                <th className="px-3 py-2 text-right">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {data.items.map((line, idx) => (
                                                <tr key={idx}>
                                                    <td className="px-3 py-2 font-medium">{line.batch_label}</td>
                                                    <td className="px-3 py-2 text-muted-foreground">{line.max_available}</td>
                                                    <td className="px-3 py-2">
                                                        <Input
                                                            type="number"
                                                            min="1"
                                                            max={line.max_available}
                                                            value={line.quantity}
                                                            onChange={(e) => updateLine(idx, 'quantity', parseInt(e.target.value) || 0)}
                                                            className="h-8 w-28"
                                                            required
                                                        />
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <Input
                                                            value={line.notes}
                                                            onChange={(e) => updateLine(idx, 'notes', e.target.value)}
                                                            className="h-8 text-xs"
                                                            placeholder="Line remarks..."
                                                        />
                                                    </td>
                                                    <td className="px-3 py-2 text-right">
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            className="h-8 text-red-600 hover:bg-red-50"
                                                            onClick={() => removeItemLine(idx)}
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                        </Button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <div className="flex items-center justify-end gap-3">
                        <Link href="/stores/transfers">
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing || data.items.length === 0}>
                            <ArrowRightLeft className="mr-2 h-4 w-4" />
                            {processing ? 'Creating Transfer...' : 'Initiate Inter-Store Transfer'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
