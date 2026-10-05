import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { laboratoryAbnormalFlagLabel } from '@/lib/laboratory-status';
import { type LaboratoryAbnormalFlag, type LaboratoryResultValue, type ParameterValueEntry } from '@/types/laboratory';
import { LaboratoryResultValueInput } from './laboratory-result-value-input';
import { cn } from '@/lib/utils';

interface ActiveParameter {
    id: number;
    name: string;
    code?: string;
    data_type: 'numeric' | 'text' | 'option' | 'enum' | 'boolean' | 'positive_negative' | 'qualitative';
    unit?: string | null;
    decimal_places?: number | null;
    options_json?: string[] | null;
    display_order?: number | null;
}

interface LaboratoryParameterResultTableProps {
    /** Catalog parameters — the authoritative list of what rows to show. */
    activeParameters: ActiveParameter[];
    /** Saved DB result_value rows — may be empty on a fresh result. */
    resultValues: LaboratoryResultValue[];
    parameterValues: ParameterValueEntry[];
    onChangeParameterValue: (parameterId: number, field: keyof Omit<ParameterValueEntry, 'parameter_id'>, value: string) => void;
    disabled?: boolean;
    errors?: Record<string, string>;
}

function flagVariant(flag: LaboratoryAbnormalFlag | null): 'destructive' | 'default' | 'secondary' {
    if (flag === 'critical') return 'destructive';
    if (flag === 'low' || flag === 'high' || flag === 'abnormal') return 'default';
    return 'secondary';
}

function formatRefRange(low: string | null, high: string | null): string {
    if (low !== null && high !== null) return `${low} – ${high}`;
    if (low !== null) return `≥ ${low}`;
    if (high !== null) return `≤ ${high}`;
    return '—';
}

/**
 * Renders a table of per-parameter result inputs for multi-component tests.
 * Uses `activeParameters` (catalog config) as the authoritative row list.
 * Merges in any already-saved `resultValues` DB rows for display/initial values.
 * Falls back to rendering empty inputs for parameters that haven't been saved yet.
 */
export function LaboratoryParameterResultTable({
    activeParameters,
    resultValues,
    parameterValues,
    onChangeParameterValue,
    disabled = false,
    errors = {},
}: LaboratoryParameterResultTableProps) {
    if (activeParameters.length === 0) {
        return <p className="text-muted-foreground text-sm">No parameters configured for this test.</p>;
    }

    // Build a lookup of saved DB rows keyed by parameter_id for O(1) merging.
    const savedByParamId = new Map(resultValues.map((rv) => [rv.laboratory_test_parameter_id, rv]));

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b text-left">
                        <th className="pb-2 pr-4 font-medium">Parameter</th>
                        <th className="pb-2 pr-4 font-medium">Result</th>
                        <th className="pb-2 pr-4 font-medium">Unit</th>
                        <th className="pb-2 pr-4 font-medium">Reference</th>
                        <th className="pb-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {activeParameters.map((param, paramIndex) => {
                        const savedRv = savedByParamId.get(param.id) ?? null;
                        const entry = parameterValues.find((e) => e.parameter_id === param.id);
                        const errorKey = `parameter_values.${parameterValues.findIndex((e) => e.parameter_id === param.id)}`;
                        // Display unit and reference range from the saved row if available;
                        // fall back to the catalog parameter for fresh (unsaved) rows.
                        const unit = savedRv?.unit ?? param.unit ?? null;
                        const refLow = savedRv?.reference_low ?? null;
                        const refHigh = savedRv?.reference_high ?? null;
                        const refCritLow = savedRv?.reference_critical_low ?? null;
                        const refCritHigh = savedRv?.reference_critical_high ?? null;
                        const flag = savedRv?.abnormal_flag ?? null;
                        
                        const hasError = !!errors[`${errorKey}.value_numeric`] || !!errors[`${errorKey}.value_text`] || !!errors[`${errorKey}.value_code`];

                        return (
                            <tr key={param.id} className={cn(
                                "align-top transition-colors hover:bg-muted/50",
                                hasError && "bg-destructive/10 hover:bg-destructive/20 transition-all duration-300"
                            )}>
                                <td className="py-3 pr-4 font-medium">
                                    {param.name}
                                </td>

                                <td className="py-3 pr-4">
                                    {disabled ? (
                                        <span>
                                            {savedRv?.value_numeric ?? savedRv?.value_text ?? savedRv?.value_code ?? (
                                                <span className="text-muted-foreground">—</span>
                                            )}
                                        </span>
                                    ) : (
                                        <LaboratoryResultValueInput
                                            hideLabel
                                            resultType={param.data_type as any}
                                            unit={unit}
                                            resultOptions={param.options_json ?? []}
                                            valueNumeric={entry?.value_numeric ?? ''}
                                            valueText={entry?.value_text ?? ''}
                                            valueCode={entry?.value_code ?? ''}
                                            onChangeNumeric={(val) => onChangeParameterValue(param.id, 'value_numeric', val)}
                                            onChangeText={(val) => onChangeParameterValue(param.id, 'value_text', val)}
                                            onChangeCode={(val) => onChangeParameterValue(param.id, 'value_code', val)}
                                            errors={{
                                                value_numeric: errors[`${errorKey}.value_numeric`],
                                                value_text: errors[`${errorKey}.value_text`],
                                                value_code: errors[`${errorKey}.value_code`],
                                            }}
                                            disabled={disabled}
                                        />
                                    )}
                                </td>

                                <td className="text-muted-foreground py-3 pr-4">{unit ?? '—'}</td>

                                <td className="text-muted-foreground py-3 pr-4">
                                    {formatRefRange(refLow, refHigh)}
                                    {(refCritLow !== null || refCritHigh !== null) && (
                                        <span className="text-destructive ml-1 text-xs">
                                            (crit: {formatRefRange(refCritLow, refCritHigh)})
                                        </span>
                                    )}
                                </td>

                                <td className="py-3">
                                    {flag && flag !== 'normal' ? (
                                        <Badge variant={flagVariant(flag)}>
                                            {laboratoryAbnormalFlagLabel[flag]}
                                        </Badge>
                                    ) : (
                                        <span className="text-muted-foreground">—</span>
                                    )}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
