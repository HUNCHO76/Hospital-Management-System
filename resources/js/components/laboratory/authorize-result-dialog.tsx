import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { type LaboratoryResult } from '@/types/laboratory';
import { router } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type ReactNode, useState } from 'react';

interface AuthorizeResultDialogProps {
    result: LaboratoryResult;
    trigger: ReactNode;
}

/**
 * Authorizes (signs off) a verified result — the final gate before it is
 * ready for release (Phase 4, Step 34, spec §17/§37). An explicit
 * confirmation step, never a one-click action.
 */
export function AuthorizeResultDialog({ result, trigger }: AuthorizeResultDialogProps) {
    const [processing, setProcessing] = useState(false);

    const confirm = () => {
        setProcessing(true);
        router.post(route('laboratory.results.authorize', result.id), {}, { preserveScroll: true, onFinish: () => setProcessing(false) });
    };

    const patientName = result.patient
        ? [result.patient.first_name, result.patient.middle_name, result.patient.last_name].filter(Boolean).join(' ')
        : '—';

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Authorize laboratory result</DialogTitle>
                    <DialogDescription>
                        You are about to authorize this laboratory result. Once authorized, it is ready for release.
                    </DialogDescription>
                </DialogHeader>

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
                        <dt className="text-muted-foreground">Verified by</dt>
                        <dd>{result.verified_by?.name ?? '—'}</dd>
                    </div>
                </dl>

                <DialogFooter>
                    <Button type="button" onClick={confirm} disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Confirm Authorization
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
