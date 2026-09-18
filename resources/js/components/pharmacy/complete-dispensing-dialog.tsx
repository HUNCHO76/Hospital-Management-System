import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { useToast } from '@/hooks/use-toast';
import { dispensingItemStatusLabel } from '@/lib/dispensing-status';
import { type DispensingItemStatus } from '@/types/dispensing';
import { router } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useState, type ReactNode } from 'react';

interface CompleteDispensingDialogItem {
    prescription_order_item_id: number;
    label: string;
    dispensed_quantity: string;
    status: DispensingItemStatus;
    notes: string;
}

interface CompleteDispensingDialogProps {
    prescriptionOrderId: number;
    items: CompleteDispensingDialogItem[];
    notes: string;
    trigger: ReactNode;
    onSuccess?: () => void;
}

/**
 * The final confirmation before completing dispensing (spec §31) — summarizes
 * exactly what will be recorded, since the frontend's own quantity limits
 * are only a convenience; the backend re-validates every quantity against
 * the live remaining amount regardless (DispensingService::complete()).
 */
export function CompleteDispensingDialog({ prescriptionOrderId, items, notes, trigger, onSuccess }: CompleteDispensingDialogProps) {
    const [processing, setProcessing] = useState(false);
    const { toast } = useToast();

    const submit = () => {
        setProcessing(true);
        router.post(
            route('pharmacy-dispensing.complete', prescriptionOrderId),
            {
                items: items.map((item) => ({
                    prescription_order_item_id: item.prescription_order_item_id,
                    dispensed_quantity: item.dispensed_quantity,
                    status: item.status,
                    notes: item.notes,
                })),
                notes,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setProcessing(false);
                    onSuccess?.();
                },
                onError: (errs) => {
                    setProcessing(false);
                    const message =
                        errs.financial_authorization ??
                        errs.status ??
                        (errs.items as unknown as string) ??
                        Object.values(errs)[0] ??
                        'Dispensing could not be completed. Please try again.';
                    toast({ variant: 'error', description: message });
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    const dispensedItems = items.filter((item) => Number(item.dispensed_quantity) > 0 || item.status === 'unavailable');

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Complete dispensing?</DialogTitle>
                    <DialogDescription>You are confirming that the selected medication quantities have been dispensed to the patient.</DialogDescription>
                </DialogHeader>

                <ul className="space-y-1 py-2 text-sm">
                    {dispensedItems.map((item) => (
                        <li key={item.prescription_order_item_id} className="flex items-center justify-between gap-2">
                            <span>{item.label}</span>
                            <span className="text-muted-foreground">
                                {item.status === 'unavailable' ? dispensingItemStatusLabel.unavailable : `${item.dispensed_quantity || 0} dispensed`}
                            </span>
                        </li>
                    ))}
                    {dispensedItems.length === 0 && <li className="text-muted-foreground">No medications selected.</li>}
                </ul>
                <DialogFooter>
                    <Button type="button" disabled={processing || dispensedItems.length === 0} onClick={submit}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Confirm Dispensing
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
