import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import {
    laboratoryOrderPriorityLabel,
    laboratoryResultTypeLabel,
    laboratorySpecimenContainerLabel,
    laboratorySpecimenTypeLabel,
} from '@/lib/laboratory-status';
import {
    type LaboratoryOrderPriority,
    type LaboratoryResultType,
    type LaboratorySpecimenContainer,
    type LaboratorySpecimenType,
    type LaboratoryTest,
    type LaboratoryTestCategoryOption,
    type LaboratoryTestDepartment,
    type LaboratoryTestFormData,
} from '@/types/laboratory';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface LaboratoryTestFormProps {
    test?: LaboratoryTest;
    isReferenced?: boolean;
    categories: LaboratoryTestCategoryOption[];
    departments: LaboratoryTestDepartment[];
    specimenTypes: LaboratorySpecimenType[];
    specimenContainers: LaboratorySpecimenContainer[];
    resultTypes: LaboratoryResultType[];
}

const PRIORITIES: LaboratoryOrderPriority[] = ['routine', 'urgent', 'stat'];

/**
 * Shared create/edit form (mirrors DepartmentForm's create/edit-sharing
 * convention). `code` is locked once the test has been referenced by a
 * laboratory order (spec §6/§34) — the backend is the real authority;
 * disabling the field here is only a UI convenience.
 */
export function LaboratoryTestForm({
    test,
    isReferenced,
    categories,
    departments,
    specimenTypes,
    specimenContainers,
    resultTypes,
}: LaboratoryTestFormProps) {
    const isEditing = Boolean(test);

    const { data, setData, post, put, processing, errors, transform } = useForm<LaboratoryTestFormData>({
        code: test?.code ?? '',
        name: test?.name ?? '',
        short_name: test?.short_name ?? '',
        description: test?.description ?? '',
        category_id: test?.category ? String(test.category.id) : '',
        department_id: test?.department ? String(test.department.id) : '',
        specimen_type: test?.specimen_type ?? '',
        specimen_container: test?.specimen_container ?? '',
        collection_notes: test?.collection_notes ?? '',
        result_type: test?.result_type ?? '',
        unit: test?.unit ?? '',
        reference_range: test?.reference_range ?? '',
        result_options: test?.result_options ? test.result_options.join(', ') : '',
        turnaround_time_minutes: test?.turnaround_time_minutes ? String(test.turnaround_time_minutes) : '',
        default_priority: test?.default_priority ?? 'routine',
        effective_from: test?.effective_from ?? '',
        effective_to: test?.effective_to ?? '',
        nhif_code: test?.nhif_code ?? '',
        price: test?.price ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        transform((data) => ({
            ...data,
            result_options: data.result_options
                ? data.result_options
                      .split(',')
                      .map((option) => option.trim())
                      .filter(Boolean)
                : null,
        }));

        if (test) {
            put(route('laboratory-tests.update', test.id));
        } else {
            post(route('laboratory-tests.store'));
        }
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="code">Test Code *</Label>
                    <Input
                        id="code"
                        value={data.code}
                        onChange={(e) => setData('code', e.target.value.toUpperCase())}
                        disabled={isReferenced}
                        required
                        autoFocus
                    />
                    {isReferenced && (
                        <p className="text-muted-foreground text-xs">Locked — this test has already been referenced by a laboratory order.</p>
                    )}
                    <InputError message={errors.code} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="name">Test Name *</Label>
                    <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="short_name">Short Name</Label>
                    <Input id="short_name" value={data.short_name} onChange={(e) => setData('short_name', e.target.value)} placeholder="e.g. CBC" />
                    <InputError message={errors.short_name} />
                </div>

                <div className="grid gap-2">
                    <Label>Category</Label>
                    <Select value={data.category_id || 'none'} onValueChange={(value) => setData('category_id', value === 'none' ? '' : value)}>
                        <SelectTrigger>
                            <SelectValue placeholder="Uncategorized" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">Uncategorized</SelectItem>
                            {categories.map((category) => (
                                <SelectItem key={category.id} value={String(category.id)}>
                                    {category.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.category_id} />
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
                <h3 className="text-sm font-medium">Specimen</h3>
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label>Specimen Type</Label>
                        <Select
                            value={data.specimen_type || 'none'}
                            onValueChange={(value) => setData('specimen_type', value === 'none' ? '' : (value as LaboratorySpecimenType))}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Not specified" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">Not specified</SelectItem>
                                {specimenTypes.map((type) => (
                                    <SelectItem key={type} value={type}>
                                        {laboratorySpecimenTypeLabel[type]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.specimen_type} />
                    </div>

                    <div className="grid gap-2">
                        <Label>Specimen Container</Label>
                        <Select
                            value={data.specimen_container || 'none'}
                            onValueChange={(value) => setData('specimen_container', value === 'none' ? '' : (value as LaboratorySpecimenContainer))}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Not specified" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">Not specified</SelectItem>
                                {specimenContainers.map((container) => (
                                    <SelectItem key={container} value={container}>
                                        {laboratorySpecimenContainerLabel[container]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.specimen_container} />
                    </div>

                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="collection_notes">Collection Notes</Label>
                        <Textarea
                            id="collection_notes"
                            rows={2}
                            value={data.collection_notes}
                            onChange={(e) => setData('collection_notes', e.target.value)}
                        />
                        <InputError message={errors.collection_notes} />
                    </div>
                </div>
            </div>

            <div className="space-y-4 rounded-md border p-4">
                <h3 className="text-sm font-medium">Result Configuration</h3>
                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="grid gap-2">
                        <Label>Result Type</Label>
                        <Select
                            value={data.result_type || 'none'}
                            onValueChange={(value) => setData('result_type', value === 'none' ? '' : (value as LaboratoryResultType))}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Not specified" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">Not specified</SelectItem>
                                {resultTypes.map((type) => (
                                    <SelectItem key={type} value={type}>
                                        {laboratoryResultTypeLabel[type]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.result_type} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="unit">Unit</Label>
                        <Input id="unit" value={data.unit} onChange={(e) => setData('unit', e.target.value)} placeholder="e.g. g/dL" />
                        <InputError message={errors.unit} />
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
                        <InputError message={errors.turnaround_time_minutes} />
                    </div>

                    <div className="grid gap-2 sm:col-span-3">
                        <Label htmlFor="reference_range">Reference Range</Label>
                        <Input
                            id="reference_range"
                            value={data.reference_range}
                            onChange={(e) => setData('reference_range', e.target.value)}
                            placeholder="e.g. 13.5–17.5 g/dL (Adult Male)"
                        />
                        <InputError message={errors.reference_range} />
                    </div>

                    {(data.result_type === 'qualitative' || data.result_type === 'enum') && (
                        <div className="grid gap-2 sm:col-span-3">
                            <Label htmlFor="result_options">Result Options</Label>
                            <Input
                                id="result_options"
                                value={data.result_options}
                                onChange={(e) => setData('result_options', e.target.value)}
                                placeholder="e.g. Positive, Negative, Reactive, Non-reactive"
                            />
                            <p className="text-muted-foreground text-xs">Comma-separated. Laboratory staff can only select one of these values.</p>
                            <InputError message={errors.result_options} />
                        </div>
                    )}
                </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
                <div className="grid gap-2">
                    <Label>Default Priority</Label>
                    <Select value={data.default_priority} onValueChange={(value) => setData('default_priority', value as LaboratoryOrderPriority)}>
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {PRIORITIES.map((priority) => (
                                <SelectItem key={priority} value={priority}>
                                    {laboratoryOrderPriorityLabel[priority]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.default_priority} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="effective_from">Effective From</Label>
                    <Input id="effective_from" type="date" value={data.effective_from} onChange={(e) => setData('effective_from', e.target.value)} />
                    <InputError message={errors.effective_from} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="effective_to">Effective To</Label>
                    <Input id="effective_to" type="date" value={data.effective_to} onChange={(e) => setData('effective_to', e.target.value)} />
                    <InputError message={errors.effective_to} />
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
                    <Link href={route('laboratory-tests.index')}>Cancel</Link>
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    {isEditing ? 'Save changes' : 'Create test'}
                </Button>
            </div>
        </form>
    );
}
