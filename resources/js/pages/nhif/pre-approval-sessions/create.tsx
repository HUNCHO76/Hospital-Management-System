import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { FormEvent } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NHIF', href: '/nhif/dashboard' },
    { title: 'Pre-Approval Sessions', href: '#' },
    { title: 'New', href: '#' },
];

interface ItemRow {
    source_type: string;
    source_id: string;
    nhif_item_code: string;
    nhif_item_name: string;
    quantity: number;
    unit_price: number;
}

interface Patient {
    id: number;
    first_name: string;
    last_name: string;
}

interface Insurance {
    id: number;
    nhif_authorization_no: string | null;
}

interface Props {
    patient: Patient;
    insurance: Insurance;
    has_authorization_no: boolean;
    authorization_no: string | null;
}

export default function Create({ patient, insurance, has_authorization_no, authorization_no }: Props) {
    const { data, setData, post, processing, errors } = useForm<{ items: ItemRow[] }>({
        items: [
            {
                source_type: 'consultation',
                source_id: '',
                nhif_item_code: '',
                nhif_item_name: 'Consultation Fee',
                quantity: 1,
                unit_price: 0,
            },
        ],
    });

    function addItem() {
        setData('items', [
            ...data.items,
            { source_type: 'lab', source_id: '', nhif_item_code: '', nhif_item_name: '', quantity: 1, unit_price: 0 },
        ]);
    }

    function removeItem(index: number) {
        setData('items', data.items.filter((_, i) => i !== index));
    }

    function updateItem(index: number, field: keyof ItemRow, value: string | number) {
        setData('items', data.items.map((item, i) =>
            i === index ? { ...item, [field]: value } : item,
        ));
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        post(route('patients.insurance.nhif.pre-approval-sessions.store', [patient.id, insurance.id]));
    }

    const itemError = (i: number, field: string) =>
        (errors as Record<string, string>)[`items.${i}.${field}`];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New NHIF Pre-Approval Session" />

            <div className="p-6 max-w-3xl space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold">New Pre-Approval Session</h1>
                    <p className="text-muted-foreground mt-1">{patient.first_name} {patient.last_name}</p>
                </div>

                {!has_authorization_no && (
                    <div className="bg-yellow-50 border border-yellow-300 rounded p-4 text-yellow-800 text-sm">
                        Patient has not been verified with NHIF. Please verify the patient first to obtain an authorization number before creating a pre-approval session.
                    </div>
                )}

                {has_authorization_no && (
                    <div className="bg-green-50 border border-green-200 rounded p-3 text-sm text-green-800">
                        NHIF Authorization No: <strong className="font-mono">{authorization_no}</strong>
                    </div>
                )}

                <form onSubmit={submit} className="space-y-6">
                    <div>
                        <div className="flex items-center justify-between mb-3">
                            <h2 className="text-lg font-medium">Service Items</h2>
                            <Button type="button" variant="outline" size="sm" onClick={addItem}>
                                + Add Item
                            </Button>
                        </div>

                        {data.items.map((item, i) => (
                            <div key={i} className="border rounded-lg p-4 mb-3 space-y-3 bg-muted/20">
                                <div className="flex justify-between items-center">
                                    <span className="font-medium text-sm">Item {i + 1}</span>
                                    {data.items.length > 1 && (
                                        <Button type="button" variant="ghost" size="sm" onClick={() => removeItem(i)}
                                            className="text-destructive text-xs h-auto p-1">
                                            Remove
                                        </Button>
                                    )}
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <Label className="text-xs">Service Type</Label>
                                        <Select value={item.source_type}
                                            onValueChange={v => updateItem(i, 'source_type', v)}>
                                            <SelectTrigger className="mt-1">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="consultation">Consultation</SelectItem>
                                                <SelectItem value="lab">Laboratory</SelectItem>
                                                <SelectItem value="medication">Medication</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div>
                                        <Label className="text-xs">NHIF Item Code *</Label>
                                        <Input
                                            className="mt-1"
                                            value={item.nhif_item_code}
                                            onChange={e => updateItem(i, 'nhif_item_code', e.target.value)}
                                            placeholder="e.g. NHS001"
                                        />
                                        {itemError(i, 'nhif_item_code') && (
                                            <p className="text-destructive text-xs mt-1">{itemError(i, 'nhif_item_code')}</p>
                                        )}
                                    </div>

                                    <div className="col-span-2">
                                        <Label className="text-xs">Item Name</Label>
                                        <Input
                                            className="mt-1"
                                            value={item.nhif_item_name}
                                            onChange={e => updateItem(i, 'nhif_item_name', e.target.value)}
                                            placeholder="Service description"
                                        />
                                    </div>

                                    <div>
                                        <Label className="text-xs">Quantity *</Label>
                                        <Input
                                            type="number"
                                            min={1}
                                            className="mt-1"
                                            value={item.quantity}
                                            onChange={e => updateItem(i, 'quantity', parseInt(e.target.value, 10))}
                                        />
                                        {itemError(i, 'quantity') && (
                                            <p className="text-destructive text-xs mt-1">{itemError(i, 'quantity')}</p>
                                        )}
                                    </div>

                                    <div>
                                        <Label className="text-xs">Unit Price (TZS) *</Label>
                                        <Input
                                            type="number"
                                            min={0}
                                            step="0.01"
                                            className="mt-1"
                                            value={item.unit_price}
                                            onChange={e => updateItem(i, 'unit_price', parseFloat(e.target.value))}
                                        />
                                        {itemError(i, 'unit_price') && (
                                            <p className="text-destructive text-xs mt-1">{itemError(i, 'unit_price')}</p>
                                        )}
                                    </div>
                                </div>
                            </div>
                        ))}

                        {errors.items && (
                            <p className="text-destructive text-sm mt-1">{errors.items as unknown as string}</p>
                        )}
                    </div>

                    <div className="flex gap-3">
                        <Button type="submit" disabled={processing || !has_authorization_no}>
                            Create Session
                        </Button>
                        <Button type="button" variant="outline" asChild>
                            <a href={route('patients.show', patient.id)}>Cancel</a>
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
