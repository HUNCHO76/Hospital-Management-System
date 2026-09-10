import { CancelConsultationDialog } from '@/components/consultation/cancel-consultation-dialog';
import { CorrectConsultationDialog } from '@/components/consultation/correct-consultation-dialog';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { usePermissions } from '@/hooks/use-permissions';
import { allowedConsultationActions, CONSULTATION_FIELDS, consultationStatusLabel, consultationStatusVariant } from '@/lib/consultation-status';
import { type Consultation, type ConsultationFormData } from '@/types/consultation';
import { type TriageAssessment } from '@/types/triage';
import { router, useForm } from '@inertiajs/react';
import { CheckCircle2, ClipboardPlus, LoaderCircle, PenLine, PlayCircle, XCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface ConsultationPanelProps {
    encounterId: number;
    encounterStatus: string;
    triage: TriageAssessment | null;
    consultation: Consultation | null;
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * The doctor consultation workflow embedded on the clinical encounter screen
 * (spec §26/28), mirroring TriagePanel — there is no standalone consultation
 * page. Requires triage to be completed first (spec §7); the backend
 * re-validates this independently.
 */
export function ConsultationPanel({ encounterId, encounterStatus, triage, consultation }: ConsultationPanelProps) {
    const { can } = usePermissions();
    const triageCompleted = triage?.status === 'completed';
    const encounterClosed = encounterStatus === 'completed' || encounterStatus === 'cancelled';

    const startConsultation = () => {
        router.post(route('clinical-encounters.consultation.store', encounterId), {}, { preserveScroll: true });
    };

    if (!consultation) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Consultation</CardTitle>
                </CardHeader>
                <CardContent className="flex items-center justify-between gap-4">
                    <p className="text-muted-foreground text-sm">
                        {triageCompleted
                            ? 'No consultation has been started for this encounter yet.'
                            : 'Triage must be completed before a consultation can be started.'}
                    </p>
                    {can('consultation.create') && triageCompleted && !encounterClosed && (
                        <Button size="sm" onClick={startConsultation}>
                            <ClipboardPlus className="h-4 w-4" />
                            Start Consultation
                        </Button>
                    )}
                </CardContent>
            </Card>
        );
    }

    const actions = allowedConsultationActions(consultation.status);
    const isOpen = consultation.status === 'pending' || consultation.status === 'in_progress';

    const runAction = (action: string) => {
        router.post(route(`clinical-encounters.consultation.${action}`, encounterId), {}, { preserveScroll: true });
    };

    return (
        <Card>
            <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2 space-y-0">
                <CardTitle className="text-base">Consultation</CardTitle>
                <div className="flex flex-wrap items-center gap-2">
                    <Badge variant={consultationStatusVariant[consultation.status]}>{consultationStatusLabel[consultation.status]}</Badge>

                    {actions.includes('start') && can('consultation.create') && (
                        <Button size="sm" onClick={() => runAction('start')}>
                            <PlayCircle className="h-4 w-4" />
                            Start
                        </Button>
                    )}

                    {actions.includes('complete') && can('consultation.complete') && (
                        <Button size="sm" onClick={() => runAction('complete')}>
                            <CheckCircle2 className="h-4 w-4" />
                            Complete
                        </Button>
                    )}

                    {actions.includes('cancel') && can('consultation.cancel') && (
                        <CancelConsultationDialog
                            encounterId={encounterId}
                            trigger={
                                <Button size="sm" variant="destructive">
                                    <XCircle className="h-4 w-4" />
                                    Cancel
                                </Button>
                            }
                        />
                    )}

                    {consultation.status === 'completed' && can('consultation.correct') && (
                        <CorrectConsultationDialog
                            encounterId={encounterId}
                            consultation={consultation}
                            trigger={
                                <Button size="sm" variant="outline">
                                    <PenLine className="h-4 w-4" />
                                    Correct
                                </Button>
                            }
                        />
                    )}
                </div>
            </CardHeader>
            <CardContent className="space-y-6">
                <div className="grid gap-4 text-sm sm:grid-cols-2">
                    <div className="flex justify-between gap-4">
                        <span className="text-muted-foreground">Practitioner</span>
                        <span>{consultation.practitioner?.name ?? '—'}</span>
                    </div>
                    <div className="flex justify-between gap-4">
                        <span className="text-muted-foreground">Started</span>
                        <span>{consultation.started_at ? formatDateTime(consultation.started_at) : '—'}</span>
                    </div>
                    {consultation.status === 'completed' && (
                        <div className="flex justify-between gap-4">
                            <span className="text-muted-foreground">Completed</span>
                            <span>{consultation.completed_at ? formatDateTime(consultation.completed_at) : '—'}</span>
                        </div>
                    )}
                    {consultation.status === 'cancelled' && (
                        <div className="flex justify-between gap-4 sm:col-span-2">
                            <span className="text-muted-foreground">Cancellation Reason</span>
                            <span>{consultation.cancellation_reason ?? '—'}</span>
                        </div>
                    )}
                    {consultation.corrected_at && (
                        <div className="flex justify-between gap-4 sm:col-span-2">
                            <span className="text-muted-foreground">Last Corrected</span>
                            <span>
                                {formatDateTime(consultation.corrected_at)}
                                {consultation.correction_reason ? ` — ${consultation.correction_reason}` : ''}
                            </span>
                        </div>
                    )}
                </div>

                {isOpen && can('consultation.update') ? (
                    <ConsultationForm encounterId={encounterId} consultation={consultation} />
                ) : (
                    <div className="grid gap-4 border-t pt-4 text-sm">
                        {CONSULTATION_FIELDS.map((field) => (
                            <div key={field.key} className="grid gap-1">
                                <span className="text-muted-foreground">{field.label}</span>
                                <span className="whitespace-pre-wrap">
                                    {(consultation as unknown as Record<string, string | null>)[field.key] || '—'}
                                </span>
                            </div>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function ConsultationForm({ encounterId, consultation }: { encounterId: number; consultation: Consultation }) {
    const { data, setData, put, processing, errors } = useForm<ConsultationFormData>({
        chief_complaint: consultation.chief_complaint ?? '',
        history_presenting_illness: consultation.history_presenting_illness ?? '',
        relevant_medical_history: consultation.relevant_medical_history ?? '',
        relevant_surgical_history: consultation.relevant_surgical_history ?? '',
        relevant_social_history: consultation.relevant_social_history ?? '',
        review_of_systems: consultation.review_of_systems ?? '',
        physical_examination: consultation.physical_examination ?? '',
        assessment_summary: consultation.assessment_summary ?? '',
        clinical_notes: consultation.clinical_notes ?? '',
        plan_summary: consultation.plan_summary ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('clinical-encounters.consultation.update', encounterId), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-4 border-t pt-4">
            <div className="grid gap-4">
                {CONSULTATION_FIELDS.map((field) => (
                    <div key={field.key} className="grid gap-1.5">
                        <Label htmlFor={field.key}>{field.label}</Label>
                        <Textarea id={field.key} rows={field.rows} value={data[field.key]} onChange={(e) => setData(field.key, e.target.value)} />
                        <InputError message={errors[field.key]} />
                    </div>
                ))}
            </div>

            <div className="flex justify-end">
                <Button type="submit" size="sm" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Save Consultation
                </Button>
            </div>
        </form>
    );
}
