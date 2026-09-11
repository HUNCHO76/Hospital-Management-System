import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { useForm } from '@inertiajs/react';
import { CheckCircle2, LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface RadiologySubmitReportDialogProps {
    reportId: number;
    trigger: ReactNode;
}

/**
 * Submission is a deliberate, confirmed action (Phase 5, Step 42, Part 13)
 * — once submitted, the report is no longer freely editable (Part 17). The
 * server re-validates findings/impression presence and examination
 * eligibility regardless of what this dialog shows.
 */
export function RadiologySubmitReportDialog({ reportId, trigger }: RadiologySubmitReportDialogProps) {
    const { post, processing } = useForm({});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('radiology-results.submit', reportId), { preserveScroll: true });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Submit report</DialogTitle>
                        <DialogDescription>
                            This submits the report for verification and locks it from further editing. Findings and impression must both be present.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            <CheckCircle2 className="h-4 w-4" />
                            Submit Report
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
