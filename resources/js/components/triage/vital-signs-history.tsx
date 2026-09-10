import { CorrectVitalSignDialog } from '@/components/triage/correct-vital-sign-dialog';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { vitalSignFlagClassName, vitalSignFlagLabel } from '@/lib/triage-status';
import { type VitalSign } from '@/types/triage';
import { Pencil } from 'lucide-react';

interface VitalSignsHistoryProps {
    encounterId: number;
    vitalSigns: VitalSign[];
}

const MEASUREMENTS: { key: keyof VitalSign; label: string; unit: string; format?: (value: number) => string }[] = [
    { key: 'temperature_c', label: 'Temp', unit: '°C' },
    { key: 'pulse_bpm', label: 'Pulse', unit: 'bpm' },
    { key: 'respiratory_rate_bpm', label: 'RR', unit: 'breaths/min' },
    { key: 'oxygen_saturation_pct', label: 'SpO₂', unit: '%' },
    { key: 'weight_kg', label: 'Weight', unit: 'kg' },
    { key: 'height_cm', label: 'Height', unit: 'cm' },
    { key: 'bmi', label: 'BMI', unit: '' },
];

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * Every observation ever recorded stays visible, oldest first — nothing is
 * ever overwritten (spec §18/§19). A corrected observation is marked but
 * still shown; the correction appears as its own later entry.
 */
export function VitalSignsHistory({ encounterId, vitalSigns }: VitalSignsHistoryProps) {
    const { can } = usePermissions();

    if (vitalSigns.length === 0) {
        return <p className="text-muted-foreground text-sm">No vital signs recorded yet.</p>;
    }

    const findOriginal = (id: number) => vitalSigns.find((v) => v.id === id);

    return (
        <div className="space-y-3">
            {vitalSigns.map((vs) => {
                const original = vs.corrects_vital_sign_id ? findOriginal(vs.corrects_vital_sign_id) : undefined;
                const bloodPressure = vs.systolic_mmhg !== null && vs.diastolic_mmhg !== null ? `${vs.systolic_mmhg}/${vs.diastolic_mmhg}` : null;
                const bpFlag =
                    vs.flags.systolic_mmhg === 'critical' || vs.flags.diastolic_mmhg === 'critical'
                        ? 'critical'
                        : vs.flags.systolic_mmhg === 'high' || vs.flags.diastolic_mmhg === 'high'
                          ? 'high'
                          : vs.flags.systolic_mmhg === 'low' || vs.flags.diastolic_mmhg === 'low'
                            ? 'low'
                            : 'normal';

                return (
                    <div key={vs.id} className="rounded-md border p-3 text-sm">
                        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <div className="flex items-center gap-2">
                                <span className="font-medium">{formatDateTime(vs.recorded_at)}</span>
                                {vs.recorded_by && <span className="text-muted-foreground text-xs">by {vs.recorded_by.name}</span>}
                            </div>

                            <div className="flex items-center gap-2">
                                {vs.corrects_vital_sign_id && (
                                    <span className="text-muted-foreground text-xs italic">
                                        Correction{original ? ` of ${formatDateTime(original.recorded_at)}` : ''}
                                        {vs.correction_reason ? ` — ${vs.correction_reason}` : ''}
                                    </span>
                                )}
                                {vs.is_corrected && <span className="text-muted-foreground text-xs italic">Corrected — see newer entry</span>}
                                {can('vital-signs.correct') && !vs.is_corrected && (
                                    <CorrectVitalSignDialog
                                        encounterId={encounterId}
                                        vitalSign={vs}
                                        trigger={
                                            <Button variant="ghost" size="sm">
                                                <Pencil className="h-3.5 w-3.5" />
                                                Correct
                                            </Button>
                                        }
                                    />
                                )}
                            </div>
                        </div>

                        <dl className="flex flex-wrap gap-x-6 gap-y-1">
                            {bloodPressure && (
                                <div className="flex items-baseline gap-1">
                                    <dt className="text-muted-foreground">BP:</dt>
                                    <dd className={vitalSignFlagClassName[bpFlag]}>
                                        {bloodPressure} mmHg
                                        {bpFlag !== 'normal' && <span className="ml-1">({vitalSignFlagLabel[bpFlag]})</span>}
                                    </dd>
                                </div>
                            )}
                            {MEASUREMENTS.map((measurement) => {
                                const value = vs[measurement.key];

                                if (value === null || value === undefined) {
                                    return null;
                                }

                                const flag = measurement.key === 'bmi' ? undefined : vs.flags[measurement.key as keyof VitalSign['flags']];

                                return (
                                    <div key={measurement.key} className="flex items-baseline gap-1">
                                        <dt className="text-muted-foreground">{measurement.label}:</dt>
                                        <dd className={flag ? vitalSignFlagClassName[flag] : ''}>
                                            {String(value)}
                                            {measurement.unit ? ` ${measurement.unit}` : ''}
                                            {flag && flag !== 'normal' && <span className="ml-1">({vitalSignFlagLabel[flag]})</span>}
                                        </dd>
                                    </div>
                                );
                            })}
                        </dl>
                    </div>
                );
            })}
        </div>
    );
}
