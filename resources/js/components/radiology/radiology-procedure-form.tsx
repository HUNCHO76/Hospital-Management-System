import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    radiologyBodySiteLabel,
    radiologyContrastRequirementLabel,
    radiologyContrastTypeLabel,
    radiologyLateralityLabel,
    radiologyModalityLabel,
    radiologyPreparationTypeLabel,
} from '@/lib/radiology-status';
import {
    type RadiologyBodySite,
    type RadiologyContrastRequirement,
    type RadiologyContrastType,
    type RadiologyLaterality,
    type RadiologyModality,
    type RadiologyPreparationType,
    type RadiologyProcedure,
    type RadiologyProcedureDepartment,
    type RadiologyProcedureFormData,
} from '@/types/radiology';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface RadiologyProcedureFormProps {
    procedure?: RadiologyProcedure;
    isReferenced?: boolean;
    departments: RadiologyProcedureDepartment[];
    modalities: RadiologyModality[];
    bodySites: RadiologyBodySite[];
    lateralityOptions: RadiologyLaterality[];
    contrastRequirements: RadiologyContrastRequirement[];
    contrastTypes: RadiologyContrastType[];
    preparationTypes: RadiologyPreparationType[];
}

/**
 * Shared create/edit form (mirrors LaboratoryTestForm's create/edit-sharing
 * convention exactly). `code` is locked once the procedure has been
 * referenced by a radiology order — the backend is the real authority;
 * disabling the field here is only a UI convenience.
 */
export function RadiologyProcedureForm({
    procedure,
    isReferenced,
    departments,
    modalities,
    bodySites,
    lateralityOptions,
    contrastRequirements,
    contrastTypes,
    preparationTypes,
}: RadiologyProcedureFormProps) {
    const isEditing = Boolean(procedure);

    const { data, setData, post, put, processing, errors } = useForm<RadiologyProcedureFormData>({
        code: procedure?.code ?? '',
        name: procedure?.name ?? '',
        description: procedure?.description ?? '',
        modality: procedure?.modality ?? '',
        body_sites: procedure?.body_sites ?? [],
        laterality: procedure?.laterality ?? 'not_applicable',
        contrast_requirement: procedure?.contrast_requirement ?? 'no_contrast',
        contrast_type: procedure?.contrast_type ?? '',
        preparation_type: procedure?.preparation_type ?? 'none',
        preparation_instructions: procedure?.preparation_instructions ?? '',
        turnaround_time_minutes: procedure?.turnaround_time_minutes ? String(procedure.turnaround_time_minutes) : '',
        sort_order: procedure ? String(procedure.sort_order) : '0',
        department_id: procedure?.department ? String(procedure.department.id) : '',
        nhif_code: procedure?.nhif_code ?? '',
        price: procedure?.price ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (procedure) {
            put(route('radiology-catalog.update', procedure.id));
        } else {
            post(route('radiology-catalog.store'));
        }
    };

    const toggleBodySite = (site: RadiologyBodySite) => {
        setData('body_sites', data.body_sites.includes(site) ? data.body_sites.filter((s) => s !== site) : [...data.body_sites, site]);
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="code">Procedure Code *</Label>
                    <Input
                        id="code"
                        value={data.code}
                        onChange={(e) => setData('code', e.target.value.toUpperCase())}
                        disabled={isReferenced}
                        required
                        autoFocus
                    />
                    {isReferenced && (
                        <p className="text-muted-foreground text-xs">Locked — this procedure has already been referenced by a radiology order.</p>
                    )}
                    <InputError message={errors.code} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="name">Procedure Name *</Label>
                    <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-2">
                    <Label>Category / Modality</Label>
                    <Select
                        value={data.modality || 'none'}
                        onValueChange={(value) => setData('modality', value === 'none' ? '' : (value as RadiologyModality))}
                    >
                        <SelectTrigger>
                            <SelectValue placeholder="Not specified" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">Not specified</SelectItem>
                            {modalities.map((modality) => (
                                <SelectItem key={modality} value={modality}>
                                    {radiologyModalityLabel[modality]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.modality} />
                </div>

                <div className="grid gap-2">
                    <Label>Department</Label>
                    <Select value={data.department_id || 'none'} onValueChange={(value) => setData('department_id', value === 'none' ? '' : value)}>
                        <SelectTrigger>
                            <SelectValue placeholder="Not specified" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">Not specified</SelectItem>
                            {departments.map((department) => (
                                <SelectItem key={department.id} value={String(department.id)}>
                                    {department.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.department_id} />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="description">Description</Label>
                <Textarea id="description" rows={2} value={data.description} onChange={(e) => setData('description', e.target.value)} />
                <InputError message={errors.description} />
            </div>

            <div className="space-y-4 rounded-md border p-4">
                <h3 className="text-sm font-medium">Body Site(s)</h3>
                <p className="text-muted-foreground text-xs">A procedure may examine more than one body region (e.g. CT Chest-Abdomen).</p>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    {bodySites.map((site) => (
                        <label key={site} className="flex items-center gap-2 text-sm">
                            <Checkbox checked={data.body_sites.includes(site)} onCheckedChange={() => toggleBodySite(site)} />
                            {radiologyBodySiteLabel[site]}
                        </label>
                    ))}
                </div>
                <InputError message={errors.body_sites} />
            </div>

            <div className="space-y-4 rounded-md border p-4">
                <h3 className="text-sm font-medium">Laterality & Contrast</h3>
                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="grid gap-2">
                        <Label>Laterality</Label>
                        <Select value={data.laterality} onValueChange={(value) => setData('laterality', value as RadiologyLaterality)}>
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {lateralityOptions.map((option) => (
                                    <SelectItem key={option} value={option}>
                                        {radiologyLateralityLabel[option]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.laterality} />
                    </div>

                    <div className="grid gap-2">
                        <Label>Contrast</Label>
                        <Select
                            value={data.contrast_requirement}
                            onValueChange={(value) => setData('contrast_requirement', value as RadiologyContrastRequirement)}
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {contrastRequirements.map((option) => (
                                    <SelectItem key={option} value={option}>
                                        {radiologyContrastRequirementLabel[option]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.contrast_requirement} />
                    </div>

                    {data.contrast_requirement !== 'no_contrast' && (
                        <div className="grid gap-2">
                            <Label>Contrast Type</Label>
                            <Select
                                value={data.contrast_type || 'none'}
                                onValueChange={(value) => setData('contrast_type', value === 'none' ? '' : (value as RadiologyContrastType))}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Not specified" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">Not specified</SelectItem>
                                    {contrastTypes.map((option) => (
                                        <SelectItem key={option} value={option}>
                                            {radiologyContrastTypeLabel[option]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.contrast_type} />
                        </div>
                    )}
                </div>
                <p className="text-muted-foreground text-xs">
                    An operational default only — the actual clinical decision is made at order/protocol time, not fixed by the catalog.
                </p>
            </div>

            <div className="space-y-4 rounded-md border p-4">
                <h3 className="text-sm font-medium">Patient Preparation</h3>
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label>Preparation Type</Label>
                        <Select
                            value={data.preparation_type}
                            onValueChange={(value) => setData('preparation_type', value as RadiologyPreparationType)}
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {preparationTypes.map((option) => (
                                    <SelectItem key={option} value={option}>
                                        {radiologyPreparationTypeLabel[option]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.preparation_type} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="turnaround_time_minutes">Turnaround Time (minutes)</Label>
                        <Input
                            id="turnaround_time_minutes"
                            type="number"
                            min={0}
                            value={data.turnaround_time_minutes}
                            onChange={(e) => setData('turnaround_time_minutes', e.target.value)}
                        />
                        <p className="text-muted-foreground text-xs">An operational target, not a guaranteed result time.</p>
                        <InputError message={errors.turnaround_time_minutes} />
                    </div>

                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="preparation_instructions">Preparation Instructions</Label>
                        <Textarea
                            id="preparation_instructions"
                            rows={2}
                            value={data.preparation_instructions}
                            onChange={(e) => setData('preparation_instructions', e.target.value)}
                        />
                        <InputError message={errors.preparation_instructions} />
                    </div>
                </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="sort_order">Sort Order</Label>
                    <Input id="sort_order" type="number" min={0} value={data.sort_order} onChange={(e) => setData('sort_order', e.target.value)} />
                    <InputError message={errors.sort_order} />
                </div>
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
                    <Link href={route('radiology-catalog.index')}>Cancel</Link>
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    {isEditing ? 'Save changes' : 'Create procedure'}
                </Button>
            </div>
        </form>
    );
}
