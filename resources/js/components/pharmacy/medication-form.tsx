import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { medicationFormLabel, medicationRouteLabel } from '@/lib/medication-status';
import { type Medication, type MedicationForm as MedicationFormValue, type MedicationFormData, type MedicationRoute } from '@/types/medication';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface MedicationFormProps {
    medication?: Medication;
    isReferenced?: boolean;
    forms: MedicationFormValue[];
    routes: MedicationRoute[];
}

/**
 * Shared create/edit form (mirrors RadiologyProcedureForm/LaboratoryTestForm's
 * create/edit-sharing convention exactly). `code` is locked once the
 * medication has been referenced by a prescription — the backend is the
 * real authority; disabling the field here is only a UI convenience.
 */
export function MedicationForm({ medication, isReferenced, forms, routes }: MedicationFormProps) {
    const isEditing = Boolean(medication);

    const { data, setData, post, put, processing, errors } = useForm<MedicationFormData>({
        code: medication?.code ?? '',
        generic_name: medication?.generic_name ?? '',
        brand_name: medication?.brand_name ?? '',
        strength: medication?.strength ?? '',
        form: medication?.form ?? '',
        route: medication?.route ?? '',
        unit: medication?.unit ?? '',
        category: medication?.category ?? '',
        description: medication?.description ?? '',
        nhif_code: medication?.nhif_code ?? '',
        price: medication?.price ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (medication) {
            put(route('medications.update', medication.id));
        } else {
            post(route('medications.store'));
        }
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="code">Medication Code *</Label>
                    <Input
                        id="code"
                        value={data.code}
                        onChange={(e) => setData('code', e.target.value.toUpperCase())}
                        disabled={isReferenced}
                        required
                        autoFocus
                    />
                    {isReferenced && (
                        <p className="text-muted-foreground text-xs">Locked — this medication has already been referenced by a prescription.</p>
                    )}
                    <InputError message={errors.code} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="generic_name">Generic Name *</Label>
                    <Input id="generic_name" value={data.generic_name} onChange={(e) => setData('generic_name', e.target.value)} required />
                    <InputError message={errors.generic_name} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="brand_name">Brand Name</Label>
                    <Input id="brand_name" value={data.brand_name} onChange={(e) => setData('brand_name', e.target.value)} />
                    <InputError message={errors.brand_name} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="strength">Strength</Label>
                    <Input
                        id="strength"
                        placeholder="e.g. 500 mg"
                        value={data.strength}
                        onChange={(e) => setData('strength', e.target.value)}
                    />
                    <InputError message={errors.strength} />
                </div>

                <div className="grid gap-2">
                    <Label>Form</Label>
                    <Select value={data.form || 'none'} onValueChange={(value) => setData('form', value === 'none' ? '' : (value as MedicationFormValue))}>
                        <SelectTrigger>
                            <SelectValue placeholder="Not specified" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">Not specified</SelectItem>
                            {forms.map((form) => (
                                <SelectItem key={form} value={form}>
                                    {medicationFormLabel[form]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.form} />
                </div>

                <div className="grid gap-2">
                    <Label>Route</Label>
                    <Select value={data.route || 'none'} onValueChange={(value) => setData('route', value === 'none' ? '' : (value as MedicationRoute))}>
                        <SelectTrigger>
                            <SelectValue placeholder="Not specified" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">Not specified</SelectItem>
                            {routes.map((route) => (
                                <SelectItem key={route} value={route}>
                                    {medicationRouteLabel[route]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.route} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="unit">Dispensing Unit</Label>
                    <Input id="unit" placeholder="e.g. tablet, mL, vial" value={data.unit} onChange={(e) => setData('unit', e.target.value)} />
                    <InputError message={errors.unit} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="category">Category</Label>
                    <Input id="category" placeholder="e.g. Analgesic" value={data.category} onChange={(e) => setData('category', e.target.value)} />
                    <InputError message={errors.category} />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="description">Description</Label>
                <Textarea id="description" rows={2} value={data.description} onChange={(e) => setData('description', e.target.value)} />
                <InputError message={errors.description} />
            </div>

            <div className="space-y-4 rounded-md border p-4">
                <h3 className="text-sm font-medium">Billing / NHIF Mapping</h3>
                <p className="text-muted-foreground text-xs">
                    Preserved for a future billing/claims module — nothing in this system currently reads or acts on these values.
                </p>
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="nhif_code">NHIF Code</Label>
                        <Input id="nhif_code" value={data.nhif_code} onChange={(e) => setData('nhif_code', e.target.value)} />
                        <InputError message={errors.nhif_code} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="price">Price</Label>
                        <Input id="price" type="number" min={0} step="0.01" value={data.price} onChange={(e) => setData('price', e.target.value)} />
                        <InputError message={errors.price} />
                    </div>
                </div>
            </div>

            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" asChild>
                    <Link href={route('medications.index')}>Cancel</Link>
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    {isEditing ? 'Save changes' : 'Create medication'}
                </Button>
            </div>
        </form>
    );
}
