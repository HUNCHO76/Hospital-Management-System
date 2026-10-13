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
    { title: 'Purchase Indents', href: '/procurement/indents' },
    { title: 'New Requisition', href: '/procurement/indents/create' },
];

interface ProcurementItem {
    id: number;
    code: string;
    name: string;
    category: string;
    unit_of_measure: string;
    estimated_unit_cost: number | null;
}

interface Props {
    departments: { id: number; name: string }[];
    stores: { id: number; name: string; type: string }[];
    items: ProcurementItem[];
}

interface ItemLine {
    [key: string]: any;
    procurement_item_id: number;
    item_name: string;
    unit_of_measure: string;
    quantity_requested: number;
    estimated_unit_cost: number;
    purpose: string;
    specification: string;
}

interface IndentForm {
    [key: string]: any;
    department_id: string | number;
    target_store_id: string | number;
    indent_date: string;
    required_by_date: string;
    priority: string;
    notes: string;
    items: ItemLine[];
}

export default function CreateIndent({ departments, stores, items }: Props) {
    const { data, setData, post, processing, errors } = useForm<IndentForm>({
        department_id: departments[0]?.id || '',
        target_store_id: stores[0]?.id || '',
        indent_date: new Date().toISOString().split('T')[0],
        required_by_date: '',
        priority: 'normal',
        notes: '',
        items: [] as ItemLine[],
    });

    const addItemLine = (item: ProcurementItem) => {
        setData('items', [
            ...data.items,
            {
                procurement_item_id: item.id,
                item_name: item.name,
                unit_of_measure: item.unit_of_measure,
                quantity_requested: 1,
                estimated_unit_cost: item.estimated_unit_cost ? Number(item.estimated_unit_cost) : 0,
                purpose: '',
                specification: '',
            },
        ]);
    };

    const removeItemLine = (index: number) => {
        const next = [...data.items];
        next.splice(index, 1);
        setData('items', next);
    };

    const updateItemLine = (index: number, field: keyof ItemLine, value: any) => {
        const next = [...data.items];
        next[index] = { ...next[index], [field]: value };
        setData('items', next);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/procurement/indents');
    };

    const totalEstimate = data.items.reduce((acc, curr) => acc + (curr.quantity_requested * curr.estimated_unit_cost), 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create Purchase Indent Requisition" />

            <div className="flex flex-col gap-6 p-4 max-w-5xl mx-auto w-full">
                <PageHeader
                    title="Create Purchase Indent Requisition"
                    description="Submit a procurement requisition for hospital stock replenishment or specific department requirements."
                    actions={
                        <Button asChild variant="outline" size="sm">
                            <Link href="/procurement/indents">
                                <ArrowLeft className="mr-1.5 h-4 w-4" /> Back to Indents
                            </Link>
                        </Button>
                    }
                />

                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base font-semibold">General Requisition Details</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-3">
                            <div className="space-y-2">
                                <Label htmlFor="department_id">Requesting Department *</Label>
                                <select
                                    id="department_id"
                                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                    value={data.department_id}
                                    onChange={(e) => setData('department_id', Number(e.target.value))}
                                    required
                                >
                                    <option value="">Select Department</option>
                                    {departments.map((d) => (
                                        <option key={d.id} value={d.id}>
                                            {d.name}
                                        </option>
                                    ))}
                                </select>
                                {errors.department_id && <p className="text-destructive text-xs">{errors.department_id}</p>}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="target_store_id">Target Receiving Store</Label>
                                <select
                                    id="target_store_id"
                                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                    value={data.target_store_id}
                                    onChange={(e) => setData('target_store_id', e.target.value ? Number(e.target.value) : '')}
                                >
                                    <option value="">Select Store</option>
                                    {stores.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name} ({s.type})
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="priority">Requisition Priority *</Label>
                                <select
                                    id="priority"
                                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                    value={data.priority}
                                    onChange={(e) => setData('priority', e.target.value)}
                                    required
                                >
                                    <option value="low">Low</option>
                                    <option value="normal">Normal</option>
                                    <option value="urgent">Urgent</option>
                                    <option value="emergency">Emergency</option>
                                </select>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="indent_date">Requisition Date *</Label>
                                <Input
                                    id="indent_date"
                                    type="date"
                                    value={data.indent_date}
                                    onChange={(e) => setData('indent_date', e.target.value)}
                                    required
                                />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="required_by_date">Required By Date</Label>
                                <Input
                                    id="required_by_date"
                                    type="date"
                                    value={data.required_by_date}
                                    onChange={(e) => setData('required_by_date', e.target.value)}
                                />
                            </div>

                            <div className="sm:col-span-3 space-y-2">
                                <Label htmlFor="notes">Clinical / Justification Notes</Label>
                                <Textarea
                                    id="notes"
                                    rows={2}
                                    placeholder="State purpose of requisition or special clinical instructions..."
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Item Picker */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <div>
                                <CardTitle className="text-base font-semibold">Requested Supplies & Items</CardTitle>
                                <p className="text-xs text-muted-foreground mt-0.5">Select items from catalog and specify requested quantities</p>
                            </div>
                            <div className="w-72">
                                <select
                                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm"
                                    onChange={(e) => {
                                        const selected = items.find((i) => i.id === Number(e.target.value));
                                        if (selected) {
                                            addItemLine(selected);
                                            e.target.value = '';
                                        }
                                    }}
                                    defaultValue=""
                                >
                                    <option value="" disabled>+ Add Item from Catalog...</option>
                                    {items.map((i) => (
                                        <option key={i.id} value={i.id}>
                                            [{i.category}] {i.name} ({i.unit_of_measure})
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </CardHeader>
                        <CardContent>
                            {data.items.length === 0 ? (
                                <div className="text-center py-8 border border-dashed rounded-lg text-muted-foreground text-sm">
                                    No items added yet. Use the dropdown above to add items to this requisition.
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    <div className="grid grid-cols-12 gap-2 text-xs font-semibold text-muted-foreground px-2">
                                        <div className="col-span-4">Item Name</div>
                                        <div className="col-span-2">UOM</div>
                                        <div className="col-span-2">Quantity</div>
                                        <div className="col-span-2 text-right">Est. Unit (TZS)</div>
                                        <div className="col-span-2 text-right">Est. Total</div>
                                    </div>
                                    {data.items.map((line, idx) => (
                                        <div key={idx} className="grid grid-cols-12 gap-2 items-center bg-muted/40 p-2 rounded-md">
                                            <div className="col-span-4">
                                                <p className="font-medium text-sm">{line.item_name}</p>
                                                <Input
                                                    placeholder="Specific purpose / spec"
                                                    value={line.purpose}
                                                    onChange={(e) => updateItemLine(idx, 'purpose', e.target.value)}
                                                    className="mt-1 h-7 text-xs"
                                                />
                                            </div>
                                            <div className="col-span-2">
                                                <span className="text-xs bg-muted px-2 py-1 rounded">{line.unit_of_measure}</span>
                                            </div>
                                            <div className="col-span-2">
                                                <Input
                                                    type="number"
                                                    min="1"
                                                    value={line.quantity_requested}
                                                    onChange={(e) => updateItemLine(idx, 'quantity_requested', Math.max(1, parseInt(e.target.value) || 1))}
                                                    className="h-8 text-sm"
                                                    required
                                                />
                                            </div>
                                            <div className="col-span-2 text-right">
                                                <Input
                                                    type="number"
                                                    min="0"
                                                    step="0.01"
                                                    value={line.estimated_unit_cost}
                                                    onChange={(e) => updateItemLine(idx, 'estimated_unit_cost', parseFloat(e.target.value) || 0)}
                                                    className="h-8 text-sm text-right"
                                                />
                                            </div>
                                            <div className="col-span-2 flex items-center justify-end gap-2">
                                                <span className="font-mono text-sm">
                                                    {(line.quantity_requested * line.estimated_unit_cost).toLocaleString()}
                                                </span>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="h-8 w-8 text-destructive"
                                                    onClick={() => removeItemLine(idx)}
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </div>
                                        </div>
                                    ))}

                                    <div className="flex justify-end pt-3 border-t text-sm font-semibold">
                                        <span>Total Estimated Budget: TZS {totalEstimate.toLocaleString('en-US', { minimumFractionDigits: 2 })}</span>
                                    </div>
                                </div>
                            )}
                            {errors.items && <p className="text-destructive text-xs mt-2">{errors.items}</p>}
                        </CardContent>
                    </Card>

                    <div className="flex justify-end gap-3">
                        <Button asChild variant="outline">
                            <Link href="/procurement/indents">Cancel</Link>
                        </Button>
                        <Button type="submit" disabled={processing || data.items.length === 0}>
                            {processing ? 'Saving...' : 'Save Requisition Indent'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
