import { MedicationSearch } from '@/components/care-plan/medication-search';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type MedicationSearchResult } from '@/types/medication';
import { type ReceiveStockFormData, type StockHospital } from '@/types/stock';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pharmacy', href: '/pharmacy' },
    { title: 'Stock', href: '/pharmacy/stock' },
    { title: 'Receive Stock', href: '/pharmacy/stock/receive' },
];

interface PharmacyStockReceiveProps {
    hospitals: StockHospital[];
}

/**
 * Receive stock into a hospital's pharmacy inventory (Phase 6, Step 49, spec
 * §34). Reuses the existing medication search-json combobox (Step 44) rather
 * than a new endpoint. Backend re-validates everything submitted here
 * (StockService::receive()) — this form's own constraints (required expiry
 * in the future, quantity ≥ 1) are a convenience only.
 */
export default function PharmacyStockReceive({ hospitals }: PharmacyStockReceiveProps) {
    const [selectedMedication, setSelectedMedication] = useState<MedicationSearchResult | null>(null);

    const { data, setData, post, processing, errors } = useForm<ReceiveStockFormData>({
        hospital_id: hospitals.length === 1 ? String(hospitals[0].id) : '',
        medication_id: '',
        batch_number: '',
        manufacture_date: '',
        expiry_date: '',
        quantity: '',
        unit_cost: '',
        supplier_name: '',
        notes: '',
        reorder_level: '',
        maximum_level: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('pharmacy-stock.receive'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Receive Stock" />

            <div className="p-4">
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Receive Stock</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-6">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label>Location *</Label>
                                    <Select value={data.hospital_id} onValueChange={(value) => setData('hospital_id', value)}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select hospital" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {hospitals.map((hospital) => (
                                                <SelectItem key={hospital.id} value={String(hospital.id)}>
                                                    {hospital.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.hospital_id} />
                                </div>

                                <div className="grid gap-2">
                                    <Label>Medication *</Label>
                                    <MedicationSearch
                                        selected={selectedMedication}
                                        onSelect={(medication) => {
                                            setSelectedMedication(medication);
                                            setData('medication_id', String(medication.id));
                                        }}
                                    />
                                    <InputError message={errors.medication_id} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="batch_number">Batch Number *</Label>
                                    <Input
                                        id="batch_number"
                                        placeholder="Manufacturer's own lot number"
                                        value={data.batch_number}
                                        onChange={(e) => setData('batch_number', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.batch_number} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="quantity">Quantity *</Label>
                                    <Input
                                        id="quantity"
                                        type="number"
                                        min={1}
                                        value={data.quantity}
                                        onChange={(e) => setData('quantity', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.quantity} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="manufacture_date">Manufacture Date</Label>
                                    <Input
                                        id="manufacture_date"
                                        type="date"
                                        value={data.manufacture_date}
                                        onChange={(e) => setData('manufacture_date', e.target.value)}
                                    />
                                    <InputError message={errors.manufacture_date} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="expiry_date">Expiry Date *</Label>
                                    <Input
                                        id="expiry_date"
                                        type="date"
                                        value={data.expiry_date}
                                        onChange={(e) => setData('expiry_date', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.expiry_date} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="unit_cost">Unit Cost</Label>
                                    <Input
                                        id="unit_cost"
                                        type="number"
                                        min={0}
                                        step="0.01"
                                        value={data.unit_cost}
                                        onChange={(e) => setData('unit_cost', e.target.value)}
                                    />
                                    <InputError message={errors.unit_cost} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="supplier_name">Supplier</Label>
                                    <Input id="supplier_name" value={data.supplier_name} onChange={(e) => setData('supplier_name', e.target.value)} />
                                    <InputError message={errors.supplier_name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="reorder_level">Reorder Level</Label>
                                    <Input
                                        id="reorder_level"
                                        type="number"
                                        min={0}
                                        placeholder="Leave blank to keep current"
                                        value={data.reorder_level}
                                        onChange={(e) => setData('reorder_level', e.target.value)}
                                    />
                                    <InputError message={errors.reorder_level} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="maximum_level">Maximum Level</Label>
                                    <Input
                                        id="maximum_level"
                                        type="number"
                                        min={0}
                                        placeholder="Leave blank to keep current"
                                        value={data.maximum_level}
                                        onChange={(e) => setData('maximum_level', e.target.value)}
                                    />
                                    <InputError message={errors.maximum_level} />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="notes">Notes</Label>
                                <Textarea id="notes" rows={2} value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                                <InputError message={errors.notes} />
                            </div>

                            <div className="flex justify-end gap-2">
                                <Button type="button" variant="outline" asChild>
                                    <Link href={route('pharmacy-stock.items.index')}>Cancel</Link>
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                    Receive Stock
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
