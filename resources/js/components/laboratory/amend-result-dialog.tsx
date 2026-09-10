import InputError from '@/components/input-error';
import { LaboratoryResultValueInput } from '@/components/laboratory/laboratory-result-value-input';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { laboratoryAbnormalFlagLabel } from '@/lib/laboratory-status';
import { type AmendLaboratoryResultFormData, type LaboratoryAbnormalFlag, type LaboratoryResult } from '@/types/laboratory';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface AmendResultDialogProps {
    result: LaboratoryResult;
    trigger: ReactNode;
}

const FLAGS = Object.keys(laboratoryAbnormalFlagLabel) as LaboratoryAbnormalFlag[];

/**
 * Corrects a result already submitted for verification (Phase 4, Step 33,
 * spec §25) — the original value is never silently overwritten; a reason is
 * always required, and the backend audits the old/new values plus the
 * reason.
 */
export function AmendResultDialog({ result, trigger }: AmendResultDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<AmendLaboratoryResultFormData>({
        value_numeric: result.value_numeric ?? '',
        value_text: result.value_text ?? '',
        value_code: result.value_code ?? '',
        abnormal_flag: result.abnormal_flag ?? '',
        result_comment: result.result_comment ?? '',
        amendment_reason: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('laboratory.results.amend', result.id), { onSuccess: () => reset('amendment_reason'), preserveScroll: true });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Amend result</DialogTitle>
                        <DialogDescription>
                            This result has already been submitted for verification. Correcting it here preserves the original value and reason in the
                            audit trail.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <LaboratoryResultValueInput
                            resultType={result.result_type}
                            unit={result.unit}
                            resultOptions={result.result_options}
                            valueNumeric={data.value_numeric}
                            valueText={data.value_text}
                            valueCode={data.value_code}
                            onChangeNumeric={(value) => setData('value_numeric', value)}
                            onChangeText={(value) => setData('value_text', value)}
                            onChangeCode={(value) => setData('value_code', value)}
                            errors={errors}
                        />

                        <div className="grid gap-2">
                            <Label>Abnormal Flag</Label>
                            <Select
                                value={data.abnormal_flag || 'none'}
                                onValueChange={(value) => setData('abnormal_flag', value === 'none' ? '' : value)}
                            >
                                <SelectTrigger className="max-w-xs">
                                    <SelectValue placeholder="Not flagged" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">Not flagged</SelectItem>
                                    {FLAGS.map((flag) => (
                                        <SelectItem key={flag} value={flag}>
                                            {laboratoryAbnormalFlagLabel[flag]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.abnormal_flag} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="amend_result_comment">Comment</Label>
                            <Textarea
                                id="amend_result_comment"
                                rows={2}
                                value={data.result_comment}
                                onChange={(e) => setData('result_comment', e.target.value)}
                            />
                            <InputError message={errors.result_comment} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="amendment_reason">Reason for amendment *</Label>
                            <Textarea
                                id="amendment_reason"
                                rows={2}
                                value={data.amendment_reason}
                                onChange={(e) => setData('amendment_reason', e.target.value)}
                            />
                            <InputError message={errors.amendment_reason} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing || !data.amendment_reason}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Save Amendment
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
