import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { radiologyReportReturnReasonLabel } from '@/lib/radiology-status';
import { type RadiologyReportReturnReason, type ReturnRadiologyReportFormData } from '@/types/radiology';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface RadiologyReturnDialogProps {
    reportId: number;
    trigger: ReactNode;
}

const REASONS = Object.keys(radiologyReportReturnReasonLabel) as RadiologyReportReturnReason[];

/**
 * Returns a submitted radiology report to the reporting practitioner for
 * correction (Phase 5, Step 43, Part 11/25) — a reason is always required;
 * the report's content is never modified here, only the return metadata.
 */
export function RadiologyReturnDialog({ reportId, trigger }: RadiologyReturnDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<ReturnRadiologyReportFormData>({
        return_reason: '',
        return_comments: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('radiology-verification.return', reportId), { onSuccess: () => reset(), preserveScroll: true });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Return for correction</DialogTitle>
                        <DialogDescription>This report will be returned to the reporting practitioner for correction.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid gap-2">
                            <Label>Reason *</Label>
                            <Select
                                value={data.return_reason}
                                onValueChange={(value) => setData('return_reason', value as RadiologyReportReturnReason)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select a reason" />
                                </SelectTrigger>
                                <SelectContent>
                                    {REASONS.map((reason) => (
                                        <SelectItem key={reason} value={reason}>
                                            {radiologyReportReturnReasonLabel[reason]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.return_reason} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="return_comments">
                                Comments {data.return_reason === 'other' && <span className="text-destructive">*</span>}
                            </Label>
                            <Textarea
                                id="return_comments"
                                rows={3}
                                value={data.return_comments}
                                onChange={(e) => setData('return_comments', e.target.value)}
                            />
                            <InputError message={errors.return_comments} />
                            <InputError message={errors.self_verification} />
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
