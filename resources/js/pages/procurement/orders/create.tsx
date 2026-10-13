import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Procurement', href: '/procurement' },
    { title: 'Purchase Orders', href: '/procurement/orders' },
    { title: 'Create PO', href: '/procurement/orders/create' },
];

interface ProcurementItem {
    id: number;
    code: string;
    name: string;
    unit_of_measure: string;
    estimated_unit_cost: number | null;
}

interface Props {
    vendors: { id: number; name: string; payment_terms?: string }[];
    stores: { id: number; name: string; type: string }[];
    items: ProcurementItem[];
    quotation: any | null;
    indent: any | null;
}

interface POLineItem {
    [key: string]: any;
    procurement_item_id: number;
    item_description: string;
    quantity_ordered: number;
    unit_of_measure: string;
    unit_price: number;
    tax_percent: number;
    notes: string;
}

interface POCreateForm {
    [key: string]: any;
    vendor_id: any;
    vendor_quotation_id: any;
    indent_id: any;
    receiving_store_id: string | number;
    po_date: string;
    expected_delivery_date: string;
    payment_terms: any;
    shipping_terms: string;
    freight_amount: number;
    notes: string;
    items: POLineItem[];
}

export default function CreatePurchaseOrder({ vendors, stores, items, quotation, indent }: Props) {
    let initialLines: POLineItem[] = [];

    if (quotation && quotation.items) {
        initialLines = quotation.items.map((qi: any) => ({
            procurement_item_id: qi.procurement_item_id,
            item_description: qi.procurement_item ? `${qi.procurement_item.name} (${qi.procurement_item.code})` : qi.item_description,
            quantity_ordered: Number(qi.quantity) || 1,
            unit_of_measure: qi.unit_of_measure || 'units',
            unit_price: Number(qi.unit_price) || 0,
            tax_percent: Number(qi.tax_percent) || 0,
            notes: '',
        }));
    } else if (indent && indent.items) {
        initialLines = indent.items.map((ii: any) => ({
            procurement_item_id: ii.procurement_item_id,
            item_description: ii.procurement_item ? `${ii.procurement_item.name} (${ii.procurement_item.code})` : 'Item',
            quantity_ordered: Number(ii.quantity_requested) || 1,
            unit_of_measure: ii.procurement_item?.unit_of_measure || 'units',
            unit_price: Number(ii.estimated_unit_cost) || 0,
            tax_percent: 0,
            notes: ii.purpose || '',
        }));
    }

    const { data, setData, post, processing, errors } = useForm<POCreateForm>({
        vendor_id: quotation?.vendor_id || vendors[0]?.id || '',
        vendor_quotation_id: quotation?.id || '',
        indent_id: indent?.id || quotation?.indent_id || '',
        receiving_store_id: stores[0]?.id || '',
        po_date: new Date().toISOString().split('T')[0],
        expected_delivery_date: '',
        payment_terms: quotation?.payment_terms || vendors[0]?.payment_terms || 'Net 30 Days',
        shipping_terms: 'DDP - Hospital Stores Receiving Bay',
        freight_amount: Number(quotation?.freight_amount) || 0,
        notes: '',
        items: initialLines,
    });

    const [selectedItemId, setSelectedItemId] = useState<string>('');

    const addItemLine = (item: ProcurementItem) => {
        setData('items', [
            ...data.items,
            {
                procurement_item_id: item.id,
                item_description: `${item.name} (${item.code})`,
                quantity_ordered: 1,
                unit_of_measure: item.unit_of_measure,
                unit_price: Number(item.estimated_unit_cost) || 0,
                tax_percent: 0,
                notes: '',
            },
        ]);
        setSelectedItemId('');
    };

    const removeItemLine = (index: number) => {
        const next = [...data.items];
        next.splice(index, 1);
        setData('items', next);
    };

    const updateLine = (index: number, field: keyof POLineItem, value: any) => {
        const next = [...data.items];
        next[index] = { ...next[index], [field]: value };
        setData('items', next);
    };

    const subtotal = data.items.reduce((sum, line) => sum + (Number(line.quantity_ordered) * Number(line.unit_price) || 0), 0);
    const taxTotal = data.items.reduce((sum, line) => {
        const base = Number(line.quantity_ordered) * Number(line.unit_price) || 0;
        return sum + base * ((Number(line.tax_percent) || 0) / 100);
    }, 0);
    const grandTotal = subtotal + taxTotal + (Number(data.freight_amount) || 0);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/procurement/orders');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create Purchase Order - Procurement" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <PageHeader
                        title="Create Purchase Order"
                        description="Issue formal legal purchase commitment to certified vendor with delivery deadlines and price specifications."
                    />
                    <Link href="/procurement/orders">
                        <Button variant="outline" size="sm">
                            <ArrowLeft className="mr-2 h-4 w-4" /> Cancel & Return
                        </Button>
                    </Link>
                </div>

                {quotation && (
                    <Card className="border-blue-200 bg-blue-50/40">
                        <CardContent className="p-4 text-sm text-blue-900">
                            Pre-populated from winning quotation <span className="font-semibold">{quotation.quotation_number}</span> by <span className="font-semibold">{quotation.vendor?.name}</span>.
                        </CardContent>
                    </Card>
                )}

                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base font-semibold">PO Details & Delivery Destination</CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div className="space-y-1">
                                <Label htmlFor="vendor_id">Supplier / Vendor *</Label>
                                <select
                                    id="vendor_id"
                                    value={data.vendor_id}
                                    onChange={(e) => setData('vendor_id', Number(e.target.value))}
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                                    required
                                >
                                    {vendors.map((v) => (
                                        <option key={v.id} value={v.id}>
                                            {v.name}
                                        </option>
                                    ))}
                                </select>
                                {errors.vendor_id && <p className="text-xs text-red-500">{errors.vendor_id}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="receiving_store_id">Receiving Hospital Store *</Label>
                                <select
                                    id="receiving_store_id"
                                    value={data.receiving_store_id}
                                    onChange={(e) => setData('receiving_store_id', Number(e.target.value))}
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                                    required
                                >
                                    {stores.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name} ({s.type})
                                        </option>
                                    ))}
                                </select>
                                {errors.receiving_store_id && <p className="text-xs text-red-500">{errors.receiving_store_id}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="po_date">Purchase Order Date *</Label>
                                <Input
                                    id="po_date"
                                    type="date"
                                    value={data.po_date}
                                    onChange={(e) => setData('po_date', e.target.value)}
                                    required
                                />
                                {errors.po_date && <p className="text-xs text-red-500">{errors.po_date}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="expected_delivery_date">Expected Delivery Date</Label>
                                <Input
                                    id="expected_delivery_date"
                                    type="date"
                                    value={data.expected_delivery_date}
                                    onChange={(e) => setData('expected_delivery_date', e.target.value)}
                                />
                                {errors.expected_delivery_date && <p className="text-xs text-red-500">{errors.expected_delivery_date}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="payment_terms">Payment Terms</Label>
                                <Input
                                    id="payment_terms"
                                    value={data.payment_terms}
                                    onChange={(e) => setData('payment_terms', e.target.value)}
                                    placeholder="e.g. Net 30, Against GRN"
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="shipping_terms">Delivery / Incoterms</Label>
                                <Input
                                    id="shipping_terms"
                                    value={data.shipping_terms}
                                    onChange={(e) => setData('shipping_terms', e.target.value)}
                                    placeholder="e.g. DDP - Main Store Dock"
                                />
                            </div>

                            <div className="space-y-1 md:col-span-3">
                                <Label htmlFor="notes">Order Instructions & Quality Standards</Label>
                                <Textarea
                                    id="notes"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    placeholder="Special storage requirements (e.g. cold chain 2-8°C), inspection protocols, or billing instructions..."
                                    rows={2}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Order Line Items */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-3">
                            <CardTitle className="text-base font-semibold">Ordered Line Items ({data.items.length})</CardTitle>
                            <div className="flex items-center gap-2">
                                <select
                                    value={selectedItemId}
                                    onChange={(e) => setSelectedItemId(e.target.value)}
                                    className="rounded-md border border-input bg-background px-3 py-1.5 text-sm"
                                >
                                    <option value="">-- Add catalog item --</option>
                                    {items.map((it) => (
                                        <option key={it.id} value={it.id}>
                                            {it.name} ({it.code})
                                        </option>
                                    ))}
                                </select>
                                <Button
                                    type="button"
                                    size="sm"
                                    disabled={!selectedItemId}
                                    onClick={() => {
                                        const found = items.find((i) => i.id === Number(selectedItemId));
                                        if (found) addItemLine(found);
                                    }}
                                >
                                    <Plus className="mr-1 h-4 w-4" /> Add Item
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            {errors.items && <p className="mb-2 text-xs text-red-500">{errors.items}</p>}
                            {data.items.length === 0 ? (
                                <div className="py-8 text-center text-sm text-muted-foreground border-2 border-dashed rounded-lg">
                                    No ordered line items. Add catalog items using the dropdown above.
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-sm">
                                        <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                            <tr>
                                                <th className="px-3 py-2">Item Description</th>
                                                <th className="px-3 py-2 w-24">Qty Ordered</th>
                                                <th className="px-3 py-2 w-20">UOM</th>
                                                <th className="px-3 py-2 w-28">Unit Price (TZS)</th>
                                                <th className="px-3 py-2 w-20">VAT %</th>
                                                <th className="px-3 py-2 w-32">Total (TZS)</th>
                                                <th className="px-3 py-2">Notes</th>
                                                <th className="px-3 py-2 text-right">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {data.items.map((line, idx) => {
                                                const base = Number(line.quantity_ordered) * Number(line.unit_price);
                                                const tax = base * ((Number(line.tax_percent) || 0) / 100);
                                                const lineTotal = base + tax;

                                                return (
                                                    <tr key={idx}>
                                                        <td className="px-3 py-2 font-medium">{line.item_description}</td>
                                                        <td className="px-3 py-2">
                                                            <Input
                                                                type="number"
                                                                min="0.01"
                                                                step="any"
                                                                value={line.quantity_ordered}
                                                                onChange={(e) => updateLine(idx, 'quantity_ordered', parseFloat(e.target.value) || 0)}
                                                                className="h-8 w-24"
                                                                required
                                                            />
                                                        </td>
                                                        <td className="px-3 py-2 text-muted-foreground text-xs">{line.unit_of_measure}</td>
                                                        <td className="px-3 py-2">
                                                            <Input
                                                                type="number"
                                                                min="0"
                                                                step="any"
                                                                value={line.unit_price}
                                                                onChange={(e) => updateLine(idx, 'unit_price', parseFloat(e.target.value) || 0)}
                                                                className="h-8 w-28"
                                                                required
                                                            />
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            <Input
                                                                type="number"
                                                                min="0"
                                                                max="100"
                                                                value={line.tax_percent}
                                                                onChange={(e) => updateLine(idx, 'tax_percent', parseFloat(e.target.value) || 0)}
                                                                className="h-8 w-16"
                                                            />
                                                        </td>
                                                        <td className="px-3 py-2 font-semibold">
                                                            TZS {lineTotal.toLocaleString()}
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            <Input
                                                                value={line.notes}
                                                                onChange={(e) => updateLine(idx, 'notes', e.target.value)}
                                                                className="h-8 text-xs"
                                                                placeholder="Line notes..."
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
                                                );
                                            })}
                                        </tbody>
                                        <tfoot>
                                            <tr className="bg-muted/50 font-medium">
                                                <td colSpan={5} className="px-3 py-2 text-right">Subtotal:</td>
                                                <td colSpan={3} className="px-3 py-2">TZS {subtotal.toLocaleString()}</td>
                                            </tr>
                                            <tr className="bg-muted/50 font-medium">
                                                <td colSpan={5} className="px-3 py-2 text-right">VAT:</td>
                                                <td colSpan={3} className="px-3 py-2">TZS {taxTotal.toLocaleString()}</td>
                                            </tr>
                                            <tr className="bg-muted/50 font-semibold text-primary">
                                                <td colSpan={5} className="px-3 py-3 text-right text-base">Grand Total:</td>
                                                <td colSpan={3} className="px-3 py-3 text-base">TZS {grandTotal.toLocaleString()}</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <div className="flex items-center justify-end gap-3">
                        <Link href="/procurement/orders">
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing || data.items.length === 0}>
                            {processing ? 'Creating Order...' : 'Generate Purchase Order'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
