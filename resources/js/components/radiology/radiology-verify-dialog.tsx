import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type RadiologyReport, type VerifyRadiologyReportFormData } from '@/types/radiology';
import { useForm } from '@inertiajs/react';
import { CheckCircle2, LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface RadiologyVerifyDialogProps {
    report: RadiologyReport;
    trigger: ReactNode;
}

/**
 * Verifies (approves) a submitted radiology report (Phase 5, Step 43, Part
 * 10/24) — an explicit confirmation step showing the same context a
 * verifier needs to recognize what they are approving. Self-verification
 * and every other eligibility rule is re-enforced server-side regardless of
 * what this dialog shows.
 */
export function RadiologyVerifyDialog({ report, trigger }: RadiologyVerifyDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<VerifyRadiologyReportFormData>({
        verification_notes: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('radiology-verification.verify', report.id), { onSuccess: () => reset(), preserveScroll: true });
    };

    const patientName = report.patient
        ? [report.patient.first_name, report.patient.middle_name, report.patient.last_name].filter(Boolean).join(' ')
        : '—';

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Verify report</DialogTitle>
                        <DialogDescription>
                            You are about to verify this radiology report. Verified reports are locked from further editing.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <dl className="grid gap-2 rounded-md border p-3 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Patient</dt>
                                <dd className="font-medium">{patientName}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Procedure</dt>
                                <dd>{report.item?.procedure_name ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Order Number</dt>
                                <dd>{report.order?.order_number ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Reporting Practitioner</dt>
                                <dd>{report.reporting_practitioner?.name ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Report Status</dt>
                                <dd className="capitalize">{report.status}</dd>
                            </div>
                        </dl>

                        <div className="grid gap-2">
                            <Label htmlFor="verification_notes">Verification Notes (optional, administrative only)</Label>
                            <Textarea
                                id="verification_notes"
                                rows={2}
                                value={data.verification_notes}
                                onChange={(e) => setData('verification_notes', e.target.value)}
                            />
                            <InputError message={errors.verification_notes} />
                            <InputError message={errors.self_verification} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            <CheckCircle2 className="h-4 w-4" />
                            Confirm Verification
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
