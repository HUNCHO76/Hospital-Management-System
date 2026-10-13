import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, Send, Trash2 } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Store & Inventory', href: '/stores' },
    { title: 'Stock Issues', href: '/stores/issues' },
    { title: 'Issue Stock', href: '/stores/issues/create' },
];

interface ProcurementItem {
    id: number;
    code: string;
    name: string;
    unit_of_measure: string;
}

interface RequestItem {
    id: number;
    procurement_item_id: number;
    quantity_approved: number;
    quantity_issued: number;
    procurement_item?: ProcurementItem;
}

interface StockRequest {
    id: number;
    request_number: string;
    department_id: number;
    from_store_id: number;
    department?: { id: number; name: string };
    items: RequestItem[];
}

interface Props {
    stock_requests: StockRequest[];
    selected_request: StockRequest | null;
    stores: { id: number; name: string }[];
    departments: { id: number; name: string }[];
    items: ProcurementItem[];
}

interface IssueLineItem {
    [key: string]: any;
    procurement_item_id: number;
    item_name: string;
    unit_of_measure: string;
    quantity_issued: number;
    notes: string;
}

interface IssueForm {
    [key: string]: any;
    department_stock_request_id: string | number;
    from_store_id: string | number;
    department_id: string | number;
    issue_date: string;
    notes: string;
    items: IssueLineItem[];
}

export default function CreateStoreIssue({ stock_requests, selected_request, stores, departments, items }: Props) {
    const defaultLines: IssueLineItem[] = selected_request?.items
        ? selected_request.items.map((it) => {
              const pendingQty = Math.max(0, Number(it.quantity_approved) - Number(it.quantity_issued));
              return {
                  procurement_item_id: it.procurement_item_id,
                  item_name: it.procurement_item ? `${it.procurement_item.name} (${it.procurement_item.code})` : 'Item',
                  unit_of_measure: it.procurement_item?.unit_of_measure || 'units',
                  quantity_issued: pendingQty || 1,
                  notes: '',
              };
          })
        : [];

    const { data, setData, post, processing, errors } = useForm<IssueForm>({
        department_stock_request_id: selected_request?.id || '',
        from_store_id: selected_request?.from_store_id || stores[0]?.id || '',
        department_id: selected_request?.department_id || departments[0]?.id || '',
        issue_date: new Date().toISOString().split('T')[0],
        notes: '',
        items: defaultLines,
    });

    const [selectedItemId, setSelectedItemId] = useState<string>('');

    const handleRequestChange = (reqIdStr: string) => {
        setData('department_stock_request_id', reqIdStr ? Number(reqIdStr) : '');
        if (reqIdStr) {
            const found = stock_requests.find((r) => r.id === Number(reqIdStr));
            if (found) {
                setData((prev) => ({
                    ...prev,
                    department_stock_request_id: found.id,
                    from_store_id: found.from_store_id || prev.from_store_id,
                    department_id: found.department_id || prev.department_id,
                    items: (found.items || []).map((it) => {
                        const pendingQty = Math.max(0, Number(it.quantity_approved) - Number(it.quantity_issued));
                        return {
                            procurement_item_id: it.procurement_item_id,
                            item_name: it.procurement_item ? `${it.procurement_item.name} (${it.procurement_item.code})` : 'Item',
                            unit_of_measure: it.procurement_item?.unit_of_measure || 'units',
                            quantity_issued: pendingQty || 1,
                            notes: '',
                        };
                    }),
                }));
            }
        }
    };

    const addItemLine = (item: ProcurementItem) => {
        setData('items', [
            ...data.items,
            {
                procurement_item_id: item.id,
                item_name: `${item.name} (${item.code})`,
                unit_of_measure: item.unit_of_measure,
                quantity_issued: 1,
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

    const updateLine = (index: number, field: keyof IssueLineItem, value: any) => {
        const next = [...data.items];
        next[index] = { ...next[index], [field]: value };
        setData('items', next);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/stores/issues');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Issue Stock to Department - Store" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <PageHeader
                        title="Issue Stock to Department"
                        description="Dispatch inventory to ward or theater. The system automatically allocates available stock using First-Expiry-First-Out (FEFO) rules."
                    />
                    <Link href="/stores/issues">
                        <Button variant="outline" size="sm">
                            <ArrowLeft className="mr-2 h-4 w-4" /> Cancel & Return
                        </Button>
                    </Link>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base font-semibold">Source Store & Receiving Department</CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div className="space-y-1">
                                <Label htmlFor="department_stock_request_id">Linked Department Requisition</Label>
                                <select
                                    id="department_stock_request_id"
                                    value={data.department_stock_request_id}
                                    onChange={(e) => handleRequestChange(e.target.value)}
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                                >
                                    <option value="">-- Direct Store Issue (No Request) --</option>
                                    {stock_requests.map((r) => (
                                        <option key={r.id} value={r.id}>
                                            {r.request_number} - {r.department?.name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="from_store_id">Issuing Store *</Label>
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
                                <Label htmlFor="department_id">Receiving Department / Ward *</Label>
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
                                <Label htmlFor="issue_date">Issue Date *</Label>
                                <Input
                                    id="issue_date"
                                    type="date"
                                    required
                                    value={data.issue_date}
                                    onChange={(e) => setData('issue_date', e.target.value)}
                                />
                            </div>

                            <div className="space-y-1 md:col-span-2">
                                <Label htmlFor="notes">Dispatch Notes</Label>
                                <Input
                                    id="notes"
                                    placeholder="e.g. Delivered by Nurse John to ICU storage room..."
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Issue Line Items */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-3">
                            <CardTitle className="text-base font-semibold">Stock Items to Issue ({data.items.length})</CardTitle>
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
                                    No items to issue. Select a department request or add catalog items above.
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-sm">
                                        <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                            <tr>
                                                <th className="px-3 py-2">Item Description</th>
                                                <th className="px-3 py-2 w-28">Qty to Issue</th>
                                                <th className="px-3 py-2 w-20">UOM</th>
                                                <th className="px-3 py-2">Line Notes</th>
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
                                                            value={line.quantity_issued}
                                                            onChange={(e) => updateLine(idx, 'quantity_issued', parseInt(e.target.value) || 0)}
                                                            className="h-8 w-28"
                                                            required
                                                        />
                                                    </td>
                                                    <td className="px-3 py-2 text-muted-foreground text-xs">{line.unit_of_measure}</td>
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
                        <Link href="/stores/issues">
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing || data.items.length === 0}>
                            <Send className="mr-2 h-4 w-4" />
                            {processing ? 'Allocating & Issuing (FEFO)...' : 'Allocate (FEFO) & Issue Stock'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
