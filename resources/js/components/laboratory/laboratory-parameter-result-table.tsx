import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { laboratoryAbnormalFlagLabel } from '@/lib/laboratory-status';
import { type LaboratoryAbnormalFlag, type LaboratoryResultValue, type ParameterValueEntry } from '@/types/laboratory';
import { LaboratoryResultValueInput } from './laboratory-result-value-input';
import { cn } from '@/lib/utils';

interface ActiveParameterRange {
    id: number;
    sex: string;
    specimen_source?: string | null;
    age_min_days: number | null;
    age_max_days: number | null;
    low: string | null;
    high: string | null;
    critical_low: string | null;
    critical_high: string | null;
}

interface ActiveParameter {
    id: number;
    name: string;
    code?: string;
    data_type: 'numeric' | 'text' | 'option' | 'enum' | 'boolean' | 'positive_negative' | 'qualitative';
    unit?: string | null;
    decimal_places?: number | null;
    options_json?: string[] | null;
    display_order?: number | null;
    reference_ranges?: ActiveParameterRange[] | null;
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
    patient?: {
        gender?: string | null;
        date_of_birth?: string | null;
    } | null;
    specimenSource?: string | null;
}

function flagVariant(flag: LaboratoryAbnormalFlag | null): 'destructive' | 'default' | 'secondary' | 'outline' {
    if (flag === 'critical' || flag === 'critical_low' || flag === 'critical_high') return 'destructive';
    if (flag === 'low' || flag === 'high' || flag === 'abnormal') return 'default';
    if (flag === 'unconfigured') return 'outline';
    return 'secondary';
}

function formatRefRange(low: string | null, high: string | null): string {
    if (low !== null && high !== null) return `${low} – ${high}`;
    if (low !== null) return `≥ ${low}`;
    if (high !== null) return `≤ ${high}`;
    return '—';
}

function resolveCatalogRange(
    ranges: ActiveParameterRange[] | null | undefined,
    patient?: { gender?: string | null; date_of_birth?: string | null } | null,
    specimenSource?: string | null
): ActiveParameterRange | null {
    if (!ranges || ranges.length === 0) return null;

    const patientSex = (patient?.gender ?? '').toLowerCase();
    const cleanSpecimenSource = specimenSource ? specimenSource.toLowerCase() : null;

    let ageInDays: number | null = null;
    if (patient?.date_of_birth) {
        const dob = new Date(patient.date_of_birth);
        if (!isNaN(dob.getTime())) {
            ageInDays = Math.floor((Date.now() - dob.getTime()) / (24 * 60 * 60 * 1000));
        }
    }

    const matches = ranges.filter((r) => {
        const sexMatch = r.sex === 'any' || (patientSex && r.sex === patientSex);
        if (!sexMatch) return false;

        const rangeSource = r.specimen_source ? r.specimen_source.toLowerCase() : 'any';
        if (cleanSpecimenSource && rangeSource !== 'any' && rangeSource !== cleanSpecimenSource) {
            return false;
        }

        if (ageInDays !== null) {
            const minOk = r.age_min_days === null || ageInDays >= r.age_min_days;
            const maxOk = r.age_max_days === null || ageInDays <= r.age_max_days;
            return minOk && maxOk;
        }

        return true;
    });

    if (matches.length === 0) return ranges[0] ?? null;

    if (cleanSpecimenSource) {
        const exactSourceAndSex = matches.find((r) => r.specimen_source?.toLowerCase() === cleanSpecimenSource && r.sex === patientSex);
        if (exactSourceAndSex) return exactSourceAndSex;

        const exactSourceAnySex = matches.find((r) => r.specimen_source?.toLowerCase() === cleanSpecimenSource);
        if (exactSourceAnySex) return exactSourceAnySex;
    }

    return (
        matches.find((r) => patientSex && r.sex === patientSex) ??
        matches[0] ??
        null
    );
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
    patient = null,
    specimenSource = null,
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
                        <th className="pb-2 pr-4 font-medium">Reference Range</th>
                        <th className="pb-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {activeParameters.map((param) => {
                        const savedRv = savedByParamId.get(param.id) ?? null;
                        const entry = parameterValues.find((e) => e.parameter_id === param.id);
                        const errorKey = `parameter_values.${parameterValues.findIndex((e) => e.parameter_id === param.id)}`;
                        
                        const unit = savedRv?.unit ?? param.unit ?? null;
                        
                        // Check if saved row has snapshot range, or resolve from catalog ranges
                        const catalogRange = resolveCatalogRange(param.reference_ranges, patient, specimenSource);
                        const refLow = savedRv?.reference_low ?? catalogRange?.low ?? null;
                        const refHigh = savedRv?.reference_high ?? catalogRange?.high ?? null;
                        const refCritLow = savedRv?.reference_critical_low ?? catalogRange?.critical_low ?? null;
                        const refCritHigh = savedRv?.reference_critical_high ?? catalogRange?.critical_high ?? null;
                        const flag = savedRv?.abnormal_flag ?? null;

                        const isNumeric = param.data_type === 'numeric';
                        const isOption = param.data_type === 'option' || param.data_type === 'enum' || param.data_type === 'boolean' || param.data_type === 'positive_negative';
                        const isText = param.data_type === 'text' || param.data_type === 'qualitative';

                        const hasError = !!errors[`${errorKey}.value_numeric`] || !!errors[`${errorKey}.value_text`] || !!errors[`${errorKey}.value_code`];

                        return (
                            <tr key={param.id} className={cn(
                                "align-top transition-colors hover:bg-muted/50",
                                hasError && "bg-destructive/10 hover:bg-destructive/20 transition-all duration-300"
                            )}>
                                <td className="py-3 pr-4 font-medium">
                                    <div>{param.name}</div>
                                    {param.code && param.code !== param.name && (
                                        <div className="text-xs text-muted-foreground font-mono">{param.code}</div>
                                    )}
                                </td>

                                <td className="py-3 pr-4">
                                    {disabled ? (
                                        <span className="font-medium">
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

                                <td className="text-muted-foreground py-3 pr-4 font-medium">
                                    {unit ? <span>{unit}</span> : <span className="text-muted-foreground/60">—</span>}
                                </td>

                                <td className="py-3 pr-4">
                                    {isNumeric ? (
                                        refLow !== null || refHigh !== null ? (
                                            <span className="text-muted-foreground">
                                                {catalogRange?.specimen_source && catalogRange.specimen_source !== 'any' && (
                                                    <span className="capitalize text-xs font-semibold mr-1 text-primary">
                                                        [{catalogRange.specimen_source}]
                                                    </span>
                                                )}
                                                {formatRefRange(refLow, refHigh)}
                                                {(refCritLow !== null || refCritHigh !== null) && (
                                                    <span className="text-destructive ml-1 text-xs">
                                                        (crit: {formatRefRange(refCritLow, refCritHigh)})
                                                    </span>
                                                )}
                                            </span>
                                        ) : (
                                            <span className="text-amber-600 dark:text-amber-400 font-medium text-xs italic">
                                                Reference range not configured
                                            </span>
                                        )
                                    ) : isOption ? (
                                        <span className="text-muted-foreground text-xs">
                                            Categorical ({param.options_json?.length ?? 0} options)
                                        </span>
                                    ) : isText ? (
                                        <span className="text-muted-foreground text-xs">
                                            Descriptive / Qualitative
                                        </span>
                                    ) : (
                                        <span className="text-muted-foreground">—</span>
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
