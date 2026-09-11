import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { useForm } from '@inertiajs/react';
import { CheckCircle2, LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface RadiologyExaminationCompleteDialogProps {
    appointmentId: number;
    trigger: ReactNode;
}

/**
 * Completion is a deliberate, confirmed action (Phase 5, Step 41, Part 30)
 * — technical information itself is saved separately via
 * RadiologyExaminationTechnicalForm before this is used; this dialog only
 * confirms the transition to COMPLETED (the Step 42 handoff point).
 */
export function RadiologyExaminationCompleteDialog({ appointmentId, trigger }: RadiologyExaminationCompleteDialogProps) {
    const { post, processing } = useForm({});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('radiology-examinations.complete', appointmentId), { preserveScroll: true });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Complete examination</DialogTitle>
                        <DialogDescription>
                            This marks the examination COMPLETED and makes it available for findings/reporting. Radiologist findings and
                            interpretation are not part of this step.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            <CheckCircle2 className="h-4 w-4" />
                            Complete Examination
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
