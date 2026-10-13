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
    { title: 'Vendor Quotations', href: '/procurement/quotations' },
    { title: 'Record Quotation', href: '/procurement/quotations/create' },
];

interface ProcurementItem {
    id: number;
    code: string;
    name: string;
    unit_of_measure: string;
    estimated_unit_cost: number | null;
}

interface IndentLine {
    procurement_item_id: number;
    procurement_item?: ProcurementItem;
    quantity_requested: number;
    estimated_unit_cost: number;
}

interface Indent {
    id: number;
    indent_number: string;
    items: IndentLine[];
}

interface Props {
    vendors: { id: number; name: string }[];
    indents: Indent[];
    items: ProcurementItem[];
    selected_indent: Indent | null;
}

interface QuotationLineItem {
    [key: string]: any;
    procurement_item_id: number;
    item_description: string;
    quantity: number;
    unit_of_measure: string;
    unit_price: number;
    discount_percent: number;
    tax_percent: number;
    delivery_days: number;
    warranty_period: string;
    remarks: string;
}

interface QuotationForm {
    [key: string]: any;
    vendor_id: string | number;
    indent_id: string | number;
    quotation_date: string;
    valid_until: string;
    payment_terms: string;
    delivery_lead_time_days: number;
    freight_amount: number;
    notes: string;
    items: QuotationLineItem[];
}

export default function CreateQuotation({ vendors, indents, items, selected_indent }: Props) {
    const initialLines: QuotationLineItem[] = selected_indent?.items
        ? selected_indent.items.map((it) => ({
              procurement_item_id: it.procurement_item_id,
              item_description: it.procurement_item?.name || 'Item',
              quantity: Number(it.quantity_requested) || 1,
              unit_of_measure: it.procurement_item?.unit_of_measure || 'units',
              unit_price: Number(it.estimated_unit_cost) || 0,
              discount_percent: 0,
              tax_percent: 0,
              delivery_days: 7,
              warranty_period: '',
              remarks: '',
          }))
        : [];

    const { data, setData, post, processing, errors } = useForm<QuotationForm>({
        vendor_id: vendors[0]?.id || '',
        indent_id: selected_indent?.id || '',
        quotation_date: new Date().toISOString().split('T')[0],
        valid_until: '',
        payment_terms: '30 Days Net',
        delivery_lead_time_days: 7,
        freight_amount: 0,
        notes: '',
        items: initialLines,
    });

    const [selectedItemId, setSelectedItemId] = useState<string>('');

    const handleIndentChange = (indentIdStr: string) => {
        setData('indent_id', indentIdStr ? Number(indentIdStr) : '');
        if (indentIdStr) {
            const ind = indents.find((i) => i.id === Number(indentIdStr));
            if (ind && ind.items) {
                const lines = ind.items.map((it) => ({
                    procurement_item_id: it.procurement_item_id,
                    item_description: it.procurement_item?.name || 'Item',
                    quantity: Number(it.quantity_requested) || 1,
                    unit_of_measure: it.procurement_item?.unit_of_measure || 'units',
                    unit_price: Number(it.estimated_unit_cost) || 0,
                    discount_percent: 0,
                    tax_percent: 0,
                    delivery_days: 7,
                    warranty_period: '',
                    remarks: '',
                }));
                setData('items', lines);
            }
        }
    };

    const addItemLine = (item: ProcurementItem) => {
        setData('items', [
            ...data.items,
            {
                procurement_item_id: item.id,
                item_description: `${item.name} (${item.code})`,
                quantity: 1,
                unit_of_measure: item.unit_of_measure,
                unit_price: Number(item.estimated_unit_cost) || 0,
                discount_percent: 0,
                tax_percent: 0,
                delivery_days: 7,
                warranty_period: '',
                remarks: '',
            },
        ]);
        setSelectedItemId('');
    };

    const removeItemLine = (index: number) => {
        const next = [...data.items];
        next.splice(index, 1);
        setData('items', next);
    };

    const updateLine = (index: number, field: keyof QuotationLineItem, value: any) => {
        const next = [...data.items];
        next[index] = { ...next[index], [field]: value };
        setData('items', next);
    };

    // Calculate totals
    const subtotal = data.items.reduce((sum, line) => {
        const base = Number(line.quantity) * Number(line.unit_price);
        const disc = base * ((Number(line.discount_percent) || 0) / 100);
        return sum + (base - disc);
    }, 0);

    const taxTotal = data.items.reduce((sum, line) => {
        const base = Number(line.quantity) * Number(line.unit_price);
        const disc = base * ((Number(line.discount_percent) || 0) / 100);
        const taxable = base - disc;
        return sum + taxable * ((Number(line.tax_percent) || 0) / 100);
    }, 0);

    const grandTotal = subtotal + taxTotal + (Number(data.freight_amount) || 0);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/procurement/quotations');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Record Vendor Quotation - Procurement" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <PageHeader
                        title="Record Vendor Quotation"
                        description="Capture formal price proposals, payment terms, and delivery commitments received from certified vendors."
                    />
                    <Link href="/procurement/quotations">
                        <Button variant="outline" size="sm">
                            <ArrowLeft className="mr-2 h-4 w-4" /> Back to Quotations
                        </Button>
                    </Link>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base font-semibold">Quotation Header & Terms</CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div className="space-y-1">
                                <Label htmlFor="vendor_id">Vendor *</Label>
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
                                <Label htmlFor="indent_id">Link to Purchase Indent (Optional)</Label>
                                <select
                                    id="indent_id"
                                    value={data.indent_id}
                                    onChange={(e) => handleIndentChange(e.target.value)}
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                                >
                                    <option value="">-- Standalone Quotation --</option>
                                    {indents.map((ind) => (
                                        <option key={ind.id} value={ind.id}>
                                            {ind.indent_number}
                                        </option>
                                    ))}
                                </select>
                                {errors.indent_id && <p className="text-xs text-red-500">{errors.indent_id}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="quotation_date">Quotation Date *</Label>
                                <Input
                                    id="quotation_date"
                                    type="date"
                                    value={data.quotation_date}
                                    onChange={(e) => setData('quotation_date', e.target.value)}
                                    required
                                />
                                {errors.quotation_date && <p className="text-xs text-red-500">{errors.quotation_date}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="valid_until">Price Valid Until</Label>
                                <Input
                                    id="valid_until"
                                    type="date"
                                    value={data.valid_until}
                                    onChange={(e) => setData('valid_until', e.target.value)}
                                />
                                {errors.valid_until && <p className="text-xs text-red-500">{errors.valid_until}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="delivery_lead_time_days">Delivery Lead Time (Days)</Label>
                                <Input
                                    id="delivery_lead_time_days"
                                    type="number"
                                    min="0"
                                    value={data.delivery_lead_time_days}
                                    onChange={(e) => setData('delivery_lead_time_days', parseInt(e.target.value) || 0)}
                                />
                                {errors.delivery_lead_time_days && <p className="text-xs text-red-500">{errors.delivery_lead_time_days}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="payment_terms">Payment Terms</Label>
                                <Input
                                    id="payment_terms"
                                    value={data.payment_terms}
                                    onChange={(e) => setData('payment_terms', e.target.value)}
                                    placeholder="e.g. Net 30, Cash on Delivery"
                                />
                                {errors.payment_terms && <p className="text-xs text-red-500">{errors.payment_terms}</p>}
                            </div>

                            <div className="space-y-1 md:col-span-3">
                                <Label htmlFor="notes">Vendor Remarks & Special Conditions</Label>
                                <Textarea
                                    id="notes"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    placeholder="Enter warranty details, delivery conditions, or special discount terms..."
                                    rows={2}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Quotation Line Items */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-3">
                            <CardTitle className="text-base font-semibold">Quoted Items ({data.items.length})</CardTitle>
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
                                    No quoted line items. Select an indent above or choose catalog items to add.
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-sm">
                                        <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                            <tr>
                                                <th className="px-3 py-2">Item Description</th>
                                                <th className="px-3 py-2 w-20">Qty</th>
                                                <th className="px-3 py-2 w-20">UOM</th>
                                                <th className="px-3 py-2 w-28">Unit Price</th>
                                                <th className="px-3 py-2 w-20">Disc %</th>
                                                <th className="px-3 py-2 w-20">VAT %</th>
                                                <th className="px-3 py-2 w-32">Total (TZS)</th>
                                                <th className="px-3 py-2 w-20">Lead Days</th>
                                                <th className="px-3 py-2 text-right">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {data.items.map((line, idx) => {
                                                const base = Number(line.quantity) * Number(line.unit_price);
                                                const disc = base * ((Number(line.discount_percent) || 0) / 100);
                                                const taxable = base - disc;
                                                const tax = taxable * ((Number(line.tax_percent) || 0) / 100);
                                                const lineTotal = taxable + tax;

                                                return (
                                                    <tr key={idx}>
                                                        <td className="px-3 py-2 font-medium">{line.item_description}</td>
                                                        <td className="px-3 py-2">
                                                            <Input
                                                                type="number"
                                                                min="0.01"
                                                                step="any"
                                                                value={line.quantity}
                                                                onChange={(e) => updateLine(idx, 'quantity', parseFloat(e.target.value) || 0)}
                                                                className="h-8 w-20"
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
                                                                value={line.discount_percent}
                                                                onChange={(e) => updateLine(idx, 'discount_percent', parseFloat(e.target.value) || 0)}
                                                                className="h-8 w-16"
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
                                                                type="number"
                                                                min="0"
                                                                value={line.delivery_days}
                                                                onChange={(e) => updateLine(idx, 'delivery_days', parseInt(e.target.value) || 0)}
                                                                className="h-8 w-16"
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
                                                <td colSpan={6} className="px-3 py-2 text-right">Subtotal:</td>
                                                <td colSpan={3} className="px-3 py-2">TZS {subtotal.toLocaleString()}</td>
                                            </tr>
                                            <tr className="bg-muted/50 font-medium">
                                                <td colSpan={6} className="px-3 py-2 text-right">Tax (VAT):</td>
                                                <td colSpan={3} className="px-3 py-2">TZS {taxTotal.toLocaleString()}</td>
                                            </tr>
                                            <tr className="bg-muted/50 font-semibold text-primary">
                                                <td colSpan={6} className="px-3 py-3 text-right text-base">Grand Total:</td>
                                                <td colSpan={3} className="px-3 py-3 text-base">TZS {grandTotal.toLocaleString()}</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <div className="flex items-center justify-end gap-3">
                        <Link href="/procurement/quotations">
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing || data.items.length === 0}>
                            {processing ? 'Recording...' : 'Save Vendor Quotation'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
