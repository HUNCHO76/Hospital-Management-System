import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type ApproveAdmissionFormData } from '@/types/admission';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface ApproveAdmissionDialogProps {
    admissionId: number;
    trigger: ReactNode;
}

export function ApproveAdmissionDialog({ admissionId, trigger }: ApproveAdmissionDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<ApproveAdmissionFormData>({ notes: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admissions.approve', admissionId), { onSuccess: () => reset() });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Approve admission request</DialogTitle>
                        <DialogDescription>
                            Approving clears this request for billing/NHIF clearance. The patient is not admitted until nursing confirms.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2 py-4">
                        <Label htmlFor="approve-notes">Notes (optional)</Label>
                        <Textarea id="approve-notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={3} />
                        <InputError message={errors.notes} />
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Approve
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
