import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type EncounterDiagnosis } from '@/types/diagnosis';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode, useState } from 'react';

interface CorrectDiagnosisDialogProps {
    encounterId: number;
    diagnosis: EncounterDiagnosis;
    trigger: ReactNode;
}

/**
 * The controlled correction workflow for a confirmed/resolved diagnosis
 * (spec §22) — the clinical note is edited in place, but the change is fully
 * captured by the audit log's old/new diff, and who/when/why is stamped on
 * the record itself. A reason is always required. The diagnosis code itself
 * is intentionally not editable here — replacing the code on a finalized
 * diagnosis is significant enough to warrant cancelling and recording a new
 * one instead.
 */
export function CorrectDiagnosisDialog({ encounterId, diagnosis, trigger }: CorrectDiagnosisDialogProps) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        clinical_note: diagnosis.clinical_note ?? '',
        correction_reason: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('clinical-encounters.diagnoses.correct', [encounterId, diagnosis.id]), {
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
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Correct diagnosis</DialogTitle>
                        <DialogDescription>
                            This diagnosis is {diagnosis.status}. Amend only what is clinically necessary and record why.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="correct_clinical_note">Clinical Note</Label>
                            <Textarea
                                id="correct_clinical_note"
                                value={data.clinical_note}
                                onChange={(e) => setData('clinical_note', e.target.value)}
                                rows={3}
                            />
                            <InputError message={errors.clinical_note} />
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="diagnosis_correction_reason">Reason for correction *</Label>
                            <Textarea
                                id="diagnosis_correction_reason"
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
