import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type ReturnAdmissionFormData } from '@/types/admission';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface ReturnAdmissionDialogProps {
    admissionId: number;
    trigger: ReactNode;
}

export function ReturnAdmissionDialog({ admissionId, trigger }: ReturnAdmissionDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<ReturnAdmissionFormData>({ notes: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admissions.return', admissionId), { onSuccess: () => reset() });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Return for correction</DialogTitle>
                        <DialogDescription>
                            The request will return to the requesting doctor as pending. Explain what needs to be corrected.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2 py-4">
                        <Label htmlFor="return-notes">Correction notes *</Label>
                        <Textarea
                            id="return-notes"
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                            rows={3}
                            autoFocus
                        />
                        <InputError message={errors.notes} />
                    </div>

                    <DialogFooter>
                        <Button type="submit" variant="outline" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Return for Correction
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
