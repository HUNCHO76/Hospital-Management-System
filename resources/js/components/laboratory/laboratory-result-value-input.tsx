import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Radio, RadioGroup } from '@/components/ui/radio-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { type LaboratoryResultType } from '@/types/laboratory';

interface LaboratoryResultValueInputProps {
    resultType: LaboratoryResultType | null;
    unit: string | null;
    resultOptions: string[] | null;
    valueNumeric: string;
    valueText: string;
    valueCode: string;
    onChangeNumeric: (value: string) => void;
    onChangeText: (value: string) => void;
    onChangeCode: (value: string) => void;
    errors: { value_numeric?: string; value_text?: string; value_code?: string };
    disabled?: boolean;
}

const DEFAULT_OPTIONS: Record<string, string[]> = {
    boolean: ['true', 'false'],
    positive_negative: ['positive', 'negative'],
};

function titleCase(value: string): string {
    return value
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

/**
 * Renders the correct input for a test's configured result_type (Phase 4,
 * Step 33, spec §6/§40) — the shape comes entirely from the test's own
 * catalog configuration (result_type/result_options), never a hard-coded
 * test name. Falls back to a plain textarea when a test has no configured
 * result_type at all, so entry is never blocked outright.
 */
export function LaboratoryResultValueInput({
    resultType,
    unit,
    resultOptions,
    valueNumeric,
    valueText,
    valueCode,
    onChangeNumeric,
    onChangeText,
    onChangeCode,
    errors,
    disabled,
}: LaboratoryResultValueInputProps) {
    if (resultType === 'numeric') {
        return (
            <div className="grid gap-2">
                <Label htmlFor="value_numeric">Result {unit && `(${unit})`}</Label>
                <Input
                    id="value_numeric"
                    type="number"
                    step="any"
                    value={valueNumeric}
                    onChange={(e) => onChangeNumeric(e.target.value)}
                    disabled={disabled}
                    className="max-w-xs"
                />
                <InputError message={errors.value_numeric} />
            </div>
        );
    }

    if (resultType === 'text') {
        return (
            <div className="grid gap-2">
                <Label htmlFor="value_text">Result</Label>
                <Textarea id="value_text" rows={4} value={valueText} onChange={(e) => onChangeText(e.target.value)} disabled={disabled} />
                <InputError message={errors.value_text} />
            </div>
        );
    }

    if (resultType === 'enum') {
        const options = resultOptions ?? [];

        return (
            <div className="grid gap-2">
                <Label>Result</Label>
                <Select value={valueCode} onValueChange={onChangeCode} disabled={disabled}>
                    <SelectTrigger className="max-w-xs">
                        <SelectValue placeholder="Select a value" />
                    </SelectTrigger>
                    <SelectContent>
                        {options.map((option) => (
                            <SelectItem key={option} value={option}>
                                {option}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.value_code} />
            </div>
        );
    }

    if (resultType === 'boolean' || resultType === 'positive_negative' || resultType === 'qualitative') {
        const options = resultOptions && resultOptions.length > 0 ? resultOptions : (DEFAULT_OPTIONS[resultType] ?? []);

        return (
            <div className="grid gap-2">
                <Label>Result</Label>
                <RadioGroup>
                    {options.map((option) => (
                        <Radio
                            key={option}
                            name="value_code"
                            value={option}
                            label={titleCase(option)}
                            checked={valueCode === option}
                            onChange={() => onChangeCode(option)}
                            disabled={disabled}
                        />
                    ))}
                </RadioGroup>
                <InputError message={errors.value_code} />
            </div>
        );
    }

    return (
        <div className="grid gap-2">
            <Label htmlFor="value_text">Result</Label>
            <p className="text-muted-foreground text-xs">This test has no configured result type — entering free text.</p>
            <Textarea id="value_text" rows={4} value={valueText} onChange={(e) => onChangeText(e.target.value)} disabled={disabled} />
            <InputError message={errors.value_text} />
        </div>
    );
}
