import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type QuarantineStockFormData } from '@/types/stock';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface QuarantineBatchDialogProps {
    stockBatchId: number;
    trigger: ReactNode;
}

/**
 * Hold a batch out of dispensable inventory pending investigation (Phase 6,
 * Step 49, spec §9) — a reason is required (enforced server-side too).
 * Reversible via ReleaseBatchButton.
 */
export function QuarantineBatchDialog({ stockBatchId, trigger }: QuarantineBatchDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<QuarantineStockFormData>({ reason: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('pharmacy-stock.batches.quarantine', stockBatchId), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Quarantine batch</DialogTitle>
                        <DialogDescription>This batch will be held out of dispensable stock until released.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2 py-4">
                        <Label htmlFor="quarantine_reason">Reason *</Label>
                        <Textarea id="quarantine_reason" rows={3} value={data.reason} onChange={(e) => setData('reason', e.target.value)} autoFocus />
                        <InputError message={errors.reason} />
                    </div>

                    <DialogFooter>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Quarantine
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
