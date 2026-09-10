import { CancelDiagnosisDialog } from '@/components/diagnosis/cancel-diagnosis-dialog';
import { CorrectDiagnosisDialog } from '@/components/diagnosis/correct-diagnosis-dialog';
import { DiagnosisCodeSearch } from '@/components/diagnosis/diagnosis-code-search';
import { SetPrimaryDiagnosisDialog } from '@/components/diagnosis/set-primary-diagnosis-dialog';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Radio, RadioGroup } from '@/components/ui/radio-group';
import { Textarea } from '@/components/ui/textarea';
import { usePermissions } from '@/hooks/use-permissions';
import {
    allowedDiagnosisStatusActions,
    canCancelDiagnosis,
    canCorrectDiagnosis,
    diagnosisStatusLabel,
    diagnosisStatusVariant,
    diagnosisTypeLabel,
    isDiagnosisActive,
    isDiagnosisDraft,
} from '@/lib/diagnosis-status';
import { type Consultation } from '@/types/consultation';
import { type DiagnosisCodeSearchResult, type DiagnosisFormData, type DiagnosisType, type EncounterDiagnosis } from '@/types/diagnosis';
import { router, useForm } from '@inertiajs/react';
import { CheckCircle2, ClipboardPlus, LoaderCircle, PenLine, Star, XCircle } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

interface DiagnosisSectionProps {
    encounterId: number;
    consultation: Consultation | null;
    diagnoses: EncounterDiagnosis[];
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

const STATUS_LABELS: Record<string, string> = { confirmed: 'Confirm', resolved: 'Resolve' };

/**
 * The diagnosis workflow embedded on the clinical encounter screen (spec
 * §25/28), mirroring ConsultationPanel/TriagePanel — there is no standalone
 * diagnosis page. Requires the consultation to be in_progress or completed
 * (spec §11); the backend re-validates this independently.
 */
export function DiagnosisSection({ encounterId, consultation, diagnoses }: DiagnosisSectionProps) {
    const { can } = usePermissions();
    const consultationEngaged = consultation?.status === 'in_progress' || consultation?.status === 'completed';
    const hasActivePrimary = diagnoses.some((d) => d.diagnosis_type === 'primary' && isDiagnosisActive(d.status));

    if (!consultationEngaged) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Diagnosis</CardTitle>
                </CardHeader>
                <CardContent>
                    <p className="text-muted-foreground text-sm">A consultation must be started before a diagnosis can be recorded.</p>
                </CardContent>
            </Card>
        );
    }

    const runStatusAction = (diagnosisId: number, status: string) => {
        router.post(route('clinical-encounters.diagnoses.status', [encounterId, diagnosisId]), { status }, { preserveScroll: true });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Diagnosis</CardTitle>
            </CardHeader>
            <CardContent className="space-y-6">
                {diagnoses.length > 0 ? (
                    <ul className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                        {diagnoses.map((diagnosis) => (
                            <li key={diagnosis.id} className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge variant={diagnosis.diagnosis_type === 'primary' ? 'default' : 'outline'}>
                                            {diagnosisTypeLabel[diagnosis.diagnosis_type]}
                                        </Badge>
                                        <Badge variant={diagnosisStatusVariant[diagnosis.status]}>{diagnosisStatusLabel[diagnosis.status]}</Badge>
                                        <span className="font-medium">
                                            {diagnosis.diagnosis_code
                                                ? `${diagnosis.diagnosis_code.code} — ${diagnosis.diagnosis_code.description}`
                                                : '—'}
                                        </span>
                                    </div>

                                    <div className="flex flex-wrap items-center gap-2">
                                        {diagnosis.diagnosis_type === 'secondary' &&
                                            isDiagnosisActive(diagnosis.status) &&
                                            can('diagnosis.primary') && (
                                                <SetPrimaryDiagnosisDialog
                                                    encounterId={encounterId}
                                                    diagnosisId={diagnosis.id}
                                                    hasExistingPrimary={hasActivePrimary}
                                                    trigger={
                                                        <Button size="sm" variant="outline">
                                                            <Star className="h-4 w-4" />
                                                            Set as Primary
                                                        </Button>
                                                    }
                                                />
                                            )}

                                        {allowedDiagnosisStatusActions(diagnosis.status).map((status) => (
                                            <Button
                                                key={status}
                                                size="sm"
                                                onClick={() => runStatusAction(diagnosis.id, status)}
                                                disabled={!can('diagnosis.status')}
                                            >
                                                <CheckCircle2 className="h-4 w-4" />
                                                {STATUS_LABELS[status] ?? status}
                                            </Button>
                                        ))}

                                        {canCancelDiagnosis(diagnosis.status) && can('diagnosis.cancel') && (
                                            <CancelDiagnosisDialog
                                                encounterId={encounterId}
                                                diagnosisId={diagnosis.id}
                                                trigger={
                                                    <Button size="sm" variant="destructive">
                                                        <XCircle className="h-4 w-4" />
                                                        Cancel
                                                    </Button>
                                                }
                                            />
                                        )}

                                        {canCorrectDiagnosis(diagnosis.status) && can('diagnosis.correct') && (
                                            <CorrectDiagnosisDialog
                                                encounterId={encounterId}
                                                diagnosis={diagnosis}
                                                trigger={
                                                    <Button size="sm" variant="outline">
                                                        <PenLine className="h-4 w-4" />
                                                        Correct
                                                    </Button>
                                                }
                                            />
                                        )}
                                    </div>
                                </div>

                                {isDiagnosisDraft(diagnosis.status) && can('diagnosis.update') ? (
                                    <EditDiagnosisForm encounterId={encounterId} diagnosis={diagnosis} />
                                ) : (
                                    <div className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                        <span>{diagnosis.diagnosed_by?.name ?? '—'}</span>
                                        <span>{formatDateTime(diagnosis.diagnosed_at)}</span>
                                        {diagnosis.clinical_note && <span className="italic">"{diagnosis.clinical_note}"</span>}
                                        {diagnosis.status === 'cancelled' && diagnosis.cancellation_reason && (
                                            <span>Cancelled: {diagnosis.cancellation_reason}</span>
                                        )}
                                        {diagnosis.corrected_at && <span>Corrected {formatDateTime(diagnosis.corrected_at)}</span>}
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-muted-foreground text-sm">No diagnoses have been recorded for this encounter yet.</p>
                )}

                {can('diagnosis.create') && <AddDiagnosisForm encounterId={encounterId} hasActivePrimary={hasActivePrimary} />}
            </CardContent>
        </Card>
    );
}

function AddDiagnosisForm({ encounterId, hasActivePrimary }: { encounterId: number; hasActivePrimary: boolean }) {
    const [selectedCode, setSelectedCode] = useState<DiagnosisCodeSearchResult | null>(null);
    const { data, setData, post, processing, errors, reset } = useForm<DiagnosisFormData>({
        diagnosis_code_id: '',
        diagnosis_type: hasActivePrimary ? 'secondary' : 'primary',
        clinical_note: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('clinical-encounters.diagnoses.store', encounterId), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setSelectedCode(null);
            },
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4 border-t pt-4">
            <h3 className="text-sm font-medium">Add Diagnosis</h3>

            <div className="grid gap-1.5">
                <Label>Diagnosis Code</Label>
                <DiagnosisCodeSearch
                    selected={selectedCode}
                    onSelect={(code) => {
                        setSelectedCode(code);
                        setData('diagnosis_code_id', String(code.id));
                    }}
                />
                <InputError message={errors.diagnosis_code_id} />
            </div>

            <div className="grid gap-1.5">
                <Label>Classification</Label>
                <RadioGroup>
                    <Radio
                        name="diagnosis_type"
                        value="primary"
                        label="Primary"
                        checked={data.diagnosis_type === 'primary'}
                        disabled={hasActivePrimary}
                        onChange={() => setData('diagnosis_type', 'primary' as DiagnosisType)}
                    />
                    <Radio
                        name="diagnosis_type"
                        value="secondary"
                        label="Secondary"
                        checked={data.diagnosis_type === 'secondary'}
                        onChange={() => setData('diagnosis_type', 'secondary' as DiagnosisType)}
                    />
                </RadioGroup>
                {hasActivePrimary && (
                    <p className="text-muted-foreground text-xs">
                        An active primary diagnosis already exists. Add this as secondary, then use "Set as Primary" to replace it.
                    </p>
                )}
                <InputError message={errors.diagnosis_type} />
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="diagnosis_clinical_note">Clinical Note</Label>
                <Textarea
                    id="diagnosis_clinical_note"
                    rows={2}
                    value={data.clinical_note}
                    onChange={(e) => setData('clinical_note', e.target.value)}
                    placeholder="Optional — findings supporting this diagnosis"
                />
                <InputError message={errors.clinical_note} />
            </div>

            <div className="flex justify-end">
                <Button type="submit" size="sm" disabled={processing || !data.diagnosis_code_id}>
                    {processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <ClipboardPlus className="h-4 w-4" />}
                    Add Diagnosis
                </Button>
            </div>
        </form>
    );
}

function EditDiagnosisForm({ encounterId, diagnosis }: { encounterId: number; diagnosis: EncounterDiagnosis }) {
    const [selectedCode, setSelectedCode] = useState<DiagnosisCodeSearchResult | null>(diagnosis.diagnosis_code);
    const { data, setData, put, processing, errors } = useForm({
        diagnosis_code_id: diagnosis.diagnosis_code ? String(diagnosis.diagnosis_code.id) : '',
        clinical_note: diagnosis.clinical_note ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('clinical-encounters.diagnoses.update', [encounterId, diagnosis.id]), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-3 rounded-md border p-3">
            <div className="grid gap-1.5">
                <Label>Diagnosis Code</Label>
                <DiagnosisCodeSearch
                    selected={selectedCode}
                    onSelect={(code) => {
                        setSelectedCode(code);
                        setData('diagnosis_code_id', String(code.id));
                    }}
                />
                <InputError message={errors.diagnosis_code_id} />
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor={`edit_note_${diagnosis.id}`}>Clinical Note</Label>
                <Textarea
                    id={`edit_note_${diagnosis.id}`}
                    rows={2}
                    value={data.clinical_note}
                    onChange={(e) => setData('clinical_note', e.target.value)}
                />
                <InputError message={errors.clinical_note} />
            </div>

            <div className="flex justify-end">
                <Button type="submit" size="sm" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Save
                </Button>
            </div>
        </form>
    );
}
