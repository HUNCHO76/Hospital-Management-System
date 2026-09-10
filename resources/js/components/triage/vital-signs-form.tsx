import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type VitalSignFormData } from '@/types/triage';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, useMemo } from 'react';

interface VitalSignsFormProps {
    encounterId: number;
}

type MeasurementKey = Exclude<keyof VitalSignFormData, number>;

const FIELDS: { key: MeasurementKey; label: string; unit: string; step?: string }[] = [
    { key: 'temperature_c', label: 'Temperature', unit: '°C', step: '0.1' },
    { key: 'systolic_mmhg', label: 'Systolic', unit: 'mmHg' },
    { key: 'diastolic_mmhg', label: 'Diastolic', unit: 'mmHg' },
    { key: 'pulse_bpm', label: 'Pulse', unit: 'bpm' },
    { key: 'respiratory_rate_bpm', label: 'Respiratory Rate', unit: 'breaths/min' },
    { key: 'oxygen_saturation_pct', label: 'SpO₂', unit: '%' },
    { key: 'weight_kg', label: 'Weight', unit: 'kg', step: '0.01' },
    { key: 'height_cm', label: 'Height', unit: 'cm', step: '0.1' },
];

/**
 * Records one new vital-sign observation — every previous observation stays
 * in history (spec §18), this never edits an existing row. BMI is shown
 * here only as a live preview; the server always recalculates it
 * authoritatively from weight/height (spec §7).
 */
export function VitalSignsForm({ encounterId }: VitalSignsFormProps) {
    const { data, setData, post, processing, errors, reset } = useForm<VitalSignFormData>({
        temperature_c: '',
        systolic_mmhg: '',
        diastolic_mmhg: '',
        pulse_bpm: '',
        respiratory_rate_bpm: '',
        oxygen_saturation_pct: '',
        weight_kg: '',
        height_cm: '',
    });

    const bmiPreview = useMemo(() => {
        const weight = parseFloat(data.weight_kg);
        const heightCm = parseFloat(data.height_cm);

        if (!weight || !heightCm) {
            return null;
        }

        const heightM = heightCm / 100;

        return (weight / (heightM * heightM)).toFixed(2);
    }, [data.weight_kg, data.height_cm]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (processing) {
            return;
        }

        post(route('clinical-encounters.vital-signs.store', encounterId), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                {FIELDS.map((field) => (
                    <div key={field.key} className="grid gap-1.5">
                        <Label htmlFor={field.key}>
                            {field.label} <span className="text-muted-foreground font-normal">({field.unit})</span>
                        </Label>
                        <Input
                            id={field.key}
                            type="number"
                            step={field.step ?? '1'}
                            value={data[field.key]}
                            onChange={(e) => setData(field.key, e.target.value)}
                        />
                        <InputError message={errors[field.key]} />
                    </div>
                ))}
            </div>

            {bmiPreview && (
                <p className="text-muted-foreground text-sm">
                    BMI preview: <span className="text-foreground font-medium">{bmiPreview}</span>
                </p>
            )}

            <InputError message={errors.measurements} />

            <div className="flex justify-end">
                <Button type="submit" disabled={processing} size="sm">
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Record Vital Signs
                </Button>
            </div>
        </form>
    );
}
