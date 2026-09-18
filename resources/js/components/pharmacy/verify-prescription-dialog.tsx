import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useToast } from '@/hooks/use-toast';
import { useForm } from '@inertiajs/react';
import { CheckCircle2, LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface VerifyPrescriptionDialogProps {
    prescriptionOrderId: number;
    trigger: ReactNode;
}

/**
 * Verification requires explicit confirmation (spec §23) — never a single
 * click with no acknowledgement of what it means. Mirrors
 * CancelPrescriptionOrderDialog's structure, using a confirmation dialog
 * rather than a bare button since this action commits the prescription as
 * eligible for dispensing.
 */
export function VerifyPrescriptionDialog({ prescriptionOrderId, trigger }: VerifyPrescriptionDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm({ verification_notes: '' });
    const { toast } = useToast();

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('pharmacy-verification.verify', prescriptionOrderId), {
            onSuccess: () => reset(),
            onError: (errs) => {
                const message =
                    errs.financial_authorization ??
                    errs.self_verification ??
                    errs.status ??
                    Object.values(errs)[0] ??
                    'Verification failed. Please try again.';
                toast({ variant: 'error', description: message });
            },
        });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Verify Prescription?</DialogTitle>
                        <DialogDescription>
                            You are confirming that this prescription has been reviewed and is eligible to proceed to dispensing.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2 py-4">
                        <Label htmlFor="verification_notes">Notes</Label>
                        <Textarea
                            id="verification_notes"
                            value={data.verification_notes}
                            onChange={(e) => setData('verification_notes', e.target.value)}
                            rows={3}
                            placeholder="Optional"
                            autoFocus
                        />
                        <InputError message={errors.verification_notes} />
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="h-4 w-4" />}
                            Confirm Verification
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
