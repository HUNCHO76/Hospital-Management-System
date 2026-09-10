import InputError from '@/components/input-error';
import { CancelTriageDialog } from '@/components/triage/cancel-triage-dialog';
import { VitalSignsForm } from '@/components/triage/vital-signs-form';
import { VitalSignsHistory } from '@/components/triage/vital-signs-history';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { usePermissions } from '@/hooks/use-permissions';
import { allowedTriageActions, triageCategoryLabel, triageStatusLabel, triageStatusVariant } from '@/lib/triage-status';
import { type TriageAssessment, type TriageCategory, type TriageUpdateFormData } from '@/types/triage';
import { router, useForm } from '@inertiajs/react';
import { CheckCircle2, ClipboardPlus, LoaderCircle, PlayCircle, XCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface TriagePanelProps {
    encounterId: number;
    encounterStatus: string;
    triage: TriageAssessment | null;
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * The full triage + vital-signs workflow embedded on the clinical encounter
 * screen (spec §38) — there is no standalone triage page. Handles all three
 * states: no triage yet, an open assessment (editable, vitals recordable),
 * and a closed one (read-only).
 */
export function TriagePanel({ encounterId, encounterStatus, triage }: TriagePanelProps) {
    const { can } = usePermissions();

    const startTriage = () => {
        router.post(route('clinical-encounters.triage.store', encounterId), {}, { preserveScroll: true });
    };

    if (!triage) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Triage</CardTitle>
                </CardHeader>
                <CardContent className="flex items-center justify-between gap-4">
                    <p className="text-muted-foreground text-sm">No triage assessment has been started for this encounter yet.</p>
                    {can('triage.create') && encounterStatus !== 'completed' && encounterStatus !== 'cancelled' && (
                        <Button size="sm" onClick={startTriage}>
                            <ClipboardPlus className="h-4 w-4" />
                            Start Triage
                        </Button>
                    )}
                </CardContent>
            </Card>
        );
    }

    const actions = allowedTriageActions(triage.status);
    const isOpen = triage.status === 'pending' || triage.status === 'in_progress';

    const runAction = (action: string) => {
        router.post(route(`clinical-encounters.triage.${action}`, encounterId), {}, { preserveScroll: true });
    };

    return (
        <Card>
            <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2 space-y-0">
                <CardTitle className="text-base">Triage</CardTitle>
                <div className="flex flex-wrap items-center gap-2">
                    <Badge variant={triageStatusVariant[triage.status]}>{triageStatusLabel[triage.status]}</Badge>

                    {actions.includes('start') && can('triage.create') && (
                        <Button size="sm" onClick={() => runAction('start')}>
                            <PlayCircle className="h-4 w-4" />
                            Start
                        </Button>
                    )}

                    {actions.includes('complete') && can('triage.complete') && (
                        <Button size="sm" onClick={() => runAction('complete')}>
                            <CheckCircle2 className="h-4 w-4" />
                            Complete
                        </Button>
                    )}

                    {actions.includes('cancel') && can('triage.cancel') && (
                        <CancelTriageDialog
                            encounterId={encounterId}
                            trigger={
                                <Button size="sm" variant="destructive">
                                    <XCircle className="h-4 w-4" />
                                    Cancel
                                </Button>
                            }
                        />
                    )}
                </div>
            </CardHeader>
            <CardContent className="space-y-6">
                <div className="grid gap-4 text-sm sm:grid-cols-2">
                    <div className="flex justify-between gap-4">
                        <span className="text-muted-foreground">Assessor</span>
                        <span>{triage.assessed_by?.name ?? '—'}</span>
                    </div>
                    <div className="flex justify-between gap-4">
                        <span className="text-muted-foreground">Assessment Time</span>
                        <span>{triage.assessment_time ? formatDateTime(triage.assessment_time) : '—'}</span>
                    </div>
                    {triage.status === 'completed' && (
                        <div className="flex justify-between gap-4">
                            <span className="text-muted-foreground">Completed</span>
                            <span>{triage.completed_at ? formatDateTime(triage.completed_at) : '—'}</span>
                        </div>
                    )}
                    {triage.status === 'cancelled' && (
                        <div className="flex justify-between gap-4 sm:col-span-2">
                            <span className="text-muted-foreground">Cancellation Reason</span>
                            <span>{triage.cancellation_reason ?? '—'}</span>
                        </div>
                    )}
                </div>

                {isOpen && can('triage.update') ? (
                    <TriageAssessmentForm encounterId={encounterId} triage={triage} />
                ) : (
                    <div className="grid gap-3 text-sm">
                        <div className="flex justify-between gap-4">
                            <span className="text-muted-foreground">Category</span>
                            <span>{triage.triage_category ? triageCategoryLabel[triage.triage_category] : 'Not yet assigned'}</span>
                        </div>
                        <div className="flex justify-between gap-4">
                            <span className="text-muted-foreground">Chief Complaint</span>
                            <span>{triage.chief_complaint ?? '—'}</span>
                        </div>
                        <div className="flex justify-between gap-4">
                            <span className="text-muted-foreground">Pain Score</span>
                            <span>{triage.pain_score ?? '—'}</span>
                        </div>
                        {triage.notes && (
                            <div className="flex justify-between gap-4">
                                <span className="text-muted-foreground">Notes</span>
                                <span className="text-right">{triage.notes}</span>
                            </div>
                        )}
                    </div>
                )}

                {can('vital-signs.view') && (
                    <div className="space-y-3 border-t pt-4">
                        <h3 className="text-sm font-medium">Vital Signs</h3>

                        {isOpen && can('vital-signs.create') && <VitalSignsForm encounterId={encounterId} />}

                        <VitalSignsHistory encounterId={encounterId} vitalSigns={triage.vital_signs} />
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

const CATEGORY_OPTIONS: TriageCategory[] = ['emergency', 'very_urgent', 'urgent', 'non_urgent'];

function TriageAssessmentForm({ encounterId, triage }: { encounterId: number; triage: TriageAssessment }) {
    const { data, setData, put, processing, errors } = useForm<TriageUpdateFormData>({
        triage_category: triage.triage_category ?? '',
        chief_complaint: triage.chief_complaint ?? '',
        pain_score: triage.pain_score?.toString() ?? '',
        notes: triage.notes ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('clinical-encounters.triage.update', encounterId), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-4 border-t pt-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-1.5">
                    <Label htmlFor="triage_category">Triage Category</Label>
                    <Select value={data.triage_category} onValueChange={(value) => setData('triage_category', value as TriageCategory)}>
                        <SelectTrigger id="triage_category">
                            <SelectValue placeholder="Select a category" />
                        </SelectTrigger>
                        <SelectContent>
                            {CATEGORY_OPTIONS.map((category) => (
                                <SelectItem key={category} value={category}>
                                    {triageCategoryLabel[category]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.triage_category} />
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor="pain_score">Pain Score (0–10)</Label>
                    <Input
                        id="pain_score"
                        type="number"
                        min={0}
                        max={10}
                        value={data.pain_score}
                        onChange={(e) => setData('pain_score', e.target.value)}
                    />
                    <InputError message={errors.pain_score} />
                </div>

                <div className="grid gap-1.5 sm:col-span-2">
                    <Label htmlFor="chief_complaint">Chief Complaint</Label>
                    <Input
                        id="chief_complaint"
                        value={data.chief_complaint}
                        onChange={(e) => setData('chief_complaint', e.target.value)}
                        placeholder="What the patient presents with"
                    />
                    <InputError message={errors.chief_complaint} />
                </div>

                <div className="grid gap-1.5 sm:col-span-2">
                    <Label htmlFor="triage_notes">Notes</Label>
                    <Textarea id="triage_notes" rows={2} value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                    <InputError message={errors.notes} />
                </div>
            </div>

            <div className="flex justify-end">
                <Button type="submit" size="sm" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Save Assessment
                </Button>
            </div>
        </form>
    );
}
