import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { laboratoryAbnormalFlagLabel } from '@/lib/laboratory-status';
import { type LaboratoryAbnormalFlag, type LaboratoryResultValue, type ParameterValueEntry } from '@/types/laboratory';

interface LaboratoryParameterResultTableProps {
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
 * Each row corresponds to one LaboratoryResultValue row (pre-seeded by
 * LaboratoryResultService::initializeForItem). Read-only mode (disabled=true)
 * renders the saved values with flag badges for the verification/release views.
 */
export function LaboratoryParameterResultTable({
    resultValues,
    parameterValues,
    onChangeParameterValue,
    disabled = false,
    errors = {},
}: LaboratoryParameterResultTableProps) {
    if (resultValues.length === 0) {
        return <p className="text-muted-foreground text-sm">No parameters configured for this test.</p>;
    }

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b text-left">
                        <th className="pb-2 pr-4 font-medium">Parameter</th>
                        <th className="pb-2 pr-4 font-medium">Result</th>
                        <th className="pb-2 pr-4 font-medium">Unit</th>
                        <th className="pb-2 pr-4 font-medium">Reference Range</th>
                        <th className="pb-2 font-medium">Flag</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {resultValues.map((rv) => {
                        const param = rv.parameter;
                        const entry = parameterValues.find((e) => e.parameter_id === rv.laboratory_test_parameter_id);
                        const errorKey = `parameter_values.${parameterValues.findIndex((e) => e.parameter_id === rv.laboratory_test_parameter_id)}`;

                        return (
                            <tr key={rv.id} className="align-top">
                                <td className="py-2 pr-4 font-medium">
                                    {param?.name ?? `#${rv.laboratory_test_parameter_id}`}
                                </td>

                                <td className="py-2 pr-4">
                                    {disabled ? (
                                        <span>
                                            {rv.value_numeric ?? rv.value_text ?? rv.value_code ?? (
                                                <span className="text-muted-foreground">—</span>
                                            )}
                                        </span>
                                    ) : param?.data_type === 'numeric' ? (
                                        <div>
                                            <Input
                                                type="number"
                                                step="any"
                                                className="w-28"
                                                value={entry?.value_numeric ?? ''}
                                                onChange={(e) =>
                                                    onChangeParameterValue(rv.laboratory_test_parameter_id, 'value_numeric', e.target.value)
                                                }
                                                disabled={disabled}
                                            />
                                            {errors[`${errorKey}.value_numeric`] && (
                                                <p className="text-destructive mt-1 text-xs">{errors[`${errorKey}.value_numeric`]}</p>
                                            )}
                                        </div>
                                    ) : param?.data_type === 'option' && param.options_json ? (
                                        <div>
                                            <Select
                                                value={entry?.value_code ?? ''}
                                                onValueChange={(value) =>
                                                    onChangeParameterValue(rv.laboratory_test_parameter_id, 'value_code', value)
                                                }
                                                disabled={disabled}
                                            >
                                                <SelectTrigger className="w-36">
                                                    <SelectValue placeholder="Select" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {param.options_json.map((opt) => (
                                                        <SelectItem key={opt} value={opt}>
                                                            {opt}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            {errors[`${errorKey}.value_code`] && (
                                                <p className="text-destructive mt-1 text-xs">{errors[`${errorKey}.value_code`]}</p>
                                            )}
                                        </div>
                                    ) : (
                                        <div>
                                            <Textarea
                                                rows={1}
                                                className="w-48"
                                                value={entry?.value_text ?? ''}
                                                onChange={(e) =>
                                                    onChangeParameterValue(rv.laboratory_test_parameter_id, 'value_text', e.target.value)
                                                }
                                                disabled={disabled}
                                            />
                                            {errors[`${errorKey}.value_text`] && (
                                                <p className="text-destructive mt-1 text-xs">{errors[`${errorKey}.value_text`]}</p>
                                            )}
                                        </div>
                                    )}
                                </td>

                                <td className="text-muted-foreground py-2 pr-4">{rv.unit ?? param?.unit ?? '—'}</td>

                                <td className="text-muted-foreground py-2 pr-4">
                                    {formatRefRange(rv.reference_low, rv.reference_high)}
                                    {(rv.reference_critical_low !== null || rv.reference_critical_high !== null) && (
                                        <span className="text-destructive ml-1 text-xs">
                                            (crit: {formatRefRange(rv.reference_critical_low, rv.reference_critical_high)})
                                        </span>
                                    )}
                                </td>

                                <td className="py-2">
                                    {rv.abnormal_flag && rv.abnormal_flag !== 'normal' ? (
                                        <Badge variant={flagVariant(rv.abnormal_flag)}>
                                            {laboratoryAbnormalFlagLabel[rv.abnormal_flag]}
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
