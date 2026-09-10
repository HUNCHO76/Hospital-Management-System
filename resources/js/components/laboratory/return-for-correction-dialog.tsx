import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { laboratoryReturnReasonLabel } from '@/lib/laboratory-status';
import { type LaboratoryReturnReason, type ReturnForCorrectionFormData } from '@/types/laboratory';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface ReturnForCorrectionDialogProps {
    resultId: number;
    trigger: ReactNode;
}

const REASONS = Object.keys(laboratoryReturnReasonLabel) as LaboratoryReturnReason[];

/**
 * Returns a result under review back to result-entry staff for correction
 * (Phase 4, Step 34, spec §20-21) — a reason is always required.
 */
export function ReturnForCorrectionDialog({ resultId, trigger }: ReturnForCorrectionDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<ReturnForCorrectionFormData>({
        return_reason: '',
        return_notes: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('laboratory.results.return', resultId), { onSuccess: () => reset(), preserveScroll: true });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Return for correction</DialogTitle>
                        <DialogDescription>
                            Why is this result being returned? It will go back to result-entry staff for correction.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid gap-2">
                            <Label>Reason *</Label>
                            <Select value={data.return_reason} onValueChange={(value) => setData('return_reason', value as LaboratoryReturnReason)}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select a reason" />
                                </SelectTrigger>
                                <SelectContent>
                                    {REASONS.map((reason) => (
                                        <SelectItem key={reason} value={reason}>
                                            {laboratoryReturnReasonLabel[reason]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.return_reason} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="return_notes">
                                Additional Comment {data.return_reason === 'other' && <span className="text-destructive">*</span>}
                            </Label>
                            <Textarea
                                id="return_notes"
                                rows={2}
                                value={data.return_notes}
                                onChange={(e) => setData('return_notes', e.target.value)}
                            />
                            <InputError message={errors.return_notes} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" variant="destructive" disabled={processing || !data.return_reason}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Return for Correction
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
