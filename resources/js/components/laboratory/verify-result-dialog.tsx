import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type LaboratoryResult, type VerifyLaboratoryResultFormData } from '@/types/laboratory';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface VerifyResultDialogProps {
    result: LaboratoryResult;
    trigger: ReactNode;
}

/**
 * Verifies a result under review (Phase 4, Step 34, spec §16/§37) — an
 * explicit confirmation step, never a one-click action, since a verified
 * result becomes controlled clinical data.
 */
export function VerifyResultDialog({ result, trigger }: VerifyResultDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<VerifyLaboratoryResultFormData>({
        verification_comment: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('laboratory.results.verify', result.id), { onSuccess: () => reset(), preserveScroll: true });
    };

    const patientName = result.patient
        ? [result.patient.first_name, result.patient.middle_name, result.patient.last_name].filter(Boolean).join(' ')
        : '—';

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Verify laboratory result</DialogTitle>
                        <DialogDescription>
                            You are about to verify this laboratory result. Verified results become controlled clinical data.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <dl className="grid gap-2 rounded-md border p-3 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Patient</dt>
                                <dd className="font-medium">{patientName}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Sample</dt>
                                <dd>{result.sample?.sample_number ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Test</dt>
                                <dd>
                                    {result.item?.test_code} — {result.item?.test_name}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Result</dt>
                                <dd className="font-medium">
                                    {result.value_numeric ?? result.value_text ?? result.value_code ?? '—'}
                                    {result.value_numeric && result.unit ? ` ${result.unit}` : ''}
                                </dd>
                            </div>
                        </dl>

                        <div className="grid gap-2">
                            <Label htmlFor="verification_comment">Verification Comment (optional)</Label>
                            <Textarea
                                id="verification_comment"
                                rows={2}
                                value={data.verification_comment}
                                onChange={(e) => setData('verification_comment', e.target.value)}
                            />
                            <InputError message={errors.verification_comment} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Confirm Verification
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
