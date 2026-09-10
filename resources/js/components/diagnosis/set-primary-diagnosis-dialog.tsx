import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type ReactNode, useState } from 'react';

interface SetPrimaryDiagnosisDialogProps {
    encounterId: number;
    diagnosisId: number;
    hasExistingPrimary: boolean;
    trigger: ReactNode;
}

/**
 * Promoting a diagnosis to primary is never silent — when another active
 * primary already exists, this confirms the doctor intends to replace it
 * (spec §26: "do not allow accidental replacement of the primary diagnosis
 * without confirmation").
 */
export function SetPrimaryDiagnosisDialog({ encounterId, diagnosisId, hasExistingPrimary, trigger }: SetPrimaryDiagnosisDialogProps) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const confirm = () => {
        setProcessing(true);
        router.post(
            route('clinical-encounters.diagnoses.primary', [encounterId, diagnosisId]),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setProcessing(false);
                    setOpen(false);
                },
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Set as primary diagnosis</DialogTitle>
                    <DialogDescription>
                        {hasExistingPrimary
                            ? 'This will replace the current primary diagnosis, which will become secondary instead. This action is recorded in the audit log.'
                            : 'This diagnosis will be marked as the primary diagnosis for this encounter.'}
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter>
                    <Button type="button" onClick={confirm} disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Confirm
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
