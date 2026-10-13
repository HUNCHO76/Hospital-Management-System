import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';

interface Medication {
    id: number;
    generic_name: string;
    brand_name: string | null;
    form: string;
}

interface ItemData {
    id: number;
    code: string;
    name: string;
    category: string;
    medication_id: number | null;
    unit_of_measure: string;
    specification: string | null;
    reorder_level: number | null;
    minimum_stock: number | null;
    estimated_unit_cost: number | null;
    status: string;
}

interface Props {
    item: ItemData;
    categories: string[];
    medications: Medication[];
}

export default function EditProcurementItem({ item, categories, medications }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Procurement', href: '/procurement' },
        { title: 'Catalog Items', href: '/procurement/items' },
        { title: item.name, href: `/procurement/items` },
        { title: 'Edit', href: `/procurement/items/${item.id}/edit` },
    ];

    const { data, setData, put, processing, errors } = useForm({
        code: item.code || '',
        name: item.name || '',
        category: item.category || categories[0],
        medication_id: item.medication_id || '',
        unit_of_measure: item.unit_of_measure || 'units',
        specification: item.specification || '',
        reorder_level: item.reorder_level ?? 10,
        minimum_stock: item.minimum_stock ?? 5,
        estimated_unit_cost: Number(item.estimated_unit_cost) || 0,
        status: item.status || 'active',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/procurement/items/${item.id}`);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit Item ${item.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <PageHeader
                        title={`Edit Item: ${item.name}`}
                        description="Update catalog specifications, reorder safety buffers, or unit costs."
                    />
                    <Link href="/procurement/items">
                        <Button variant="outline" size="sm">
                            <ArrowLeft className="mr-2 h-4 w-4" /> Cancel & Return
                        </Button>
                    </Link>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base font-semibold">General Item Definition</CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div className="space-y-1">
                                <Label htmlFor="code">Item Code *</Label>
                                <Input
                                    id="code"
                                    required
                                    value={data.code}
                                    onChange={(e) => setData('code', e.target.value)}
                                />
                                {errors.code && <p className="text-xs text-red-500">{errors.code}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="category">Category *</Label>
                                <select
                                    id="category"
                                    value={data.category}
                                    onChange={(e) => setData('category', e.target.value)}
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm capitalize"
                                    required
                                >
                                    {categories.map((c) => (
                                        <option key={c} value={c}>
                                            {c.replace('_', ' ')}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {data.category === 'medicines' && (
                                <div className="space-y-1">
                                    <Label htmlFor="medication_id">Linked Medication</Label>
                                    <select
                                        id="medication_id"
                                        value={data.medication_id}
                                        onChange={(e) => setData('medication_id', e.target.value ? Number(e.target.value) : '')}
                                        className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                    >
                                        <option value="">-- Standalone Item --</option>
                                        {medications.map((m) => (
                                            <option key={m.id} value={m.id}>
                                                {m.generic_name} ({m.form})
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}

                            <div className="space-y-1 md:col-span-2">
                                <Label htmlFor="name">Item Name *</Label>
                                <Input
                                    id="name"
                                    required
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                />
                                {errors.name && <p className="text-xs text-red-500">{errors.name}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="unit_of_measure">Unit of Measure (UOM) *</Label>
                                <Input
                                    id="unit_of_measure"
                                    required
                                    value={data.unit_of_measure}
                                    onChange={(e) => setData('unit_of_measure', e.target.value)}
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="estimated_unit_cost">Est. Unit Cost (TZS)</Label>
                                <Input
                                    id="estimated_unit_cost"
                                    type="number"
                                    min="0"
                                    step="any"
                                    value={data.estimated_unit_cost}
                                    onChange={(e) => setData('estimated_unit_cost', parseFloat(e.target.value) || 0)}
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="reorder_level">Reorder Threshold Level</Label>
                                <Input
                                    id="reorder_level"
                                    type="number"
                                    min="0"
                                    value={data.reorder_level}
                                    onChange={(e) => setData('reorder_level', parseInt(e.target.value) || 0)}
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="minimum_stock">Safety Minimum Buffer</Label>
                                <Input
                                    id="minimum_stock"
                                    type="number"
                                    min="0"
                                    value={data.minimum_stock}
                                    onChange={(e) => setData('minimum_stock', parseInt(e.target.value) || 0)}
                                />
                            </div>

                            <div className="space-y-1 md:col-span-3">
                                <Label htmlFor="specification">Technical Specification</Label>
                                <Textarea
                                    id="specification"
                                    rows={2}
                                    value={data.specification}
                                    onChange={(e) => setData('specification', e.target.value)}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex items-center justify-end gap-3">
                        <Link href="/procurement/items">
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Save Changes'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
