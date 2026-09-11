import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface CancelRadiologyOrderDialogProps {
    encounterId: number;
    orderId: number;
    trigger: ReactNode;
}

/**
 * Cancellation requires a reason (enforced server-side too) — this is never
 * a silent status change without an explanation. Cascades to cancel every
 * non-cancelled item on the order. Mirrors CancelLaboratoryOrderDialog
 * exactly.
 */
export function CancelRadiologyOrderDialog({ encounterId, orderId, trigger }: CancelRadiologyOrderDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm({ cancellation_reason: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('clinical-encounters.radiology-orders.cancel', [encounterId, orderId]), {
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
                        <DialogTitle>Cancel radiology order</DialogTitle>
                        <DialogDescription>
                            Please provide a reason for cancelling this order. Every procedure on it will also be cancelled.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2 py-4">
                        <Label htmlFor="rad_order_cancellation_reason">Cancellation reason *</Label>
                        <Textarea
                            id="rad_order_cancellation_reason"
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
                            Cancel order
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
