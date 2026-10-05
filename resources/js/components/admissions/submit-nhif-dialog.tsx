import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { type Admission } from '@/types/admission';
import { router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Loader2, RotateCcw, Send, ShieldCheck } from 'lucide-react';
import { type FormEventHandler, type ReactNode, useState } from 'react';

interface SubmitNhifDialogProps {
    admission: Admission;
    trigger?: ReactNode;
}

export function SubmitNhifDialog({ admission, trigger }: SubmitNhifDialogProps) {
    const [open, setOpen] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    const nhif = admission.nhifAdmission;
    const isAlreadySubmitted = nhif?.nhif_status === 'submitted' || Boolean(nhif?.nhif_admission_no);
    const patientName = admission.patient
        ? [admission.patient.first_name, admission.patient.middle_name, admission.patient.last_name].filter(Boolean).join(' ')
        : 'Unknown Patient';

    const handleSubmit: FormEventHandler = (e) => {
        e.preventDefault();
        setSubmitting(true);

        router.post(
            route('admissions.nhif-submit', admission.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setSubmitting(false);
                    setOpen(false);
                },
            }
        );
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {trigger || (
                    <Button size="sm" variant={isAlreadySubmitted ? 'outline' : 'default'} className="gap-1.5">
                        {isAlreadySubmitted ? <RotateCcw className="h-4 w-4" /> : <Send className="h-4 w-4" />}
                        {isAlreadySubmitted ? 'Resubmit to NHIF' : 'Submit to NHIF'}
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent className="sm:max-w-[480px]">
                <form onSubmit={handleSubmit}>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <ShieldCheck className="h-5 w-5 text-primary" />
                            {isAlreadySubmitted ? 'Resubmit Admission to NHIF' : 'Submit Admission to NHIF'}
                        </DialogTitle>
                        <DialogDescription>
                            {isAlreadySubmitted
                                ? 'This admission has already been submitted to NHIF. You can submit again if you need to update or re-transmit the record.'
                                : 'Confirm submitting this inpatient admission to the NHIF portal.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 py-4">
                        {isAlreadySubmitted && (
                            <Alert className="border-amber-500/50 bg-amber-500/10 text-amber-900 dark:text-amber-200">
                                <AlertTriangle className="h-4 w-4 text-amber-600" />
                                <AlertDescription className="text-xs space-y-1">
                                    <div className="font-semibold">Patient Already Admitted to NHIF</div>
                                    <div>
                                        NHIF Admission No: <span className="font-mono font-bold">{nhif?.nhif_admission_no || 'Recorded'}</span>
                                    </div>
                                    <div>
                                        Are you sure you want to resubmit this admission to NHIF?
                                    </div>
                                </AlertDescription>
                            </Alert>
                        )}

                        <div className="rounded-lg border bg-muted/30 p-3 text-xs space-y-2">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">Patient:</span>
                                <span className="font-medium text-foreground">{patientName} ({admission.patient?.patient_number})</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">NHIF Authorization No:</span>
                                <span className="font-mono font-bold text-foreground">
                                    {nhif?.nhif_authorization_no || 'Auto-resolved from patient'}
                                </span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">Ward / Room:</span>
                                <span className="text-foreground">
                                    {admission.ward_type || 'General'} {admission.room_type ? `· ${admission.room_type}` : ''}
                                </span>
                            </div>
                            {nhif?.nhif_admission_no && (
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Current Admission No:</span>
                                    <Badge variant="secondary" className="font-mono text-xs">
                                        {nhif.nhif_admission_no}
                                    </Badge>
                                </div>
                            )}
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">NHIF Status:</span>
                                <Badge variant={isAlreadySubmitted ? 'default' : 'outline'} className="capitalize text-xs">
                                    {nhif?.nhif_status || 'Pending submission'}
                                </Badge>
                            </div>
                        </div>
                    </div>

                    <DialogFooter className="gap-2 sm:gap-0">
                        <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={submitting}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={submitting} className="gap-1.5">
                            {submitting ? (
                                <Loader2 className="h-4 w-4 animate-spin" />
                            ) : isAlreadySubmitted ? (
                                <RotateCcw className="h-4 w-4" />
                            ) : (
                                <CheckCircle2 className="h-4 w-4" />
                            )}
                            {isAlreadySubmitted ? 'Yes, Resubmit to NHIF' : 'Confirm & Submit to NHIF'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
