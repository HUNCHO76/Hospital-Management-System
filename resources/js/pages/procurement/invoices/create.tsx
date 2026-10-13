import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, ShieldCheck, Trash2 } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Procurement', href: '/procurement' },
    { title: 'Vendor Invoices', href: '/procurement/invoices' },
    { title: 'Enter Invoice', href: '/procurement/invoices/create' },
];

interface POLineItem {
    procurement_item_id: number;
    item_description: string;
    quantity_ordered: number;
    unit_of_measure: string;
    unit_price: number;
    tax_percent: number;
    procurement_item?: { code: string; name: string };
}

interface PurchaseOrder {
    id: number;
    po_number: string;
    vendor_id: number;
    vendor?: { id: number; name: string };
    total_amount: number;
    items: POLineItem[];
}

interface GRN {
    id: number;
    grn_number: string;
    purchase_order_id: number | null;
    vendor_id: number;
}

interface Props {
    purchase_orders: PurchaseOrder[];
    grns: GRN[];
    selected_po: PurchaseOrder | null;
}

interface InvoiceLineItem {
    [key: string]: any;
    procurement_item_id: number;
    item_description: string;
    quantity_billed: number;
    unit_of_measure: string;
    unit_price: number;
    tax_percent: number;
}

interface InvoiceForm {
    [key: string]: any;
    vendor_id: string | number;
    purchase_order_id: string | number;
    goods_receipt_note_id: string | number;
    invoice_number: string;
    invoice_date: string;
    due_date: string;
    freight_amount: number;
    notes: string;
    items: InvoiceLineItem[];
}

export default function CreateVendorInvoice({ purchase_orders, grns, selected_po }: Props) {
    const initialLines: InvoiceLineItem[] = selected_po?.items
        ? selected_po.items.map((pi) => ({
              procurement_item_id: pi.procurement_item_id,
              item_description: pi.procurement_item ? `${pi.procurement_item.name} (${pi.procurement_item.code})` : pi.item_description,
              quantity_billed: Number(pi.quantity_ordered) || 1,
              unit_of_measure: pi.unit_of_measure || 'units',
              unit_price: Number(pi.unit_price) || 0,
              tax_percent: Number(pi.tax_percent) || 0,
          }))
        : [];

    const { data, setData, post, processing, errors } = useForm<InvoiceForm>({
        vendor_id: selected_po?.vendor_id || '',
        purchase_order_id: selected_po?.id || '',
        goods_receipt_note_id: '',
        invoice_number: '',
        invoice_date: new Date().toISOString().split('T')[0],
        due_date: '',
        freight_amount: 0,
        notes: '',
        items: initialLines,
    });

    const handlePoChange = (poIdStr: string) => {
        setData('purchase_order_id', poIdStr ? Number(poIdStr) : '');
        if (poIdStr) {
            const foundPo = purchase_orders.find((p) => p.id === Number(poIdStr));
            if (foundPo) {
                setData((prev) => ({
                    ...prev,
                    purchase_order_id: foundPo.id,
                    vendor_id: foundPo.vendor_id,
                    items: (foundPo.items || []).map((pi) => ({
                        procurement_item_id: pi.procurement_item_id,
                        item_description: pi.procurement_item ? `${pi.procurement_item.name} (${pi.procurement_item.code})` : pi.item_description,
                        quantity_billed: Number(pi.quantity_ordered) || 1,
                        unit_of_measure: pi.unit_of_measure || 'units',
                        unit_price: Number(pi.unit_price) || 0,
                        tax_percent: Number(pi.tax_percent) || 0,
                    })),
                }));
            }
        }
    };

    const updateLine = (index: number, field: keyof InvoiceLineItem, value: any) => {
        const next = [...data.items];
        next[index] = { ...next[index], [field]: value };
        setData('items', next);
    };

    const removeItemLine = (index: number) => {
        const next = [...data.items];
        next.splice(index, 1);
        setData('items', next);
    };

    const subtotal = data.items.reduce((sum, line) => sum + (Number(line.quantity_billed) * Number(line.unit_price) || 0), 0);
    const taxTotal = data.items.reduce((sum, line) => {
        const base = Number(line.quantity_billed) * Number(line.unit_price) || 0;
        return sum + base * ((Number(line.tax_percent) || 0) / 100);
    }, 0);
    const grandTotal = subtotal + taxTotal + (Number(data.freight_amount) || 0);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/procurement/invoices');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Enter Vendor Invoice - Procurement" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <PageHeader
                        title="Enter Vendor Invoice"
                        description="Record supplier tax invoice for automated 3-way matching verification against PO and GRN."
                    />
                    <Link href="/procurement/invoices">
                        <Button variant="outline" size="sm">
                            <ArrowLeft className="mr-2 h-4 w-4" /> Cancel & Return
                        </Button>
                    </Link>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base font-semibold">Invoice Credentials & PO Reference</CardTitle>
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
                                    <option value="">-- Select PO to match against --</option>
                                    {purchase_orders.map((po) => (
                                        <option key={po.id} value={po.id}>
                                            {po.po_number} - {po.vendor?.name} (TZS {Number(po.total_amount).toLocaleString()})
                                        </option>
                                    ))}
                                </select>
                                {errors.purchase_order_id && <p className="text-xs text-red-500">{errors.purchase_order_id}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="goods_receipt_note_id">Linked GRN (Optional)</Label>
                                <select
                                    id="goods_receipt_note_id"
                                    value={data.goods_receipt_note_id}
                                    onChange={(e) => setData('goods_receipt_note_id', e.target.value ? Number(e.target.value) : '')}
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                                >
                                    <option value="">-- Auto-detect from PO if blank --</option>
                                    {grns.map((g) => (
                                        <option key={g.id} value={g.id}>
                                            {g.grn_number}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="invoice_number">Vendor's Physical Invoice # *</Label>
                                <Input
                                    id="invoice_number"
                                    required
                                    placeholder="e.g. INV-2026-9812"
                                    value={data.invoice_number}
                                    onChange={(e) => setData('invoice_number', e.target.value)}
                                />
                                {errors.invoice_number && <p className="text-xs text-red-500">{errors.invoice_number}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="invoice_date">Invoice Date *</Label>
                                <Input
                                    id="invoice_date"
                                    type="date"
                                    required
                                    value={data.invoice_date}
                                    onChange={(e) => setData('invoice_date', e.target.value)}
                                />
                                {errors.invoice_date && <p className="text-xs text-red-500">{errors.invoice_date}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="due_date">Payment Due Date</Label>
                                <Input
                                    id="due_date"
                                    type="date"
                                    value={data.due_date}
                                    onChange={(e) => setData('due_date', e.target.value)}
                                />
                            </div>

                            <div className="space-y-1 md:col-span-3">
                                <Label htmlFor="notes">Invoice Notes & Tax Exemption Numbers</Label>
                                <Textarea
                                    id="notes"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    placeholder="Enter TRA EFD receipt number, bank account details, or discrepancy explanation..."
                                    rows={2}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Line items */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base font-semibold">Billed Line Items ({data.items.length})</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {errors.items && <p className="mb-2 text-xs text-red-500">{errors.items}</p>}
                            {data.items.length === 0 ? (
                                <div className="py-8 text-center text-sm text-muted-foreground border-2 border-dashed rounded-lg">
                                    Select a Purchase Order above to automatically populate billed line items.
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-sm">
                                        <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                            <tr>
                                                <th className="px-3 py-2">Item Description</th>
                                                <th className="px-3 py-2 w-24">Qty Billed</th>
                                                <th className="px-3 py-2 w-20">UOM</th>
                                                <th className="px-3 py-2 w-28">Unit Price</th>
                                                <th className="px-3 py-2 w-20">VAT %</th>
                                                <th className="px-3 py-2 w-32">Total (TZS)</th>
                                                <th className="px-3 py-2 text-right">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {data.items.map((line, idx) => {
                                                const base = Number(line.quantity_billed) * Number(line.unit_price);
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
                                                                value={line.quantity_billed}
                                                                onChange={(e) => updateLine(idx, 'quantity_billed', parseFloat(e.target.value) || 0)}
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
                                                <td colSpan={2} className="px-3 py-2">TZS {subtotal.toLocaleString()}</td>
                                            </tr>
                                            <tr className="bg-muted/50 font-medium">
                                                <td colSpan={5} className="px-3 py-2 text-right">VAT:</td>
                                                <td colSpan={2} className="px-3 py-2">TZS {taxTotal.toLocaleString()}</td>
                                            </tr>
                                            <tr className="bg-muted/50 font-semibold text-primary">
                                                <td colSpan={5} className="px-3 py-3 text-right text-base">Total Invoice Amount:</td>
                                                <td colSpan={2} className="px-3 py-3 text-base">TZS {grandTotal.toLocaleString()}</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <div className="flex items-center justify-end gap-3">
                        <Link href="/procurement/invoices">
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing || data.items.length === 0}>
                            <ShieldCheck className="mr-2 h-4 w-4" />
                            {processing ? 'Auditing & Matching...' : 'Record & Run 3-Way Match'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
