import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type LaboratoryResult, type ReleaseLaboratoryResultFormData } from '@/types/laboratory';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface ReleaseResultDialogProps {
    result: LaboratoryResult;
    trigger: ReactNode;
}

/**
 * Releases an authorized result (Phase 4, Step 35, spec Part 6/36) — an
 * explicit confirmation step, never a one-click action, since a released
 * result becomes an official, final clinical document. The backend
 * revalidates eligibility independently of anything shown here.
 */
export function ReleaseResultDialog({ result, trigger }: ReleaseResultDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<ReleaseLaboratoryResultFormData>({
        release_comment: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('laboratory.results.release', result.id), { onSuccess: () => reset(), preserveScroll: true });
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
                        <DialogTitle>Release laboratory result</DialogTitle>
                        <DialogDescription>
                            You are about to release this laboratory result. After release, it will become available to authorized clinical users.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <dl className="grid gap-2 rounded-md border p-3 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Patient</dt>
                                <dd className="font-medium">{patientName}</dd>
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
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Authorized by</dt>
                                <dd>{result.authorized_by?.name ?? '—'}</dd>
                            </div>
                        </dl>

                        <div className="grid gap-2">
                            <Label htmlFor="release_comment">Release Comment (optional)</Label>
                            <Textarea
                                id="release_comment"
                                rows={2}
                                value={data.release_comment}
                                onChange={(e) => setData('release_comment', e.target.value)}
                            />
                            <InputError message={errors.release_comment} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Confirm Release
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
