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

interface RejectProcessingDialogProps {
    sampleId: number;
    sampleNumber: string;
    trigger: ReactNode;
}

const REASONS = Object.keys(laboratorySampleRejectionReasonLabel) as LaboratorySampleRejectionReason[];

/**
 * Rejects a specimen found unsuitable during laboratory processing itself
 * (Phase 4, Step 32, spec §18) — reuses the exact same rejection reason
 * vocabulary as Step 31's own collection-time rejection, posting to the
 * processing-specific route so it is authorized/audited as a processing
 * action.
 */
export function RejectProcessingDialog({ sampleId, sampleNumber, trigger }: RejectProcessingDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<RejectLaboratorySampleFormData>({
        rejection_reason: '',
        rejection_notes: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('laboratory.processing.reject', sampleId), { onSuccess: () => reset(), preserveScroll: true });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Reject specimen {sampleNumber}</DialogTitle>
                        <DialogDescription>
                            Select a reason. Every test on this specimen will be marked rejected; the specimen record itself is never deleted.
                        </DialogDescription>
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
                            <Label htmlFor="processing_rejection_notes">
                                Notes {data.rejection_reason === 'other' && <span className="text-destructive">*</span>}
                            </Label>
                            <Textarea
                                id="processing_rejection_notes"
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
                            Reject Specimen
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
