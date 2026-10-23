import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, PackageCheck, Trash2 } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Store & Inventory', href: '/stores' },
    { title: 'Goods Receipt Notes', href: '/stores/grn' },
    { title: 'New Receipt', href: '/stores/grn/create' },
];

interface POLineItem {
    id: number;
    procurement_item_id: number;
    item_description: string;
    quantity_ordered: number;
    quantity_received: number;
    unit_of_measure: string;
    unit_price: number;
    procurement_item?: { code: string; name: string };
}

interface PurchaseOrder {
    id: number;
    po_number: string;
    vendor_id: number;
    receiving_store_id: number;
    vendor?: { id: number; name: string };
    receiving_store?: { id: number; name: string };
    items: POLineItem[];
}

interface Props {
    purchase_orders: PurchaseOrder[];
    stores: { id: number; name: string }[];
    selected_po: PurchaseOrder | null;
}

interface GRNLineItem {
    [key: string]: any;
    procurement_item_id: number;
    purchase_order_item_id: number | null;
    item_name: string;
    item_description: string;
    quantity_delivered: number;
    quantity_received: number;
    unit_of_measure: string;
    unit_cost: number;
    batch_number: string;
    expiry_date: string;
    storage_location: string;
}

interface GRNForm {
    [key: string]: any;
    purchase_order_id: string | number;
    vendor_id: string | number;
    store_id: string | number;
    received_date: string;
    delivery_note_number: string;
    invoice_reference: string;
    notes: string;
    remarks: string;
    items: GRNLineItem[];
}

export default function CreateGRN({ purchase_orders, stores, selected_po }: Props) {
    const defaultLines: GRNLineItem[] = selected_po?.items
        ? selected_po.items.map((pi) => {
              const pendingQty = Math.max(0, Number(pi.quantity_ordered) - Number(pi.quantity_received));
              const name = pi.procurement_item ? `${pi.procurement_item.name} (${pi.procurement_item.code})` : (pi.item_description || 'Item');
              return {
                  procurement_item_id: pi.procurement_item_id,
                  purchase_order_item_id: pi.id,
                  item_name: name,
                  item_description: name,
                  quantity_delivered: pendingQty || 1,
                  quantity_received: pendingQty || 1,
                  unit_of_measure: pi.unit_of_measure || 'units',
                  unit_cost: Number(pi.unit_price) || 0,
                  batch_number: '',
                  expiry_date: '',
                  storage_location: 'Bay A-1',
              };
          })
        : [];

    const { data, setData, post, processing, errors, transform } = useForm<GRNForm>({
        purchase_order_id: selected_po?.id || '',
        vendor_id: selected_po?.vendor_id || '',
        store_id: selected_po?.receiving_store_id || stores[0]?.id || '',
        received_date: new Date().toISOString().split('T')[0],
        delivery_note_number: '',
        invoice_reference: '',
        notes: '',
        remarks: '',
        items: defaultLines,
    });

    const handlePoChange = (poIdStr: string) => {
        setData('purchase_order_id', poIdStr ? Number(poIdStr) : '');
        if (poIdStr) {
            const found = purchase_orders.find((p) => p.id === Number(poIdStr));
            if (found) {
                setData((prev) => ({
                    ...prev,
                    purchase_order_id: found.id,
                    vendor_id: found.vendor_id,
                    store_id: found.receiving_store_id || prev.store_id,
                    items: (found.items || []).map((pi) => {
                        const pendingQty = Math.max(0, Number(pi.quantity_ordered) - Number(pi.quantity_received));
                        const name = pi.procurement_item ? `${pi.procurement_item.name} (${pi.procurement_item.code})` : (pi.item_description || 'Item');
                        return {
                            procurement_item_id: pi.procurement_item_id,
                            purchase_order_item_id: pi.id,
                            item_name: name,
                            item_description: name,
                            quantity_delivered: pendingQty || 1,
                            quantity_received: pendingQty || 1,
                            unit_of_measure: pi.unit_of_measure || 'units',
                            unit_cost: Number(pi.unit_price) || 0,
                            batch_number: '',
                            expiry_date: '',
                            storage_location: 'Bay A-1',
                        };
                    }),
                }));
            }
        }
    };

    const updateLine = (index: number, field: keyof GRNLineItem, value: any) => {
        const next = [...data.items];
        next[index] = { ...next[index], [field]: value };
        if (field === 'quantity_received') {
            next[index].quantity_delivered = value;
        } else if (field === 'quantity_delivered') {
            next[index].quantity_received = value;
        } else if (field === 'item_description') {
            next[index].item_name = value;
        } else if (field === 'item_name') {
            next[index].item_description = value;
        }
        setData('items', next);
    };

    const removeItemLine = (index: number) => {
        const next = [...data.items];
        next.splice(index, 1);
        setData('items', next);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        transform((formState) => ({
            ...formState,
            remarks: formState.remarks || formState.notes || '',
            notes: formState.notes || formState.remarks || '',
            items: formState.items.map((line) => ({
                ...line,
                item_name: line.item_name || line.item_description,
                item_description: line.item_description || line.item_name,
                quantity_delivered: line.quantity_delivered || line.quantity_received,
                quantity_received: line.quantity_received || line.quantity_delivered,
            })),
        }));
        post('/stores/grn');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create Goods Receipt Note - Store" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <PageHeader
                        title="Create Goods Receipt Note (GRN)"
                        description="Record physical arrival of goods at hospital stores receiving bay against approved Purchase Order."
                    />
                    <Link href="/stores/grn">
                        <Button variant="outline" size="sm">
                            <ArrowLeft className="mr-2 h-4 w-4" /> Cancel & Return
                        </Button>
                    </Link>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base font-semibold">Delivery Identification & Destination Store</CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div className="space-y-1">
                                <Label htmlFor="purchase_order_id">Linked Purchase Order *</Label>
                                <select
                                    id="purchase_order_id"
                                    value={data.purchase_order_id}
                                    onChange={(e) => handlePoChange(e.target.value)}
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                                    required
                                >
                                    <option value="">-- Choose Purchase Order --</option>
                                    {purchase_orders.map((po) => (
                                        <option key={po.id} value={po.id}>
                                            {po.po_number} - {po.vendor?.name}
                                        </option>
                                    ))}
                                </select>
                                {errors.purchase_order_id && <p className="text-xs text-red-500">{errors.purchase_order_id}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="store_id">Receiving Hospital Store *</Label>
                                <select
                                    id="store_id"
                                    value={data.store_id}
                                    onChange={(e) => setData('store_id', Number(e.target.value))}
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                                    required
                                >
                                    {stores.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                        </option>
                                    ))}
                                </select>
                                {errors.store_id && <p className="text-xs text-red-500">{errors.store_id}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="received_date">Goods Received Date *</Label>
                                <Input
                                    id="received_date"
                                    type="date"
                                    required
                                    value={data.received_date}
                                    onChange={(e) => setData('received_date', e.target.value)}
                                />
                                {errors.received_date && <p className="text-xs text-red-500">{errors.received_date}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="delivery_note_number">Carrier / Delivery Note #</Label>
                                <Input
                                    id="delivery_note_number"
                                    placeholder="e.g. DN-2026-891"
                                    value={data.delivery_note_number}
                                    onChange={(e) => setData('delivery_note_number', e.target.value)}
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="invoice_reference">Supplier Invoice / Waybill Ref</Label>
                                <Input
                                    id="invoice_reference"
                                    placeholder="e.g. WB-4412"
                                    value={data.invoice_reference}
                                    onChange={(e) => setData('invoice_reference', e.target.value)}
                                />
                            </div>

                            <div className="space-y-1 md:col-span-3">
                                <Label htmlFor="notes">Delivery Condition & Packaging Notes</Label>
                                <Textarea
                                    id="notes"
                                    rows={2}
                                    placeholder="Note box conditions, temperature recorder reading (e.g. 4.2°C cold chain verified), seal status..."
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Received Items */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base font-semibold">Arrived Items to Inspect ({data.items.length})</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {errors.items && <p className="mb-2 text-xs text-red-500">{errors.items}</p>}
                            {data.items.length === 0 ? (
                                <div className="py-8 text-center text-sm text-muted-foreground border-2 border-dashed rounded-lg">
                                    Select an approved Purchase Order above to load ordered items.
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-sm">
                                        <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                            <tr>
                                                <th className="px-3 py-2">Item Description</th>
                                                <th className="px-3 py-2 w-24">Qty Received</th>
                                                <th className="px-3 py-2 w-20">UOM</th>
                                                <th className="px-3 py-2 w-32">Batch / Lot #</th>
                                                <th className="px-3 py-2 w-36">Expiry Date</th>
                                                <th className="px-3 py-2 w-28">Store Bin / Bay</th>
                                                <th className="px-3 py-2 text-right">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {data.items.map((line, idx) => (
                                                <tr key={idx}>
                                                    <td className="px-3 py-2 font-medium">{line.item_description}</td>
                                                    <td className="px-3 py-2">
                                                        <Input
                                                            type="number"
                                                            min="1"
                                                            value={line.quantity_received}
                                                            onChange={(e) => updateLine(idx, 'quantity_received', parseInt(e.target.value) || 0)}
                                                            className="h-8 w-24"
                                                            required
                                                        />
                                                    </td>
                                                    <td className="px-3 py-2 text-muted-foreground text-xs">{line.unit_of_measure}</td>
                                                    <td className="px-3 py-2">
                                                        <Input
                                                            placeholder="e.g. B2026-X"
                                                            value={line.batch_number}
                                                            onChange={(e) => updateLine(idx, 'batch_number', e.target.value)}
                                                            className="h-8 w-32 font-mono text-xs"
                                                        />
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <Input
                                                            type="date"
                                                            value={line.expiry_date}
                                                            onChange={(e) => updateLine(idx, 'expiry_date', e.target.value)}
                                                            className="h-8 w-36 text-xs"
                                                        />
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <Input
                                                            placeholder="Shelf A1"
                                                            value={line.storage_location}
                                                            onChange={(e) => updateLine(idx, 'storage_location', e.target.value)}
                                                            className="h-8 w-28 text-xs"
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
                        <Link href="/stores/grn">
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing || data.items.length === 0}>
                            <PackageCheck className="mr-2 h-4 w-4" />
                            {processing ? 'Creating GRN...' : 'Create Goods Receipt Note'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
