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

interface ProcurementItem {
    id: number;
    code: string;
    name: string;
    category: string;
    unit_of_measure: string;
    estimated_unit_cost: number | null;
}

interface IndentItemData {
    id?: number;
    procurement_item_id: number;
    quantity_requested: number;
    estimated_unit_cost: number;
    purpose?: string;
    specification?: string;
    procurement_item?: ProcurementItem;
}

interface IndentData {
    id: number;
    indent_number: string;
    department_id: number;
    target_store_id: number;
    indent_date: string;
    required_by_date: string | null;
    priority: string;
    notes: string | null;
    items: IndentItemData[];
}

interface Props {
    indent: IndentData;
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

interface IndentEditForm {
    [key: string]: any;
    department_id: string | number;
    target_store_id: string | number;
    indent_date: string;
    required_by_date: string;
    priority: string;
    notes: string;
    items: ItemLine[];
}

export default function EditIndent({ indent, departments, stores, items }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Procurement', href: '/procurement' },
        { title: 'Purchase Indents', href: '/procurement/indents' },
        { title: indent.indent_number, href: `/procurement/indents/${indent.id}` },
        { title: 'Edit', href: `/procurement/indents/${indent.id}/edit` },
    ];

    const initialItems: ItemLine[] = (indent.items || []).map((it) => ({
        procurement_item_id: it.procurement_item_id,
        item_name: it.procurement_item?.name || 'Item',
        unit_of_measure: it.procurement_item?.unit_of_measure || 'units',
        quantity_requested: Number(it.quantity_requested) || 1,
        estimated_unit_cost: Number(it.estimated_unit_cost) || 0,
        purpose: it.purpose || '',
        specification: it.specification || '',
    }));

    const { data, setData, put, processing, errors } = useForm<IndentEditForm>({
        department_id: indent.department_id || departments[0]?.id || '',
        target_store_id: indent.target_store_id || stores[0]?.id || '',
        indent_date: indent.indent_date?.split('T')[0] || new Date().toISOString().split('T')[0],
        required_by_date: indent.required_by_date ? indent.required_by_date.split('T')[0] : '',
        priority: indent.priority || 'normal',
        notes: indent.notes || '',
        items: initialItems,
    });

    const [selectedItemId, setSelectedItemId] = useState<string>('');

    const addItemLine = (item: ProcurementItem) => {
        setData('items', [
            ...data.items,
            {
                procurement_item_id: item.id,
                item_name: `${item.name} (${item.code})`,
                unit_of_measure: item.unit_of_measure,
                quantity_requested: 1,
                estimated_unit_cost: Number(item.estimated_unit_cost) || 0,
                purpose: '',
                specification: '',
            },
        ]);
        setSelectedItemId('');
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
        put(`/procurement/indents/${indent.id}`);
    };

    const totalEstimated = data.items.reduce((sum, it) => sum + (Number(it.quantity_requested) * Number(it.estimated_unit_cost) || 0), 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit Indent ${indent.indent_number}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <PageHeader
                        title={`Edit Requisition: ${indent.indent_number}`}
                        description="Modify department indent requisition details and line items before submitting."
                    />
                    <Link href={`/procurement/indents/${indent.id}`}>
                        <Button variant="outline" size="sm">
                            <ArrowLeft className="mr-2 h-4 w-4" /> Cancel & Return
                        </Button>
                    </Link>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base font-semibold">Requisition Details</CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div className="space-y-1">
                                <Label htmlFor="department_id">Department *</Label>
                                <select
                                    id="department_id"
                                    value={data.department_id}
                                    onChange={(e) => setData('department_id', Number(e.target.value))}
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                                    required
                                >
                                    {departments.map((d) => (
                                        <option key={d.id} value={d.id}>
                                            {d.name}
                                        </option>
                                    ))}
                                </select>
                                {errors.department_id && <p className="text-xs text-red-500">{errors.department_id}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="target_store_id">Target Receiving Store *</Label>
                                <select
                                    id="target_store_id"
                                    value={data.target_store_id}
                                    onChange={(e) => setData('target_store_id', Number(e.target.value))}
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                                    required
                                >
                                    {stores.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name} ({s.type})
                                        </option>
                                    ))}
                                </select>
                                {errors.target_store_id && <p className="text-xs text-red-500">{errors.target_store_id}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="priority">Priority *</Label>
                                <select
                                    id="priority"
                                    value={data.priority}
                                    onChange={(e) => setData('priority', e.target.value)}
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                                    required
                                >
                                    <option value="low">Low</option>
                                    <option value="normal">Normal</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                                {errors.priority && <p className="text-xs text-red-500">{errors.priority}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="indent_date">Indent Date *</Label>
                                <Input
                                    id="indent_date"
                                    type="date"
                                    value={data.indent_date}
                                    onChange={(e) => setData('indent_date', e.target.value)}
                                    required
                                />
                                {errors.indent_date && <p className="text-xs text-red-500">{errors.indent_date}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="required_by_date">Required By Date</Label>
                                <Input
                                    id="required_by_date"
                                    type="date"
                                    value={data.required_by_date}
                                    onChange={(e) => setData('required_by_date', e.target.value)}
                                />
                                {errors.required_by_date && <p className="text-xs text-red-500">{errors.required_by_date}</p>}
                            </div>

                            <div className="space-y-1 md:col-span-3">
                                <Label htmlFor="notes">Clinical / Operational Justification & Notes</Label>
                                <Textarea
                                    id="notes"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    placeholder="Provide department justification or urgency notes..."
                                    rows={2}
                                />
                                {errors.notes && <p className="text-xs text-red-500">{errors.notes}</p>}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Line Items */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-3">
                            <CardTitle className="text-base font-semibold">Requested Items ({data.items.length})</CardTitle>
                            <div className="flex items-center gap-2">
                                <select
                                    value={selectedItemId}
                                    onChange={(e) => setSelectedItemId(e.target.value)}
                                    className="rounded-md border border-input bg-background px-3 py-1.5 text-sm"
                                >
                                    <option value="">-- Choose item to add --</option>
                                    {items.map((it) => (
                                        <option key={it.id} value={it.id}>
                                            {it.name} ({it.code}) - {it.category}
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
                                    No items added to this indent yet. Select an item above to add.
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-sm">
                                        <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                            <tr>
                                                <th className="px-3 py-2">Item</th>
                                                <th className="px-3 py-2">UOM</th>
                                                <th className="px-3 py-2 w-28">Qty Requested</th>
                                                <th className="px-3 py-2 w-32">Est. Unit Cost (TZS)</th>
                                                <th className="px-3 py-2 w-32">Est. Total</th>
                                                <th className="px-3 py-2">Purpose / Spec</th>
                                                <th className="px-3 py-2 text-right">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {data.items.map((line, idx) => {
                                                const lineTotal = Number(line.quantity_requested) * Number(line.estimated_unit_cost);
                                                return (
                                                    <tr key={idx}>
                                                        <td className="px-3 py-2 font-medium">{line.item_name}</td>
                                                        <td className="px-3 py-2 text-muted-foreground">{line.unit_of_measure}</td>
                                                        <td className="px-3 py-2">
                                                            <Input
                                                                type="number"
                                                                min="0.01"
                                                                step="any"
                                                                value={line.quantity_requested}
                                                                onChange={(e) => updateItemLine(idx, 'quantity_requested', parseFloat(e.target.value) || 0)}
                                                                className="h-8 w-24"
                                                                required
                                                            />
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            <Input
                                                                type="number"
                                                                min="0"
                                                                step="any"
                                                                value={line.estimated_unit_cost}
                                                                onChange={(e) => updateItemLine(idx, 'estimated_unit_cost', parseFloat(e.target.value) || 0)}
                                                                className="h-8 w-28"
                                                            />
                                                        </td>
                                                        <td className="px-3 py-2 font-semibold">
                                                            TZS {lineTotal.toLocaleString()}
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            <Input
                                                                placeholder="e.g. ICU emergency refill"
                                                                value={line.purpose}
                                                                onChange={(e) => updateItemLine(idx, 'purpose', e.target.value)}
                                                                className="h-8 text-xs"
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
                                            <tr className="bg-muted/50 font-semibold">
                                                <td colSpan={4} className="px-3 py-3 text-right">
                                                    Estimated Total Requisition:
                                                </td>
                                                <td colSpan={3} className="px-3 py-3 text-primary text-base">
                                                    TZS {totalEstimated.toLocaleString()}
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <div className="flex items-center justify-end gap-3">
                        <Link href={`/procurement/indents/${indent.id}`}>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing || data.items.length === 0}>
                            {processing ? 'Saving changes...' : 'Save Changes'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
