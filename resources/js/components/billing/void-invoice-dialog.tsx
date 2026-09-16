import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type VoidInvoiceFormData } from '@/types/billing';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface VoidInvoiceDialogProps {
    invoiceId: number;
    mode: 'void' | 'cancel';
    trigger: ReactNode;
}

const copy = {
    void: { title: 'Void invoice', description: 'This draft invoice will be abandoned and its charges released for a future invoice.', action: 'Void' },
    cancel: {
        title: 'Cancel invoice',
        description: 'This posted invoice will be reversed. Its charges are released for a corrected invoice; the original record is kept for audit.',
        action: 'Cancel Invoice',
    },
};

/**
 * Void (DRAFT) or cancel (POSTED) an invoice — a reason is required either
 * way (enforced server-side too). Never deletes; the invoice remains
 * historically visible with its reason (spec §30).
 */
export function VoidInvoiceDialog({ invoiceId, mode, trigger }: VoidInvoiceDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<VoidInvoiceFormData>({ reason: '' });
    const text = copy[mode];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route(mode === 'void' ? 'billing.invoices.void' : 'billing.invoices.cancel', invoiceId), {
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
                        <Label htmlFor="invoice_reason">Reason *</Label>
                        <Textarea id="invoice_reason" rows={3} value={data.reason} onChange={(e) => setData('reason', e.target.value)} autoFocus />
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
