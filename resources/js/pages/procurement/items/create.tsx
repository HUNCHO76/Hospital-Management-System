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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Procurement', href: '/procurement' },
    { title: 'Catalog Items', href: '/procurement/items' },
    { title: 'New Item', href: '/procurement/items/create' },
];

interface Medication {
    id: number;
    generic_name: string;
    brand_name: string | null;
    form: string;
}

interface Props {
    categories: string[];
    medications: Medication[];
}

export default function CreateProcurementItem({ categories, medications }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        code: `ITM-${Math.floor(10000 + Math.random() * 90000)}`,
        name: '',
        category: categories[0] || 'medicines',
        medication_id: '',
        unit_of_measure: 'pieces',
        specification: '',
        reorder_level: 10,
        minimum_stock: 5,
        estimated_unit_cost: 0,
        requires_batch: true,
        requires_expiry: true,
        status: 'active',
    });

    const handleMedicationSelect = (medIdStr: string) => {
        setData('medication_id', medIdStr ? String(medIdStr) : '');
        if (medIdStr) {
            const med = medications.find((m) => m.id === Number(medIdStr));
            if (med) {
                setData((prev) => ({
                    ...prev,
                    medication_id: String(med.id),
                    name: `${med.generic_name} ${med.brand_name ? `(${med.brand_name})` : ''} - ${med.form}`,
                    category: 'medicines',
                    requires_batch: true,
                    requires_expiry: true,
                }));
            }
        }
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/procurement/items');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create Catalog Item - Procurement" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <PageHeader
                        title="Add Master Catalog Item"
                        description="Define item parameters, stock thresholds, unit of measurement, and medication links."
                    />
                    <Link href="/procurement/items">
                        <Button variant="outline" size="sm">
                            <ArrowLeft className="mr-2 h-4 w-4" /> Cancel
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
                                    <Label htmlFor="medication_id">Link Pharmacy Medication (Optional)</Label>
                                    <select
                                        id="medication_id"
                                        value={data.medication_id}
                                        onChange={(e) => handleMedicationSelect(e.target.value)}
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
                                    placeholder="e.g. Paracetamol 500mg Tablets"
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
                                    placeholder="e.g. box, vial, roll, pack, bottle"
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
                                <Label htmlFor="minimum_stock">Critical Minimum Stock Safety Buffer</Label>
                                <Input
                                    id="minimum_stock"
                                    type="number"
                                    min="0"
                                    value={data.minimum_stock}
                                    onChange={(e) => setData('minimum_stock', parseInt(e.target.value) || 0)}
                                />
                            </div>

                            <div className="space-y-1 md:col-span-3">
                                <Label htmlFor="specification">Technical Specification / Description</Label>
                                <Textarea
                                    id="specification"
                                    rows={2}
                                    placeholder="Manufacturer standards, storage instructions, composition..."
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
                            {processing ? 'Saving...' : 'Create Item'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
