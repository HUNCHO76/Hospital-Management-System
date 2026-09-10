import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type CarePlan } from '@/types/care-plan';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode, useState } from 'react';

interface CorrectCarePlanDialogProps {
    encounterId: number;
    carePlan: CarePlan;
    trigger: ReactNode;
}

/**
 * The controlled correction workflow for a completed treatment plan (spec
 * §31) — fields are edited in place, but the change is fully captured by the
 * audit log's old/new diff, and who/when/why is stamped on the record
 * itself. A reason is always required.
 */
export function CorrectCarePlanDialog({ encounterId, carePlan, trigger }: CorrectCarePlanDialogProps) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        goals: carePlan.goals ?? '',
        clinical_instructions: carePlan.clinical_instructions ?? '',
        monitoring_instructions: carePlan.monitoring_instructions ?? '',
        follow_up_instructions: carePlan.follow_up_instructions ?? '',
        correction_reason: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('clinical-encounters.care-plan.correct', encounterId), {
            preserveScroll: true,
            onSuccess: () => {
                reset('correction_reason');
                setOpen(false);
            },
        });
    };

    const FIELDS: { key: keyof typeof data; label: string }[] = [
        { key: 'goals', label: 'Goals' },
        { key: 'clinical_instructions', label: 'Clinical Instructions' },
        { key: 'monitoring_instructions', label: 'Monitoring Instructions' },
        { key: 'follow_up_instructions', label: 'Follow-up Instructions' },
    ];

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Correct treatment plan</DialogTitle>
                        <DialogDescription>This plan is completed. Amend only what is clinically necessary and record why.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        {FIELDS.map((field) => (
                            <div key={field.key} className="grid gap-1.5">
                                <Label htmlFor={`correct-${field.key}`}>{field.label}</Label>
                                <Textarea
                                    id={`correct-${field.key}`}
                                    value={data[field.key]}
                                    onChange={(e) => setData(field.key, e.target.value)}
                                    rows={3}
                                />
                                <InputError message={errors[field.key]} />
                            </div>
                        ))}

                        <div className="grid gap-1.5">
                            <Label htmlFor="care_plan_correction_reason">Reason for correction *</Label>
                            <Textarea
                                id="care_plan_correction_reason"
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
