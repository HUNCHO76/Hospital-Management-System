import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    type LaboratoryParameterDataType,
    type LaboratoryTestParameter,
    type StoreLaboratoryTestParameterForm,
} from '@/types/laboratory';
import { useForm } from '@inertiajs/react';
import { FlaskConical, LoaderCircle } from 'lucide-react';
import { useEffect } from 'react';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    test: {
        id: number;
        code: string;
        name: string;
        specimen_type?: string | null;
    } | null;
    parameter?: LaboratoryTestParameter | null;
    onSuccess?: () => void;
}

const DATA_TYPES: { value: LaboratoryParameterDataType; label: string; desc: string }[] = [
    { value: 'numeric', label: 'Numeric Analyte', desc: 'Quantitative measurement with optional unit and decimal places' },
    { value: 'text', label: 'Narrative Text', desc: 'Free-text findings, microscopic comments, or culture morphology' },
    { value: 'option', label: 'Selectable Options', desc: 'Categorical pick-list with defined normal / abnormal values' },
];

function emptyForm(): StoreLaboratoryTestParameterForm {
    return {
        code: '',
        name: '',
        unit: '',
        data_type: 'numeric',
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

export function LaboratoryParameterDialog({ open, onOpenChange, test, parameter, onSuccess }: Props) {
    const isEditing = Boolean(parameter);

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm<
        StoreLaboratoryTestParameterForm & { active?: boolean }
    >({
        ...emptyForm(),
        active: true,
    });

    useEffect(() => {
        if (!open) {
            reset();
            clearErrors();
            return;
        }

        if (parameter) {
            setData({
                code: parameter.code,
                name: parameter.name,
                unit: parameter.unit ?? '',
                data_type: parameter.data_type,
                decimal_places: String(parameter.decimal_places ?? 1),
                options_json: parameter.options_json?.join('\n') ?? '',
                abnormal_options: parameter.abnormal_options?.join('\n') ?? '',
                display_order: String(parameter.display_order ?? 0),
                is_calculated: parameter.is_calculated ?? false,
                calculation_formula: parameter.calculation_formula ?? '',
                analyser_code: parameter.analyser_code ?? '',
                loinc_code: parameter.loinc_code ?? '',
                active: parameter.active ?? true,
            });
        } else {
            setData({
                ...emptyForm(),
                active: true,
            });
        }
    }, [open, parameter]);

    if (!test) {
        return null;
    }

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (isEditing && parameter) {
            put(route('laboratory-test-parameters.update', [test.id, parameter.id]), {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    onOpenChange(false);
                    onSuccess?.();
                },
            });
        } else {
            post(route('laboratory-test-parameters.store', test.id), {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    onOpenChange(false);
                    onSuccess?.();
                },
            });
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-[620px]">
                <DialogHeader>
                    <div className="flex items-center gap-2">
                        <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <FlaskConical className="h-5 w-5" />
                        </div>
                        <div>
                            <DialogTitle className="text-lg">
                                {isEditing ? 'Edit Parameter' : 'Add Test Parameter'}
                            </DialogTitle>
                            <DialogDescription className="text-xs text-muted-foreground">
                                Test: <span className="font-semibold text-foreground">{test.name}</span> ({test.code})
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="space-y-4 pt-2">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="param_code" className="text-xs font-medium">
                                Parameter Code <span className="text-destructive">*</span>
                            </Label>
                            <Input
                                id="param_code"
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value.toUpperCase())}
                                placeholder="e.g. HGB, RBC, GROWTH"
                                disabled={processing}
                                className="font-mono text-sm"
                            />
                            <p className="text-[11px] text-muted-foreground">Unique identifier within this test.</p>
                            <InputError message={errors.code} />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="param_name" className="text-xs font-medium">
                                Analyte / Parameter Name <span className="text-destructive">*</span>
                            </Label>
                            <Input
                                id="param_name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="e.g. Hemoglobin, Bacterial Growth"
                                disabled={processing}
                            />
                            <p className="text-[11px] text-muted-foreground">Display label on result entry and patient reports.</p>
                            <InputError message={errors.name} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="param_data_type" className="text-xs font-medium">
                                Data Type <span className="text-destructive">*</span>
                            </Label>
                            <Select
                                value={data.data_type}
                                onValueChange={(val) => setData('data_type', val as LaboratoryParameterDataType)}
                                disabled={processing}
                            >
                                <SelectTrigger id="param_data_type">
                                    <SelectValue placeholder="Select type" />
                                </SelectTrigger>
                                <SelectContent>
                                    {DATA_TYPES.map((dt) => (
                                        <SelectItem key={dt.value} value={dt.value}>
                                            <div className="flex flex-col">
                                                <span className="font-medium">{dt.label}</span>
                                                <span className="text-[10px] text-muted-foreground">{dt.desc}</span>
                                            </div>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.data_type} />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="param_unit" className="text-xs font-medium">
                                Measurement Unit
                            </Label>
                            <Input
                                id="param_unit"
                                value={data.unit}
                                onChange={(e) => setData('unit', e.target.value)}
                                placeholder="e.g. g/dL, mmol/L, %, 10^9/L"
                                disabled={processing || data.data_type === 'text'}
                            />
                            <p className="text-[11px] text-muted-foreground">Optional clinical unit for numeric values.</p>
                            <InputError message={errors.unit} />
                        </div>
                    </div>

                    {data.data_type === 'numeric' && (
                        <div className="rounded-lg border bg-muted/30 p-3">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="param_decimals" className="text-xs font-medium">
                                        Decimal Precision
                                    </Label>
                                    <Input
                                        id="param_decimals"
                                        type="number"
                                        min={0}
                                        max={4}
                                        value={data.decimal_places}
                                        onChange={(e) => setData('decimal_places', e.target.value)}
                                        disabled={processing}
                                    />
                                    <p className="text-[11px] text-muted-foreground">0 for integers, 1-3 for decimals.</p>
                                    <InputError message={errors.decimal_places} />
                                </div>

                                <div className="space-y-1.5">
                                    <Label htmlFor="param_analyser" className="text-xs font-medium">
                                        Analyser Channel Code
                                    </Label>
                                    <Input
                                        id="param_analyser"
                                        value={data.analyser_code}
                                        onChange={(e) => setData('analyser_code', e.target.value)}
                                        placeholder="e.g. HGB, PLT"
                                        disabled={processing}
                                    />
                                    <p className="text-[11px] text-muted-foreground">Instrument mapping for automated LIS import.</p>
                                    <InputError message={errors.analyser_code} />
                                </div>
                            </div>
                        </div>
                    )}

                    {data.data_type === 'option' && (
                        <div className="space-y-3 rounded-lg border bg-muted/30 p-3">
                            <div className="space-y-1.5">
                                <Label htmlFor="param_options" className="text-xs font-medium">
                                    Allowed Options (One per line) <span className="text-destructive">*</span>
                                </Label>
                                <Textarea
                                    id="param_options"
                                    rows={3}
                                    value={data.options_json}
                                    onChange={(e) => setData('options_json', e.target.value)}
                                    placeholder="Positive&#10;Negative&#10;Equivocal"
                                    disabled={processing}
                                    className="font-mono text-xs"
                                />
                                <p className="text-[11px] text-muted-foreground">Enter each valid pick-list option on a new line.</p>
                                <InputError message={errors.options_json} />
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="param_abnormal" className="text-xs font-medium">
                                    Abnormal Options (One per line)
                                </Label>
                                <Textarea
                                    id="param_abnormal"
                                    rows={2}
                                    value={data.abnormal_options}
                                    onChange={(e) => setData('abnormal_options', e.target.value)}
                                    placeholder="Positive&#10;Equivocal"
                                    disabled={processing}
                                    className="font-mono text-xs"
                                />
                                <p className="text-[11px] text-muted-foreground">Options that trigger abnormal flags when reported.</p>
                                <InputError message={errors.abnormal_options} />
                            </div>
                        </div>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="param_order" className="text-xs font-medium">
                                Display Order
                            </Label>
                            <Input
                                id="param_order"
                                type="number"
                                min={0}
                                value={data.display_order}
                                onChange={(e) => setData('display_order', e.target.value)}
                                disabled={processing}
                            />
                            <p className="text-[11px] text-muted-foreground">Sequence on report and result entry screens.</p>
                            <InputError message={errors.display_order} />
                        </div>

                        <div className="flex flex-col justify-center space-y-2 pt-2">
                            <div className="flex items-center justify-between rounded-lg border p-2.5">
                                <div className="space-y-0.5">
                                    <Label htmlFor="param_active" className="text-xs font-medium cursor-pointer">
                                        Parameter Active
                                    </Label>
                                    <p className="text-[11px] text-muted-foreground">Active parameters appear on order result forms.</p>
                                </div>
                                <Switch
                                    id="param_active"
                                    checked={data.active !== false}
                                    onCheckedChange={(checked) => setData('active', checked)}
                                    disabled={processing}
                                />
                            </div>
                        </div>
                    </div>

                    <DialogFooter className="gap-2 pt-2 sm:gap-0">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="mr-2 h-4 w-4 animate-spin" />}
                            {isEditing ? 'Save Changes' : 'Add Parameter'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
