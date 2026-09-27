import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import {
    type LaboratoryParameterDataType,
    type LaboratoryReferenceSex,
    type LaboratoryReferenceRange,
    type LaboratoryTest,
    type LaboratoryTestParameter,
    type StoreLaboratoryReferenceRangeForm,
    type StoreLaboratoryTestParameterForm,
} from '@/types/laboratory';
import { router, useForm } from '@inertiajs/react';
import { ChevronDown, ChevronUp, Pencil, Plus, Trash2, X } from 'lucide-react';
import { useState } from 'react';

interface Props {
    test: LaboratoryTest;
    canManage: boolean;
}

const DATA_TYPES: { value: LaboratoryParameterDataType; label: string }[] = [
    { value: 'numeric', label: 'Numeric' },
    { value: 'text', label: 'Free text' },
    { value: 'option', label: 'Option list' },
];

const SEX_OPTIONS: { value: LaboratoryReferenceSex; label: string }[] = [
    { value: 'any', label: 'Any' },
    { value: 'male', label: 'Male' },
    { value: 'female', label: 'Female' },
];

function emptyParameterForm(): StoreLaboratoryTestParameterForm {
    return {
        code: '',
        name: '',
        unit: '',
        data_type: '',
        decimal_places: '1',
        options_json: '',
        abnormal_options: '',
        display_order: '0',
        is_calculated: false,
        calculation_formula: '',
        analyser_code: '',
        loinc_code: '',
    };
}

function emptyRangeForm(): StoreLaboratoryReferenceRangeForm {
    return { sex: '', age_min_days: '', age_max_days: '', low: '', high: '', critical_low: '', critical_high: '', notes: '' };
}

export function LaboratoryTestParameterSection({ test, canManage }: Props) {
    const [showParameterForm, setShowParameterForm] = useState(false);
    const [editingParameter, setEditingParameter] = useState<LaboratoryTestParameter | null>(null);
    const [expandedRanges, setExpandedRanges] = useState<Set<number>>(new Set());
    const [addingRangeFor, setAddingRangeFor] = useState<number | null>(null);

    const paramForm = useForm<StoreLaboratoryTestParameterForm>(emptyParameterForm());
    const rangeForm = useForm<StoreLaboratoryReferenceRangeForm>(emptyRangeForm());

    function toggleRanges(parameterId: number) {
        setExpandedRanges((prev) => {
            const next = new Set(prev);
            if (next.has(parameterId)) {
                next.delete(parameterId);
            } else {
                next.add(parameterId);
            }
            return next;
        });
    }

    function openAddParameter() {
        paramForm.reset();
        paramForm.setData(emptyParameterForm());
        setEditingParameter(null);
        setShowParameterForm(true);
    }

    function openEditParameter(parameter: LaboratoryTestParameter) {
        paramForm.setData({
            code: parameter.code,
            name: parameter.name,
            unit: parameter.unit ?? '',
            data_type: parameter.data_type,
            decimal_places: String(parameter.decimal_places),
            options_json: parameter.options_json?.join('\n') ?? '',
            abnormal_options: parameter.abnormal_options?.join('\n') ?? '',
            display_order: String(parameter.display_order),
            is_calculated: parameter.is_calculated,
            calculation_formula: parameter.calculation_formula ?? '',
            analyser_code: parameter.analyser_code ?? '',
            loinc_code: parameter.loinc_code ?? '',
        });
        setEditingParameter(parameter);
        setShowParameterForm(true);
    }

    function cancelParameterForm() {
        setShowParameterForm(false);
        setEditingParameter(null);
        paramForm.reset();
    }

    function submitParameter(e: React.FormEvent) {
        e.preventDefault();
        if (editingParameter) {
            paramForm.put(route('laboratory-test-parameters.update', [test.id, editingParameter.id]), {
                onSuccess: () => cancelParameterForm(),
                preserveScroll: true,
            });
        } else {
            paramForm.post(route('laboratory-test-parameters.store', test.id), {
                onSuccess: () => cancelParameterForm(),
                preserveScroll: true,
            });
        }
    }

    function deleteParameter(parameter: LaboratoryTestParameter) {
        if (!confirm(`Delete parameter "${parameter.name}"? This cannot be undone if results exist.`)) {
            return;
        }
        router.delete(route('laboratory-test-parameters.destroy', [test.id, parameter.id]), { preserveScroll: true });
    }

    function openAddRange(parameterId: number) {
        rangeForm.reset();
        rangeForm.setData(emptyRangeForm());
        setAddingRangeFor(parameterId);
        setExpandedRanges((prev) => new Set([...prev, parameterId]));
    }

    function cancelRangeForm() {
        setAddingRangeFor(null);
        rangeForm.reset();
    }

    function submitRange(e: React.FormEvent, parameter: LaboratoryTestParameter) {
        e.preventDefault();
        rangeForm.post(route('laboratory-reference-ranges.store', parameter.id), {
            onSuccess: () => cancelRangeForm(),
            preserveScroll: true,
        });
    }

    function deleteRange(parameter: LaboratoryTestParameter, range: LaboratoryReferenceRange) {
        if (!confirm('Remove this reference range?')) {
            return;
        }
        router.delete(route('laboratory-reference-ranges.destroy', [parameter.id, range.id]), { preserveScroll: true });
    }

    const needsOptions = paramForm.data.data_type === 'option';
    const isNumeric = paramForm.data.data_type === 'numeric';

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between">
                <CardTitle className="text-base">Analyte Parameters</CardTitle>
                {canManage && !showParameterForm && (
                    <Button size="sm" variant="outline" onClick={openAddParameter}>
                        <Plus className="h-4 w-4" />
                        Add parameter
                    </Button>
                )}
            </CardHeader>

            <CardContent className="space-y-4">
                {showParameterForm && (
                    <form onSubmit={submitParameter} className="rounded-md border p-4">
                        <div className="mb-4 flex items-center justify-between">
                            <p className="text-sm font-medium">{editingParameter ? 'Edit parameter' : 'New parameter'}</p>
                            <Button type="button" size="icon" variant="ghost" onClick={cancelParameterForm}>
                                <X className="h-4 w-4" />
                            </Button>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="param-code">Code *</Label>
                                <Input
                                    id="param-code"
                                    value={paramForm.data.code}
                                    onChange={(e) => paramForm.setData('code', e.target.value.toUpperCase())}
                                    placeholder="HB"
                                    required
                                />
                                <InputError message={paramForm.errors.code} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="param-name">Name *</Label>
                                <Input
                                    id="param-name"
                                    value={paramForm.data.name}
                                    onChange={(e) => paramForm.setData('name', e.target.value)}
                                    placeholder="Hemoglobin"
                                    required
                                />
                                <InputError message={paramForm.errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="param-unit">Unit</Label>
                                <Input
                                    id="param-unit"
                                    value={paramForm.data.unit}
                                    onChange={(e) => paramForm.setData('unit', e.target.value)}
                                    placeholder="g/dL"
                                />
                                <InputError message={paramForm.errors.unit} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="param-data-type">Data type *</Label>
                                <Select
                                    value={paramForm.data.data_type}
                                    onValueChange={(v) => paramForm.setData('data_type', v as LaboratoryParameterDataType)}
                                >
                                    <SelectTrigger id="param-data-type">
                                        <SelectValue placeholder="Select type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {DATA_TYPES.map((dt) => (
                                            <SelectItem key={dt.value} value={dt.value}>
                                                {dt.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={paramForm.errors.data_type} />
                            </div>

                            {isNumeric && (
                                <div className="grid gap-2">
                                    <Label htmlFor="param-decimal">Decimal places</Label>
                                    <Input
                                        id="param-decimal"
                                        type="number"
                                        min={0}
                                        max={6}
                                        value={paramForm.data.decimal_places}
                                        onChange={(e) => paramForm.setData('decimal_places', e.target.value)}
                                    />
                                    <InputError message={paramForm.errors.decimal_places} />
                                </div>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="param-order">Display order</Label>
                                <Input
                                    id="param-order"
                                    type="number"
                                    min={0}
                                    value={paramForm.data.display_order}
                                    onChange={(e) => paramForm.setData('display_order', e.target.value)}
                                />
                                <InputError message={paramForm.errors.display_order} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="param-analyser">Analyser code</Label>
                                <Input
                                    id="param-analyser"
                                    value={paramForm.data.analyser_code}
                                    onChange={(e) => paramForm.setData('analyser_code', e.target.value)}
                                    placeholder="HGB"
                                />
                                <InputError message={paramForm.errors.analyser_code} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="param-loinc">LOINC code</Label>
                                <Input
                                    id="param-loinc"
                                    value={paramForm.data.loinc_code}
                                    onChange={(e) => paramForm.setData('loinc_code', e.target.value)}
                                    placeholder="718-7"
                                />
                                <InputError message={paramForm.errors.loinc_code} />
                            </div>
                        </div>

                        {needsOptions && (
                            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="param-options">Options (one per line)</Label>
                                    <textarea
                                        id="param-options"
                                        className="border-input bg-background placeholder:text-muted-foreground focus-visible:ring-ring min-h-[80px] w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-1 focus-visible:outline-none"
                                        value={paramForm.data.options_json}
                                        onChange={(e) => paramForm.setData('options_json', e.target.value)}
                                        placeholder={'negative\npositive'}
                                    />
                                    <InputError message={paramForm.errors.options_json} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="param-abnormal">Abnormal options (one per line)</Label>
                                    <textarea
                                        id="param-abnormal"
                                        className="border-input bg-background placeholder:text-muted-foreground focus-visible:ring-ring min-h-[80px] w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-1 focus-visible:outline-none"
                                        value={paramForm.data.abnormal_options}
                                        onChange={(e) => paramForm.setData('abnormal_options', e.target.value)}
                                        placeholder={'positive'}
                                    />
                                    <InputError message={paramForm.errors.abnormal_options} />
                                </div>
                            </div>
                        )}

                        <div className="mt-4 flex items-center gap-3">
                            <Switch
                                id="param-calculated"
                                checked={paramForm.data.is_calculated}
                                onCheckedChange={(v) => paramForm.setData('is_calculated', v)}
                            />
                            <Label htmlFor="param-calculated">Calculated parameter</Label>
                        </div>
                        {paramForm.data.is_calculated && (
                            <div className="mt-2 grid gap-2">
                                <Label htmlFor="param-formula">Formula</Label>
                                <Input
                                    id="param-formula"
                                    value={paramForm.data.calculation_formula}
                                    onChange={(e) => paramForm.setData('calculation_formula', e.target.value)}
                                    placeholder="field1 / field2"
                                />
                                <InputError message={paramForm.errors.calculation_formula} />
                            </div>
                        )}

                        <div className="mt-4 flex justify-end gap-2">
                            <Button type="button" variant="outline" size="sm" onClick={cancelParameterForm}>
                                Cancel
                            </Button>
                            <Button type="submit" size="sm" disabled={paramForm.processing}>
                                {editingParameter ? 'Save changes' : 'Add parameter'}
                            </Button>
                        </div>
                    </form>
                )}

                {test.parameters.length === 0 && !showParameterForm && (
                    <p className="text-muted-foreground text-sm">No parameters defined yet.</p>
                )}

                {test.parameters.map((parameter) => (
                    <div key={parameter.id} className="rounded-md border">
                        <div className="flex items-center justify-between px-4 py-3">
                            <div className="flex items-center gap-3">
                                <span className="text-muted-foreground font-mono text-xs">{parameter.code}</span>
                                <span className="text-sm font-medium">{parameter.name}</span>
                                {parameter.unit && <span className="text-muted-foreground text-xs">({parameter.unit})</span>}
                                <span className="bg-muted rounded px-1.5 py-0.5 text-xs capitalize">{parameter.data_type}</span>
                                {!parameter.active && <span className="text-muted-foreground text-xs">(inactive)</span>}
                            </div>

                            <div className="flex items-center gap-1">
                                {canManage && (
                                    <>
                                        <Button size="icon" variant="ghost" title="Add reference range" onClick={() => openAddRange(parameter.id)}>
                                            <Plus className="h-3.5 w-3.5" />
                                        </Button>
                                        <Button size="icon" variant="ghost" title="Edit parameter" onClick={() => openEditParameter(parameter)}>
                                            <Pencil className="h-3.5 w-3.5" />
                                        </Button>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            title="Delete parameter"
                                            className="text-destructive hover:text-destructive"
                                            onClick={() => deleteParameter(parameter)}
                                        >
                                            <Trash2 className="h-3.5 w-3.5" />
                                        </Button>
                                    </>
                                )}
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    title={expandedRanges.has(parameter.id) ? 'Collapse ranges' : 'Expand ranges'}
                                    onClick={() => toggleRanges(parameter.id)}
                                >
                                    {expandedRanges.has(parameter.id) ? (
                                        <ChevronUp className="h-3.5 w-3.5" />
                                    ) : (
                                        <ChevronDown className="h-3.5 w-3.5" />
                                    )}
                                </Button>
                            </div>
                        </div>

                        {expandedRanges.has(parameter.id) && (
                            <div className="border-t px-4 py-3">
                                {parameter.reference_ranges.length > 0 ? (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead className="text-xs">Sex</TableHead>
                                                <TableHead className="text-xs">Age (days)</TableHead>
                                                <TableHead className="text-xs">Low</TableHead>
                                                <TableHead className="text-xs">High</TableHead>
                                                <TableHead className="text-xs">Critical low</TableHead>
                                                <TableHead className="text-xs">Critical high</TableHead>
                                                <TableHead className="text-xs">Notes</TableHead>
                                                {canManage && <TableHead />}
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {parameter.reference_ranges.map((range) => (
                                                <TableRow key={range.id}>
                                                    <TableCell className="text-xs capitalize">{range.sex}</TableCell>
                                                    <TableCell className="text-xs">
                                                        {range.age_min_days ?? '—'} – {range.age_max_days ?? '∞'}
                                                    </TableCell>
                                                    <TableCell className="text-xs">{range.low ?? '—'}</TableCell>
                                                    <TableCell className="text-xs">{range.high ?? '—'}</TableCell>
                                                    <TableCell className="text-xs">{range.critical_low ?? '—'}</TableCell>
                                                    <TableCell className="text-xs">{range.critical_high ?? '—'}</TableCell>
                                                    <TableCell className="text-muted-foreground text-xs">{range.notes ?? ''}</TableCell>
                                                    {canManage && (
                                                        <TableCell>
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                className="text-destructive hover:text-destructive"
                                                                onClick={() => deleteRange(parameter, range)}
                                                            >
                                                                <Trash2 className="h-3.5 w-3.5" />
                                                            </Button>
                                                        </TableCell>
                                                    )}
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                ) : (
                                    <p className="text-muted-foreground text-xs">No reference ranges defined.</p>
                                )}

                                {addingRangeFor === parameter.id && (
                                    <form onSubmit={(e) => submitRange(e, parameter)} className="mt-3 rounded border p-3">
                                        <p className="mb-3 text-xs font-medium">New reference range</p>
                                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                            <div className="grid gap-1">
                                                <Label className="text-xs" htmlFor="range-sex">
                                                    Sex *
                                                </Label>
                                                <Select
                                                    value={rangeForm.data.sex}
                                                    onValueChange={(v) => rangeForm.setData('sex', v as LaboratoryReferenceSex)}
                                                >
                                                    <SelectTrigger id="range-sex" className="h-8 text-xs">
                                                        <SelectValue placeholder="Sex" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {SEX_OPTIONS.map((s) => (
                                                            <SelectItem key={s.value} value={s.value}>
                                                                {s.label}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                                <InputError message={rangeForm.errors.sex} />
                                            </div>

                                            <div className="grid gap-1">
                                                <Label className="text-xs" htmlFor="range-age-min">
                                                    Age min (days)
                                                </Label>
                                                <Input
                                                    id="range-age-min"
                                                    className="h-8 text-xs"
                                                    type="number"
                                                    min={0}
                                                    value={rangeForm.data.age_min_days}
                                                    onChange={(e) => rangeForm.setData('age_min_days', e.target.value)}
                                                    placeholder="0"
                                                />
                                                <InputError message={rangeForm.errors.age_min_days} />
                                            </div>

                                            <div className="grid gap-1">
                                                <Label className="text-xs" htmlFor="range-age-max">
                                                    Age max (days)
                                                </Label>
                                                <Input
                                                    id="range-age-max"
                                                    className="h-8 text-xs"
                                                    type="number"
                                                    min={0}
                                                    value={rangeForm.data.age_max_days}
                                                    onChange={(e) => rangeForm.setData('age_max_days', e.target.value)}
                                                    placeholder="blank = no limit"
                                                />
                                                <InputError message={rangeForm.errors.age_max_days} />
                                            </div>

                                            <div className="grid gap-1">
                                                <Label className="text-xs" htmlFor="range-low">
                                                    Low
                                                </Label>
                                                <Input
                                                    id="range-low"
                                                    className="h-8 text-xs"
                                                    type="number"
                                                    step="any"
                                                    value={rangeForm.data.low}
                                                    onChange={(e) => rangeForm.setData('low', e.target.value)}
                                                />
                                                <InputError message={rangeForm.errors.low} />
                                            </div>

                                            <div className="grid gap-1">
                                                <Label className="text-xs" htmlFor="range-high">
                                                    High
                                                </Label>
                                                <Input
                                                    id="range-high"
                                                    className="h-8 text-xs"
                                                    type="number"
                                                    step="any"
                                                    value={rangeForm.data.high}
                                                    onChange={(e) => rangeForm.setData('high', e.target.value)}
                                                />
                                                <InputError message={rangeForm.errors.high} />
                                            </div>

                                            <div className="grid gap-1">
                                                <Label className="text-xs" htmlFor="range-crit-low">
                                                    Critical low
                                                </Label>
                                                <Input
                                                    id="range-crit-low"
                                                    className="h-8 text-xs"
                                                    type="number"
                                                    step="any"
                                                    value={rangeForm.data.critical_low}
                                                    onChange={(e) => rangeForm.setData('critical_low', e.target.value)}
                                                />
                                                <InputError message={rangeForm.errors.critical_low} />
                                            </div>

                                            <div className="grid gap-1">
                                                <Label className="text-xs" htmlFor="range-crit-high">
                                                    Critical high
                                                </Label>
                                                <Input
                                                    id="range-crit-high"
                                                    className="h-8 text-xs"
                                                    type="number"
                                                    step="any"
                                                    value={rangeForm.data.critical_high}
                                                    onChange={(e) => rangeForm.setData('critical_high', e.target.value)}
                                                />
                                                <InputError message={rangeForm.errors.critical_high} />
                                            </div>

                                            <div className="grid gap-1 sm:col-span-2 lg:col-span-1">
                                                <Label className="text-xs" htmlFor="range-notes">
                                                    Notes
                                                </Label>
                                                <Input
                                                    id="range-notes"
                                                    className="h-8 text-xs"
                                                    value={rangeForm.data.notes}
                                                    onChange={(e) => rangeForm.setData('notes', e.target.value)}
                                                    placeholder="e.g. Adult male"
                                                />
                                            </div>
                                        </div>

                                        <div className="mt-3 flex justify-end gap-2">
                                            <Button type="button" variant="outline" size="sm" onClick={cancelRangeForm}>
                                                Cancel
                                            </Button>
                                            <Button type="submit" size="sm" disabled={rangeForm.processing}>
                                                Add range
                                            </Button>
                                        </div>
                                    </form>
                                )}
                            </div>
                        )}
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}
