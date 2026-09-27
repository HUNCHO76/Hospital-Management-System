import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type CancelAdmissionFormData } from '@/types/admission';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface CancelAdmissionDialogProps {
    admissionId: number;
    trigger: ReactNode;
}

/**
 * Cancellation requires a reason (enforced server-side too) — mirrors
 * reception's own CancelVisitDialog precedent exactly. Only a pending
 * (not yet admitted) request can be cancelled this way.
 */
export function CancelAdmissionDialog({ admissionId, trigger }: CancelAdmissionDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<CancelAdmissionFormData>({ reason: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admissions.cancel', admissionId), { onSuccess: () => reset() });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Cancel admission request</DialogTitle>
                        <DialogDescription>Please provide a reason for cancelling this admission request.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2 py-4">
                        <Label htmlFor="reason">Cancellation reason *</Label>
                        <Textarea id="reason" value={data.reason} onChange={(e) => setData('reason', e.target.value)} rows={3} autoFocus />
                        <InputError message={errors.reason} />
                    </div>

                    <DialogFooter>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Cancel Request
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
