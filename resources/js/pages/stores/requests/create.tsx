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
    { title: 'Store & Inventory', href: '/stores' },
    { title: 'Department Requests', href: '/stores/requests' },
    { title: 'New Request', href: '/stores/requests/create' },
];

interface ProcurementItem {
    id: number;
    code: string;
    name: string;
    unit_of_measure: string;
}

interface Props {
    departments: { id: number; name: string }[];
    stores: { id: number; name: string }[];
    items: ProcurementItem[];
}

interface RequestLine {
    [key: string]: any;
    procurement_item_id: number;
    item_name: string;
    unit_of_measure: string;
    quantity_requested: number;
    purpose: string;
}

interface StockRequestForm {
    [key: string]: any;
    department_id: string | number;
    from_store_id: string | number;
    request_date: string;
    required_by_date: string;
    priority: string;
    notes: string;
    items: RequestLine[];
}

export default function CreateStockRequest({ departments, stores, items }: Props) {
    const { data, setData, post, processing, errors } = useForm<StockRequestForm>({
        department_id: departments[0]?.id || '',
        from_store_id: stores[0]?.id || '',
        request_date: new Date().toISOString().split('T')[0],
        required_by_date: '',
        priority: 'normal',
        notes: '',
        items: [] as RequestLine[],
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
                purpose: '',
            },
        ]);
        setSelectedItemId('');
    };

    const removeItemLine = (index: number) => {
        const next = [...data.items];
        next.splice(index, 1);
        setData('items', next);
    };

    const updateLine = (index: number, field: keyof RequestLine, value: any) => {
        const next = [...data.items];
        next[index] = { ...next[index], [field]: value };
        setData('items', next);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/stores/requests');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Request Stock - Store" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <PageHeader
                        title="New Department Stock Request"
                        description="Requisition medicines and medical supplies from main stores for clinical consumption."
                    />
                    <Link href="/stores/requests">
                        <Button variant="outline" size="sm">
                            <ArrowLeft className="mr-2 h-4 w-4" /> Cancel & Return
                        </Button>
                    </Link>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base font-semibold">Requesting Department & Target Store</CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div className="space-y-1">
                                <Label htmlFor="department_id">Requesting Department *</Label>
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
                                <Label htmlFor="from_store_id">Source Store *</Label>
                                <select
                                    id="from_store_id"
                                    value={data.from_store_id}
                                    onChange={(e) => setData('from_store_id', Number(e.target.value))}
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
                                <Label htmlFor="priority">Priority *</Label>
                                <select
                                    id="priority"
                                    value={data.priority}
                                    onChange={(e) => setData('priority', e.target.value)}
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                    required
                                >
                                    <option value="low">Low</option>
                                    <option value="normal">Normal</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="request_date">Request Date *</Label>
                                <Input
                                    id="request_date"
                                    type="date"
                                    required
                                    value={data.request_date}
                                    onChange={(e) => setData('request_date', e.target.value)}
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="required_by_date">Required By Date</Label>
                                <Input
                                    id="required_by_date"
                                    type="date"
                                    value={data.required_by_date}
                                    onChange={(e) => setData('required_by_date', e.target.value)}
                                />
                            </div>

                            <div className="space-y-1 md:col-span-3">
                                <Label htmlFor="notes">Clinical Justification & Ward Notes</Label>
                                <Textarea
                                    id="notes"
                                    rows={2}
                                    placeholder="Explain why items are needed (e.g. ward daily refill, emergency surgery stock)..."
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Requested Items */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-3">
                            <CardTitle className="text-base font-semibold">Requested Items ({data.items.length})</CardTitle>
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
                                    No items added yet. Select an item from the dropdown to add.
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-sm">
                                        <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                            <tr>
                                                <th className="px-3 py-2">Item</th>
                                                <th className="px-3 py-2 w-28">Qty Requested</th>
                                                <th className="px-3 py-2 w-20">UOM</th>
                                                <th className="px-3 py-2">Purpose / Ward Note</th>
                                                <th className="px-3 py-2 text-right">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {data.items.map((line, idx) => (
                                                <tr key={idx}>
                                                    <td className="px-3 py-2 font-medium">{line.item_name}</td>
                                                    <td className="px-3 py-2">
                                                        <Input
                                                            type="number"
                                                            min="1"
                                                            value={line.quantity_requested}
                                                            onChange={(e) => updateLine(idx, 'quantity_requested', parseInt(e.target.value) || 0)}
                                                            className="h-8 w-28"
                                                            required
                                                        />
                                                    </td>
                                                    <td className="px-3 py-2 text-muted-foreground text-xs">{line.unit_of_measure}</td>
                                                    <td className="px-3 py-2">
                                                        <Input
                                                            value={line.purpose}
                                                            placeholder="e.g. Bed 1-12 refill"
                                                            onChange={(e) => updateLine(idx, 'purpose', e.target.value)}
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
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <div className="flex items-center justify-end gap-3">
                        <Link href="/stores/requests">
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing || data.items.length === 0}>
                            {processing ? 'Submitting...' : 'Submit Request'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
