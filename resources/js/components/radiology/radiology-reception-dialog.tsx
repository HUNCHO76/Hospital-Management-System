import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { usePermissions } from '@/hooks/use-permissions';
import {
    radiologyAppointmentStatusLabel,
    radiologyAppointmentStatusVariant,
    radiologyConsentStatusLabel,
    radiologyNotReadyReasonLabel,
    radiologyPreparationTypeLabel,
} from '@/lib/radiology-status';
import {
    type RadiologyAppointment,
    type RadiologyConsentStatus,
    type RadiologyNotReadyReason,
    type RadiologyReceptionAppointment,
} from '@/types/radiology';
import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode, useState } from 'react';

interface RadiologyReceptionDialogProps {
    appointment: RadiologyReceptionAppointment;
    trigger: ReactNode;
}

const NOT_READY_REASONS: RadiologyNotReadyReason[] = [
    'preparation_incomplete',
    'identity_issue',
    'consent_pending',
    'clinical_review_required',
    'equipment_unavailable',
    'patient_condition_changed',
    'other',
];

const CONSENT_STATUSES: RadiologyConsentStatus[] = ['not_required', 'pending', 'obtained', 'declined'];

function post(route_: string, id: number) {
    router.post(route(route_, id), {}, { preserveScroll: true });
}

/**
 * The full reception/preparation/readiness panel for one appointment (Phase
 * 5, Step 40) — consolidates verification, preparation checklist, MRI
 * screening, contrast review, consent, and the final ready/not-ready
 * decision into one contextual dialog, since they are all steps of the
 * same continuous staff workflow for the same patient. The server performs
 * every actual gate check (Part 7/16) — the UI only reflects it.
 */
export function RadiologyReceptionDialog({ appointment, trigger }: RadiologyReceptionDialogProps) {
    const readinessBlockers = appointment.readiness_blockers;
    const { can } = usePermissions();
    const [identityChecked, setIdentityChecked] = useState(false);
    const [orderChecked, setOrderChecked] = useState(false);

    const patientName = appointment.patient
        ? [appointment.patient.first_name, appointment.patient.middle_name, appointment.patient.last_name].filter(Boolean).join(' ')
        : '—';

    const requiresPreparation = appointment.procedure && appointment.procedure.preparation_type !== 'none';
    const requiresMriScreening = appointment.procedure?.preparation_type === 'mri_safety_screening';
    const requiresContrastReview = appointment.procedure && appointment.procedure.contrast_requirement !== 'no_contrast';

    const canManage = can('radiology-reception.manage');

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[85vh] max-w-lg overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{patientName}</DialogTitle>
                    <DialogDescription>
                        {appointment.patient?.patient_number} — {appointment.order?.order_number} — {appointment.item?.procedure_name}
                    </DialogDescription>
                </DialogHeader>

                <div className="flex items-center gap-2">
                    <Badge variant={radiologyAppointmentStatusVariant[appointment.status]}>
                        {radiologyAppointmentStatusLabel[appointment.status]}
                    </Badge>
                    {appointment.not_ready_reason && (
                        <span className="text-muted-foreground text-xs">Reason: {radiologyNotReadyReasonLabel[appointment.not_ready_reason]}</span>
                    )}
                </div>

                <dl className="grid grid-cols-2 gap-2 text-sm">
                    <dt className="text-muted-foreground">Date of Birth</dt>
                    <dd className="text-right">{appointment.patient?.date_of_birth ?? '—'}</dd>
                    <dt className="text-muted-foreground">Sex</dt>
                    <dd className="text-right">{appointment.patient?.gender ?? '—'}</dd>
                    <dt className="text-muted-foreground">Priority</dt>
                    <dd className="text-right">{appointment.order?.priority?.toUpperCase()}</dd>
                    <dt className="text-muted-foreground">Scheduled</dt>
                    <dd className="text-right">
                        {new Date(appointment.scheduled_start).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}
                    </dd>
                </dl>

                {canManage && ['scheduled', 'confirmed'].includes(appointment.status) && (
                    <Button onClick={() => post('radiology-reception.arrive', appointment.id)}>Patient Arrived</Button>
                )}

                {canManage && appointment.status === 'arrived' && !appointment.verified_at && (
                    <div className="space-y-3 border-t pt-4">
                        <p className="text-sm font-medium">Patient Verification</p>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={identityChecked} onCheckedChange={(v) => setIdentityChecked(v === true)} />
                            Patient identity confirmed
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={orderChecked} onCheckedChange={(v) => setOrderChecked(v === true)} />
                            Correct radiology order confirmed
                        </label>
                        <Button disabled={!identityChecked || !orderChecked} onClick={() => post('radiology-reception.verify', appointment.id)}>
                            Confirm &amp; Continue
                        </Button>
                    </div>
                )}

                {appointment.verified_at && requiresPreparation && (
                    <PreparationSection
                        appointment={appointment}
                        canManage={canManage}
                        requiresMriScreening={!!requiresMriScreening}
                        requiresContrastReview={!!requiresContrastReview}
                    />
                )}

                {appointment.verified_at && canManage && <ConsentSection appointment={appointment} />}

                {canManage && ['arrived', 'preparation_pending', 'preparation_in_progress'].includes(appointment.status) && (
                    <div className="space-y-2 border-t pt-4">
                        {readinessBlockers.length > 0 && (
                            <div className="bg-muted flex flex-col gap-1 rounded-md p-3 text-sm">
                                <div className="flex items-center gap-1 font-medium">
                                    <AlertTriangle className="h-4 w-4" />
                                    Not yet ready
                                </div>
                                <ul className="list-inside list-disc">
                                    {readinessBlockers.map((blocker) => (
                                        <li key={blocker}>{blocker}</li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        <div className="flex gap-2">
                            <Button disabled={readinessBlockers.length > 0} onClick={() => post('radiology-reception.ready', appointment.id)}>
                                <CheckCircle2 className="h-4 w-4" />
                                Mark Ready
                            </Button>
                            <NotReadyDialog appointmentId={appointment.id} />
                        </div>
                    </div>
                )}

                {appointment.status === 'ready' && (
                    <div className="border-t pt-4 text-sm">
                        <p className="font-medium">Ready for examination.</p>
                        <p className="text-muted-foreground">The examination itself is performed in the radiology examination workflow.</p>
                    </div>
                )}

                {canManage && appointment.status === 'not_ready' && (
                    <Button variant="outline" onClick={() => post('radiology-reception.resume', appointment.id)}>
                        Resume
                    </Button>
                )}
            </DialogContent>
        </Dialog>
    );
}

function PreparationSection({
    appointment,
    canManage,
    requiresMriScreening,
    requiresContrastReview,
}: {
    appointment: RadiologyAppointment;
    canManage: boolean;
    requiresMriScreening: boolean;
    requiresContrastReview: boolean;
}) {
    const {
        data,
        setData,
        post: submit,
        processing,
        errors,
    } = useForm({
        instructions_given: !!appointment.instructions_given_at,
        completed: !!appointment.preparation_completed_at,
        notes: appointment.preparation_notes ?? '',
    });

    const screeningForm = useForm<{ flagged: boolean; notes: string }>({ flagged: false, notes: '' });

    const submitPreparation: FormEventHandler = (e) => {
        e.preventDefault();
        submit(route('radiology-reception.complete-preparation', appointment.id), { preserveScroll: true });
    };

    const submitScreening: FormEventHandler = (e) => {
        e.preventDefault();
        screeningForm.post(route('radiology-reception.screening', appointment.id), { preserveScroll: true });
    };

    return (
        <div className="space-y-4 border-t pt-4">
            <p className="text-sm font-medium">
                Preparation — {appointment.procedure && radiologyPreparationTypeLabel[appointment.procedure.preparation_type]}
            </p>

            {appointment.procedure?.preparation_instructions && (
                <p className="bg-muted rounded-md p-3 text-sm">{appointment.procedure.preparation_instructions}</p>
            )}

            {canManage && appointment.status === 'preparation_pending' && (
                <Button size="sm" onClick={() => post('radiology-reception.start-preparation', appointment.id)}>
                    Start Preparation
                </Button>
            )}

            {canManage && appointment.status === 'preparation_in_progress' && (
                <form onSubmit={submitPreparation} className="space-y-3">
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox checked={data.instructions_given} onCheckedChange={(v) => setData('instructions_given', v === true)} />
                        Preparation instructions given
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox checked={data.completed} onCheckedChange={(v) => setData('completed', v === true)} />
                        Preparation completed
                    </label>
                    <Textarea placeholder="Notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} />
                    <InputError message={errors.notes} />
                    <Button type="submit" size="sm" disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Save Preparation
                    </Button>
                </form>
            )}

            {requiresMriScreening && canManage && appointment.status !== 'ready' && (
                <form onSubmit={submitScreening} className="space-y-2 border-t pt-3">
                    <p className="text-sm font-medium">MRI Safety Screening</p>
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox checked={screeningForm.data.flagged} onCheckedChange={(v) => screeningForm.setData('flagged', v === true)} />
                        Potential concern identified (requires clinical review)
                    </label>
                    <Textarea
                        placeholder="Screening notes"
                        value={screeningForm.data.notes}
                        onChange={(e) => screeningForm.setData('notes', e.target.value)}
                        rows={2}
                    />
                    <Button type="submit" size="sm" variant="outline" disabled={screeningForm.processing}>
                        Record Screening
                    </Button>
                    {appointment.mri_screening_flagged && (
                        <p className="text-destructive text-xs">Flagged — requires clinical review before this patient can be marked ready.</p>
                    )}
                </form>
            )}

            {requiresContrastReview && canManage && !appointment.contrast_reviewed_at && (
                <Button size="sm" variant="outline" onClick={() => post('radiology-reception.review-contrast', appointment.id)}>
                    Review Contrast Requirement
                </Button>
            )}
        </div>
    );
}

function ConsentSection({ appointment }: { appointment: RadiologyAppointment }) {
    const { data, setData, post: submit, processing } = useForm({ status: appointment.consent_status });

    const submitConsent: FormEventHandler = (e) => {
        e.preventDefault();
        submit(route('radiology-reception.consent', appointment.id), { preserveScroll: true });
    };

    return (
        <form onSubmit={submitConsent} className="space-y-2 border-t pt-4">
            <p className="text-sm font-medium">Consent</p>
            <div className="flex items-center gap-2">
                <Select value={data.status} onValueChange={(v) => setData('status', v as typeof data.status)}>
                    <SelectTrigger className="w-[180px]">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {CONSENT_STATUSES.map((status) => (
                            <SelectItem key={status} value={status}>
                                {radiologyConsentStatusLabel[status]}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Button type="submit" size="sm" variant="outline" disabled={processing}>
                    Record Consent
                </Button>
            </div>
        </form>
    );
}

function NotReadyDialog({ appointmentId }: { appointmentId: number }) {
    const { data, setData, post: submit, processing, errors, reset } = useForm({ reason: '' });

    const submitReason: FormEventHandler = (e) => {
        e.preventDefault();
        submit(route('radiology-reception.not-ready', appointmentId), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="destructive">Mark Not Ready</Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submitReason}>
                    <DialogHeader>
                        <DialogTitle>Mark patient not ready</DialogTitle>
                        <DialogDescription>The radiology order is not cancelled — the appointment may be rescheduled or resumed.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2 py-4">
                        <Label htmlFor="not_ready_reason">Reason *</Label>
                        <Select value={data.reason} onValueChange={(v) => setData('reason', v)}>
                            <SelectTrigger id="not_ready_reason">
                                <SelectValue placeholder="Select a reason" />
                            </SelectTrigger>
                            <SelectContent>
                                {NOT_READY_REASONS.map((reason) => (
                                    <SelectItem key={reason} value={reason}>
                                        {radiologyNotReadyReasonLabel[reason]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.reason} />
                    </div>
                    <DialogFooter>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Mark Not Ready
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
