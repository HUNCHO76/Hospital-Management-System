import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { CONSULTATION_FIELDS } from '@/lib/consultation-status';
import { type Consultation, type ConsultationCorrectionFormData } from '@/types/consultation';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode, useState } from 'react';

interface CorrectConsultationDialogProps {
    encounterId: number;
    consultation: Consultation;
    trigger: ReactNode;
}

/**
 * The controlled correction workflow for a completed consultation (spec
 * §24) — fields are edited in place, but the change is fully captured by the
 * audit log's old/new diff, and who/when/why is stamped on the record
 * itself. A reason is always required.
 */
export function CorrectConsultationDialog({ encounterId, consultation, trigger }: CorrectConsultationDialogProps) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm<ConsultationCorrectionFormData>({
        chief_complaint: consultation.chief_complaint ?? '',
        history_presenting_illness: consultation.history_presenting_illness ?? '',
        relevant_medical_history: consultation.relevant_medical_history ?? '',
        relevant_surgical_history: consultation.relevant_surgical_history ?? '',
        relevant_social_history: consultation.relevant_social_history ?? '',
        review_of_systems: consultation.review_of_systems ?? '',
        physical_examination: consultation.physical_examination ?? '',
        assessment_summary: consultation.assessment_summary ?? '',
        clinical_notes: consultation.clinical_notes ?? '',
        plan_summary: consultation.plan_summary ?? '',
        correction_reason: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('clinical-encounters.consultation.correct', encounterId), {
            preserveScroll: true,
            onSuccess: () => {
                reset('correction_reason');
                setOpen(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Correct consultation</DialogTitle>
                        <DialogDescription>This consultation is completed. Amend only what is clinically necessary and record why.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        {CONSULTATION_FIELDS.map((field) => (
                            <div key={field.key} className="grid gap-1.5">
                                <Label htmlFor={`correct-${field.key}`}>{field.label}</Label>
                                <Textarea
                                    id={`correct-${field.key}`}
                                    value={data[field.key]}
                                    onChange={(e) => setData(field.key, e.target.value)}
                                    rows={field.rows}
                                />
                                <InputError message={errors[field.key]} />
                            </div>
                        ))}

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
