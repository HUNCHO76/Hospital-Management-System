import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type RejectAdmissionFormData } from '@/types/admission';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface RejectAdmissionDialogProps {
    admissionId: number;
    trigger: ReactNode;
}

export function RejectAdmissionDialog({ admissionId, trigger }: RejectAdmissionDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<RejectAdmissionFormData>({ reason: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admissions.reject', admissionId), { onSuccess: () => reset() });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Reject admission request</DialogTitle>
                        <DialogDescription>
                            Please provide a reason. The request will reach a terminal rejected state and cannot be un-rejected.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2 py-4">
                        <Label htmlFor="reject-reason">Rejection reason *</Label>
                        <Textarea
                            id="reject-reason"
                            value={data.reason}
                            onChange={(e) => setData('reason', e.target.value)}
                            rows={3}
                            autoFocus
                        />
                        <InputError message={errors.reason} />
                    </div>

                    <DialogFooter>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Reject Request
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
