import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface CancelDispensingDialogProps {
    prescriptionOrderId: number;
    trigger: ReactNode;
}

/**
 * Cancels the prescription's current in-progress dispensing event (spec
 * §25) — a reason is required (enforced server-side too). Never deletes;
 * the event remains visible in the dispensing history. Mirrors
 * CancelPrescriptionOrderDialog exactly.
 */
export function CancelDispensingDialog({ prescriptionOrderId, trigger }: CancelDispensingDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm({ cancellation_reason: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('pharmacy-dispensing.cancel', prescriptionOrderId), {
            onSuccess: () => reset(),
            preserveScroll: true,
        });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Cancel dispensing</DialogTitle>
                        <DialogDescription>Please provide a reason for cancelling this dispensing event.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2 py-4">
                        <Label htmlFor="dispensing_cancellation_reason">Reason *</Label>
                        <Textarea
                            id="dispensing_cancellation_reason"
                            value={data.cancellation_reason}
                            onChange={(e) => setData('cancellation_reason', e.target.value)}
                            rows={3}
                            autoFocus
                        />
                        <InputError message={errors.cancellation_reason} />
                    </div>

                    <DialogFooter>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Cancel dispensing
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
