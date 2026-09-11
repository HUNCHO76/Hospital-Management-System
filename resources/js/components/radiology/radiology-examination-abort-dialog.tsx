import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { radiologyAbortReasonLabel } from '@/lib/radiology-status';
import { type RadiologyAbortReason } from '@/types/radiology';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface RadiologyExaminationAbortDialogProps {
    appointmentId: number;
}

const ABORT_REASONS: RadiologyAbortReason[] = [
    'patient_unable_to_tolerate',
    'equipment_problem',
    'patient_condition_changed',
    'patient_refused',
    'incorrect_preparation',
    'safety_concern',
    'technical_failure',
    'other',
];

/**
 * Aborting an examination always requires a reason (Phase 5, Step 41, Part
 * 14) — never a one-click, unconfirmed action. Never deletes the
 * examination; it remains ABORTED and fully historically traceable.
 */
export function RadiologyExaminationAbortDialog({ appointmentId }: RadiologyExaminationAbortDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<{ reason: RadiologyAbortReason | '' }>({ reason: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('radiology-examinations.abort', appointmentId), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="destructive">Abort Examination</Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Abort examination</DialogTitle>
                        <DialogDescription>The order is not cancelled — the procedure may be re-scheduled for a fresh attempt.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2 py-4">
                        <Label htmlFor="abort_reason">Reason *</Label>
                        <Select value={data.reason} onValueChange={(v) => setData('reason', v as RadiologyAbortReason)}>
                            <SelectTrigger id="abort_reason">
                                <SelectValue placeholder="Select a reason" />
                            </SelectTrigger>
                            <SelectContent>
                                {ABORT_REASONS.map((reason) => (
                                    <SelectItem key={reason} value={reason}>
                                        {radiologyAbortReasonLabel[reason]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.reason} />
                    </div>
                    <DialogFooter>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Abort Examination
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
