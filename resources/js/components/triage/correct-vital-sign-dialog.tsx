import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type VitalSign, type VitalSignFormData } from '@/types/triage';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode, useState } from 'react';

interface CorrectVitalSignDialogProps {
    encounterId: number;
    vitalSign: VitalSign;
    trigger: ReactNode;
}

const FIELDS: { key: keyof VitalSignFormData; label: string; unit: string; step?: string }[] = [
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
 * Correcting an observation never edits the original row — it creates a new
 * one that points back at it via corrects_vital_sign_id, pre-filled from the
 * original's values so the corrector only has to change what was wrong
 * (spec §19). A reason is always required.
 */
export function CorrectVitalSignDialog({ encounterId, vitalSign, trigger }: CorrectVitalSignDialogProps) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm<VitalSignFormData & { correction_reason: string }>({
        temperature_c: vitalSign.temperature_c?.toString() ?? '',
        systolic_mmhg: vitalSign.systolic_mmhg?.toString() ?? '',
        diastolic_mmhg: vitalSign.diastolic_mmhg?.toString() ?? '',
        pulse_bpm: vitalSign.pulse_bpm?.toString() ?? '',
        respiratory_rate_bpm: vitalSign.respiratory_rate_bpm?.toString() ?? '',
        oxygen_saturation_pct: vitalSign.oxygen_saturation_pct?.toString() ?? '',
        weight_kg: vitalSign.weight_kg?.toString() ?? '',
        height_cm: vitalSign.height_cm?.toString() ?? '',
        correction_reason: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('clinical-encounters.vital-signs.correct', [encounterId, vitalSign.id]), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-w-2xl">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Correct vital-sign observation</DialogTitle>
                        <DialogDescription>
                            The original observation is preserved unchanged. This creates a new, corrected observation and records why.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                            {FIELDS.map((field) => (
                                <div key={field.key} className="grid gap-1.5">
                                    <Label htmlFor={`correct-${field.key}`}>
                                        {field.label} <span className="text-muted-foreground font-normal">({field.unit})</span>
                                    </Label>
                                    <Input
                                        id={`correct-${field.key}`}
                                        type="number"
                                        step={field.step ?? '1'}
                                        value={data[field.key]}
                                        onChange={(e) => setData(field.key, e.target.value)}
                                    />
                                    <InputError message={errors[field.key]} />
                                </div>
                            ))}
                        </div>

                        <InputError message={errors.measurements} />

                        <div className="grid gap-1.5">
                            <Label htmlFor="correction_reason">Reason for correction *</Label>
                            <Textarea
                                id="correction_reason"
                                value={data.correction_reason}
                                onChange={(e) => setData('correction_reason', e.target.value)}
                                rows={2}
                            />
                            <InputError message={errors.correction_reason} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Save Correction
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
