import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode, useState } from 'react';

interface CancelProcedureOrderDialogProps {
    encounterId: number;
    orderId: number;
    orderNumber: string;
    procedureName: string;
    trigger: ReactNode;
}

export function CancelProcedureOrderDialog({
    encounterId,
    orderId,
    orderNumber,
    procedureName,
    trigger,
}: CancelProcedureOrderDialogProps) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ cancellation_reason: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('clinical-encounters.procedure-orders.cancel', [encounterId, orderId]), {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
            preserveScroll: true,
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Cancel Clinical Procedure Order</DialogTitle>
                        <DialogDescription>
                            Cancel order <strong>{orderNumber}</strong> ({procedureName}). Any linked unposted charges will be voided.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2 py-4">
                        <Label htmlFor="proc_order_cancellation_reason">Cancellation reason *</Label>
                        <Textarea
                            id="proc_order_cancellation_reason"
                            value={data.cancellation_reason}
                            onChange={(e) => setData('cancellation_reason', e.target.value)}
                            placeholder="Provide clinical or administrative justification for cancellation..."
                            rows={3}
                            autoFocus
                        />
                        <InputError message={errors.cancellation_reason} />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                            Keep Order
                        </Button>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            {processing && <LoaderCircle className="mr-1.5 h-4 w-4 animate-spin" />}
                            Cancel Order
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
