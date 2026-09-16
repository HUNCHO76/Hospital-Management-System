import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface CancelPrescriptionOrderItemDialogProps {
    encounterId: number;
    orderId: number;
    itemId: number;
    trigger: ReactNode;
}

/**
 * Removes a single medication from a prescription, only while the order is
 * still in an editable state (enforced server-side) — removal requires a
 * reason. Mirrors CancelRadiologyOrderItemDialog exactly.
 */
export function CancelPrescriptionOrderItemDialog({ encounterId, orderId, itemId, trigger }: CancelPrescriptionOrderItemDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm({ cancellation_reason: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('clinical-encounters.prescription-orders.items.cancel', [encounterId, orderId, itemId]), {
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
                        <DialogTitle>Remove medication</DialogTitle>
                        <DialogDescription>Please provide a reason for removing this medication from the prescription.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2 py-4">
                        <Label htmlFor="prescription_order_item_cancellation_reason">Reason *</Label>
                        <Textarea
                            id="prescription_order_item_cancellation_reason"
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
                            Remove medication
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
