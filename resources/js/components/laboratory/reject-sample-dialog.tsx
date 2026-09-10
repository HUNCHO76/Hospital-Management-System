import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { laboratorySampleRejectionReasonLabel } from '@/lib/laboratory-status';
import { type LaboratorySampleRejectionReason, type RejectLaboratorySampleFormData } from '@/types/laboratory';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface RejectSampleDialogProps {
    sampleId: number;
    sampleNumber: string;
    trigger: ReactNode;
}

const REASONS = Object.keys(laboratorySampleRejectionReasonLabel) as LaboratorySampleRejectionReason[];

/**
 * Rejects a collected specimen (spec §31-32) — the sample is never deleted
 * or overwritten, only marked rejected with a recorded reason.
 */
export function RejectSampleDialog({ sampleId, sampleNumber, trigger }: RejectSampleDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<RejectLaboratorySampleFormData>({
        rejection_reason: '',
        rejection_notes: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('laboratory.samples.reject', sampleId), { onSuccess: () => reset(), preserveScroll: true });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Reject specimen {sampleNumber}</DialogTitle>
                        <DialogDescription>Select a reason. The rejected specimen stays in the record permanently.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid gap-2">
                            <Label>Rejection Reason *</Label>
                            <Select
                                value={data.rejection_reason}
                                onValueChange={(value) => setData('rejection_reason', value as LaboratorySampleRejectionReason)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select a reason" />
                                </SelectTrigger>
                                <SelectContent>
                                    {REASONS.map((reason) => (
                                        <SelectItem key={reason} value={reason}>
                                            {laboratorySampleRejectionReasonLabel[reason]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.rejection_reason} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="rejection_notes">
                                Notes {data.rejection_reason === 'other' && <span className="text-destructive">*</span>}
                            </Label>
                            <Textarea
                                id="rejection_notes"
                                rows={2}
                                value={data.rejection_notes}
                                onChange={(e) => setData('rejection_notes', e.target.value)}
                            />
                            <InputError message={errors.rejection_notes} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" variant="destructive" disabled={processing || !data.rejection_reason}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Reject Sample
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
