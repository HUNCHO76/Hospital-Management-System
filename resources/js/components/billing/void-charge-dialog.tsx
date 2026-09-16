import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type VoidChargeFormData } from '@/types/billing';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface VoidChargeDialogProps {
    chargeId: number;
    mode: 'void' | 'cancel';
    trigger: ReactNode;
}

const copy = {
    void: { title: 'Void charge', description: 'This charge has not been posted and will never affect the account balance.', action: 'Void' },
    cancel: {
        title: 'Cancel charge',
        description: 'This posted charge will be reversed and removed from the account balance. The original record is kept for audit.',
        action: 'Cancel Charge',
    },
};

/**
 * Void (PENDING) or cancel (POSTED) a charge — a reason is required either
 * way (enforced server-side too). Never deletes; the charge remains
 * historically visible with its reason (spec §25).
 */
export function VoidChargeDialog({ chargeId, mode, trigger }: VoidChargeDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<VoidChargeFormData>({ reason: '' });
    const text = copy[mode];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route(mode === 'void' ? 'billing.charges.void' : 'billing.charges.cancel', chargeId), {
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
                        <DialogTitle>{text.title}</DialogTitle>
                        <DialogDescription>{text.description}</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2 py-4">
                        <Label htmlFor="charge_reason">Reason *</Label>
                        <Textarea id="charge_reason" rows={3} value={data.reason} onChange={(e) => setData('reason', e.target.value)} autoFocus />
                        <InputError message={errors.reason} />
                    </div>

                    <DialogFooter>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            {text.action}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
