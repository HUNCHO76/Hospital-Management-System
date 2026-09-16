import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type RadiologyReport, type ReleaseRadiologyResultFormData } from '@/types/radiology';
import { useForm } from '@inertiajs/react';
import { CheckCircle2, LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface RadiologyReleaseDialogProps {
    report: RadiologyReport;
    trigger: ReactNode;
}

/**
 * Releases a verified radiology report as the official clinical result
 * (Phase 5, Step 44, Part 8) — an explicit confirmation step showing the
 * exact context Part 8 requires (patient, procedure, reporting
 * practitioner, verifier, findings, impression) before the irreversible
 * release action. Every eligibility/authorization rule is re-enforced
 * server-side regardless of what this dialog shows.
 */
export function RadiologyReleaseDialog({ report, trigger }: RadiologyReleaseDialogProps) {
    const { data, setData, post, processing, errors, reset } = useForm<ReleaseRadiologyResultFormData>({
        release_notes: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('radiology-release.release', report.id), { onSuccess: () => reset(), preserveScroll: true });
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
                        <DialogTitle>Release result</DialogTitle>
                        <DialogDescription>
                            You are about to release this verified radiology result as an official clinical result.
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
                                <dt className="text-muted-foreground">Reporting Practitioner</dt>
                                <dd>{report.reporting_practitioner?.name ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Verified By</dt>
                                <dd>{report.verifying_practitioner?.name ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground mb-1">Findings</dt>
                                <dd className="whitespace-pre-wrap">{report.findings || '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground mb-1">Impression</dt>
                                <dd className="whitespace-pre-wrap">{report.impression || '—'}</dd>
                            </div>
                        </dl>

                        <div className="grid gap-2">
                            <Label htmlFor="release_notes">Release Notes (optional)</Label>
                            <Textarea
                                id="release_notes"
                                rows={2}
                                value={data.release_notes}
                                onChange={(e) => setData('release_notes', e.target.value)}
                            />
                            <InputError message={errors.release_notes} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            <CheckCircle2 className="h-4 w-4" />
                            Confirm Release
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
