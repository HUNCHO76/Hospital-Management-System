import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useToast } from '@/hooks/use-toast';
import { useForm } from '@inertiajs/react';
import { LoaderCircle, XCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface ReturnPrescriptionDialogProps {
    prescriptionOrderId: number;
    trigger: ReactNode;
}

/**
 * A return reason is always mandatory (spec §13) — the backend rejects an
 * empty one regardless of this client-side check, mirroring
 * CancelPrescriptionOrderDialog's identical "never a silent status change
 * without an explanation" shape.
 */
export function ReturnPrescriptionDialog({ prescriptionOrderId, trigger }: ReturnPrescriptionDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm({ return_reason: '' });
    const { toast } = useToast();

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('pharmacy-verification.return', prescriptionOrderId), {
            onSuccess: () => reset(),
            onError: (errs) => {
                const nonFieldError = errs.self_verification ?? errs.status;
                if (nonFieldError) {
                    toast({ variant: 'error', description: nonFieldError });
                }
            },
        });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Return Prescription</DialogTitle>
                        <DialogDescription>Explain what needs to be corrected before this prescription can be verified.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2 py-4">
                        <Label htmlFor="return_reason">Reason for returning this prescription *</Label>
                        <Textarea
                            id="return_reason"
                            value={data.return_reason}
                            onChange={(e) => setData('return_reason', e.target.value)}
                            rows={3}
                            placeholder="e.g. Quantity does not match prescribed duration."
                            autoFocus
                        />
                        <InputError message={errors.return_reason} />
                    </div>

                    <DialogFooter>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            {processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <XCircle className="h-4 w-4" />}
                            Return Prescription
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
