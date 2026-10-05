import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { clinicalStatusLabel, clinicalStatusVariant } from '@/lib/admission-status';
import { clinicalEncounterStatusLabel, clinicalEncounterStatusVariant } from '@/lib/clinical-encounter-status';
import { type BreadcrumbItem } from '@/types';
import { type Admission, type ClinicalStatus } from '@/types/admission';
import { type InpatientEncounter } from '@/types/clinical-encounter';
import { Head, Link, useForm } from '@inertiajs/react';
import { Activity, BedDouble, CheckCircle, Circle, Clock, FileText, FlaskConical, Pill, Plus, Radio, Stethoscope, User } from 'lucide-react';
import { useState } from 'react';

interface VitalSign {
    id: number;
    recorded_at: string;
    temperature_c: number | null;
    pulse_rate?: number | null;
    pulse_bpm?: number | null;
    blood_pressure_systolic?: number | null;
    systolic_mmhg?: number | null;
    blood_pressure_diastolic?: number | null;
    diastolic_mmhg?: number | null;
    respiratory_rate?: number | null;
    respiratory_rate_bpm?: number | null;
    oxygen_saturation?: number | null;
    oxygen_saturation_pct?: number | null;
    weight_kg: number | null;
    height_cm: number | null;
    bmi: number | null;
    recordedBy?: { id: number; UserName: string } | null;
}

interface InpatientPractitioner {
    id: number;
    practitioner_number: string;
    staff_id: number;
    staff?: { first_name: string; last_name: string };
}

interface ClinicalProfileProps {
    admission: Admission;
    encounters: InpatientEncounter[];
    vitals: VitalSign[];
    practitioners: InpatientPractitioner[];
    clinicalStatuses: ClinicalStatus[];
    canOpenEncounter: boolean;
    canUpdateClinicalStatus: boolean;
    canRecordVitals: boolean;
    canCreateNote: boolean;
    canManageCarePlan: boolean;
    canCreateOrders: boolean;
    canViewMedications: boolean;
}

const itemTypeLabel: Record<string, string> = {
    medication: 'Medication',
    procedure: 'Procedure',
    clinical_intervention: 'Intervention',
    referral: 'Referral',
    monitoring: 'Monitoring',
    follow_up: 'Follow-up',
    other: 'Other',
};

const itemStatusVariant: Record<string, 'default' | 'secondary' | 'outline' | 'destructive'> = {
    planned: 'outline',
    active: 'default',
    completed: 'secondary',
    cancelled: 'destructive',
};

const priorityVariant: Record<string, 'default' | 'secondary' | 'outline' | 'destructive'> = {
    low: 'secondary',
    medium: 'outline',
    high: 'destructive',
};

function formatDateTime(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }
    return new Date(value).toLocaleDateString(undefined, { dateStyle: 'medium' });
}

function daysSince(dateStr: string | null | undefined): string {
    if (!dateStr) {
        return '—';
    }
    const days = Math.floor((Date.now() - new Date(dateStr).getTime()) / 86_400_000);
    return days === 0 ? 'Admitted today' : `${days} day${days === 1 ? '' : 's'}`;
}

function practitionerFullName(practitioner: InpatientPractitioner | null | undefined): string {
    if (!practitioner) {
        return '—';
    }
    if (practitioner.staff) {
        return `${practitioner.staff.first_name} ${practitioner.staff.last_name}`;
    }
    return practitioner.practitioner_number;
}

function ProgressNoteForm({ encounterId, onCancel }: { encounterId: number; onCancel: () => void }) {
    const noteForm = useForm<{ note_type: string; note_content: string }>({
        note_type: 'progress',
        note_content: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        noteForm.post(route('clinical-encounters.clinical-notes.store', encounterId), {
            onSuccess: onCancel,
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-3 rounded-lg border p-4">
            <div className="flex items-center justify-between">
                <Label className="text-sm font-medium">Record Progress Note</Label>
                <Button type="button" variant="ghost" size="sm" onClick={onCancel}>
                    Cancel
                </Button>
            </div>
            <div className="flex flex-col gap-1">
                <Label htmlFor={`note-type-${encounterId}`} className="text-xs">
                    Note Type
                </Label>
                <Select
                    value={noteForm.data.note_type}
                    onValueChange={(v) => noteForm.setData('note_type', v)}
                >
                    <SelectTrigger id={`note-type-${encounterId}`} className="h-8">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="progress">Progress Note</SelectItem>
                        <SelectItem value="nursing">Nursing Note</SelectItem>
                        <SelectItem value="clinical_observation">Clinical Observation</SelectItem>
                        <SelectItem value="procedure">Procedure Note</SelectItem>
                        <SelectItem value="other">Other</SelectItem>
                    </SelectContent>
                </Select>
                {noteForm.errors.note_type && (
                    <p className="text-destructive text-xs">{noteForm.errors.note_type}</p>
                )}
            </div>
            <div className="flex flex-col gap-1">
                <Label htmlFor={`note-content-${encounterId}`} className="text-xs">
                    Note
                </Label>
                <Textarea
                    id={`note-content-${encounterId}`}
                    rows={4}
                    value={noteForm.data.note_content}
                    onChange={(e) => noteForm.setData('note_content', e.target.value)}
                    placeholder="Enter clinical note…"
                />
                {noteForm.errors.note_content && (
                    <p className="text-destructive text-xs">{noteForm.errors.note_content}</p>
                )}
            </div>
            <Button type="submit" size="sm" disabled={noteForm.processing || !noteForm.data.note_content.trim()}>
                Save Note
            </Button>
        </form>
    );
}

function WardRoundEncounterPanel({
    encounter,
    canCreateNote,
    canManageCarePlan,
    canCreateOrders,
}: {
    encounter: InpatientEncounter;
    canCreateNote: boolean;
    canManageCarePlan: boolean;
    canCreateOrders: boolean;
}) {
    const [showNoteForm, setShowNoteForm] = useState(false);
    const prac = encounter.practitioner;
    const pracName = prac?.staff ? `${prac.staff.first_name} ${prac.staff.last_name}` : (prac?.practitioner_number ?? '—');
    const isActive = encounter.status === 'open' || encounter.status === 'in_progress';

    return (
        <div className="flex flex-col gap-4 rounded-lg border p-4">
            {/* Encounter header */}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <FileText className="text-muted-foreground h-4 w-4" />
                    <span className="font-mono text-sm font-medium">{encounter.encounter_number}</span>
                    <Badge variant={clinicalEncounterStatusVariant[encounter.status]}>
                        {clinicalEncounterStatusLabel[encounter.status]}
                    </Badge>
                </div>
                <div className="flex items-center gap-2">
                    <p className="text-muted-foreground text-xs">
                        {formatDateTime(encounter.started_at)} · {pracName}
                    </p>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={route('clinical-encounters.show', encounter.id)}>Open Encounter</Link>
                    </Button>
                </div>
            </div>

            {/* Diagnoses */}
            {encounter.diagnoses && encounter.diagnoses.length > 0 && (
                <div>
                    <h4 className="text-muted-foreground mb-2 text-xs font-semibold uppercase tracking-wide">
                        Diagnoses
                    </h4>
                    <div className="flex flex-col gap-1">
                        {encounter.diagnoses.map((d) => (
                            <div key={d.id} className="flex items-center gap-2 text-sm">
                                {d.status === 'cancelled' ? (
                                    <Circle className="text-muted-foreground h-3 w-3" />
                                ) : (
                                    <CheckCircle className="h-3 w-3 text-green-600" />
                                )}
                                <span
                                    className={
                                        d.status === 'cancelled' ? 'text-muted-foreground line-through' : ''
                                    }
                                >
                                    {d.diagnosis_code
                                        ? `${d.diagnosis_code.code} — ${d.diagnosis_code.description}`
                                        : 'Unknown'}
                                </span>
                                <Badge variant="outline" className="text-xs">
                                    {d.diagnosis_type}
                                </Badge>
                                {d.status !== 'confirmed' && d.status !== 'cancelled' && (
                                    <Badge variant="secondary" className="text-xs">
                                        {d.status}
                                    </Badge>
                                )}
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Care Plan */}
            {encounter.carePlan && (
                <div>
                    <div className="mb-2 flex items-center justify-between">
                        <h4 className="text-muted-foreground text-xs font-semibold uppercase tracking-wide">
                            Treatment Plan
                        </h4>
                        <Badge variant={encounter.carePlan.status === 'active' ? 'default' : 'secondary'}>
                            {encounter.carePlan.status}
                        </Badge>
                    </div>

                    {encounter.carePlan.items && encounter.carePlan.items.length > 0 ? (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="text-xs">Type</TableHead>
                                        <TableHead className="text-xs">Description</TableHead>
                                        <TableHead className="text-xs">Priority</TableHead>
                                        <TableHead className="text-xs">Status</TableHead>
                                        <TableHead className="text-xs">Start</TableHead>
                                        <TableHead className="text-xs">Activated</TableHead>
                                        <TableHead className="text-xs">Completed</TableHead>
                                        <TableHead className="text-xs">Completed by</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {encounter.carePlan.items.map((item) => (
                                        <TableRow key={item.id}>
                                            <TableCell className="text-xs">
                                                {itemTypeLabel[item.type] ?? item.type}
                                            </TableCell>
                                            <TableCell className="max-w-[200px] truncate text-xs" title={item.description}>
                                                {item.description}
                                            </TableCell>
                                            <TableCell>
                                                {item.priority && (
                                                    <Badge variant={priorityVariant[item.priority]} className="text-xs">
                                                        {item.priority}
                                                    </Badge>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant={itemStatusVariant[item.status] ?? 'outline'} className="text-xs">
                                                    {item.status}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-xs">{formatDate(item.start_date)}</TableCell>
                                            <TableCell className="text-xs">{formatDateTime(item.activated_at)}</TableCell>
                                            <TableCell className="text-xs">{formatDateTime(item.completed_at)}</TableCell>
                                            <TableCell className="text-xs">
                                                {item.completed_by ? item.completed_by.name : '—'}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    ) : (
                        <p className="text-muted-foreground text-xs">No treatment items added yet.</p>
                    )}

                    {canManageCarePlan && (
                        <div className="mt-2">
                            <Button size="sm" variant="outline" asChild>
                                <Link href={route('clinical-encounters.show', encounter.id)}>
                                    Manage Treatment Plan
                                </Link>
                            </Button>
                        </div>
                    )}
                </div>
            )}

            {/* Order summaries */}
            <div className="flex flex-wrap gap-4 text-sm">
                {encounter.laboratoryOrders && encounter.laboratoryOrders.length > 0 && (
                    <div className="flex items-center gap-1">
                        <FlaskConical className="text-muted-foreground h-4 w-4" />
                        <span>{encounter.laboratoryOrders.length} lab order{encounter.laboratoryOrders.length !== 1 ? 's' : ''}</span>
                    </div>
                )}
                {encounter.radiologyOrders && encounter.radiologyOrders.length > 0 && (
                    <div className="flex items-center gap-1">
                        <Radio className="text-muted-foreground h-4 w-4" />
                        <span>{encounter.radiologyOrders.length} radiology order{encounter.radiologyOrders.length !== 1 ? 's' : ''}</span>
                    </div>
                )}
                {encounter.prescriptionOrders && encounter.prescriptionOrders.length > 0 && (
                    <div className="flex items-center gap-1">
                        <Pill className="text-muted-foreground h-4 w-4" />
                        <span>{encounter.prescriptionOrders.length} prescription{encounter.prescriptionOrders.length !== 1 ? 's' : ''}</span>
                    </div>
                )}
            </div>

            {/* Clinical notes */}
            {encounter.clinicalNotes && encounter.clinicalNotes.length > 0 && (
                <div>
                    <h4 className="text-muted-foreground mb-2 text-xs font-semibold uppercase tracking-wide">
                        Progress Notes
                    </h4>
                    <div className="flex flex-col gap-2">
                        {encounter.clinicalNotes.slice(0, 3).map((note) => (
                            <div key={note.id} className="rounded border p-3 text-sm">
                                <div className="text-muted-foreground mb-1 flex items-center gap-2 text-xs">
                                    <span>{note.author?.name ?? '—'}</span>
                                    <span>·</span>
                                    <span>{formatDateTime(note.created_at)}</span>
                                    <Badge variant="outline" className="text-xs">
                                        {note.note_type.replace('_', ' ')}
                                    </Badge>
                                    <Badge variant={note.status === 'signed' ? 'default' : 'secondary'} className="text-xs">
                                        {note.status}
                                    </Badge>
                                </div>
                                <p className="line-clamp-3 whitespace-pre-wrap text-xs">{note.note_content}</p>
                            </div>
                        ))}
                        {encounter.clinicalNotes.length > 3 && (
                            <p className="text-muted-foreground text-xs">
                                +{encounter.clinicalNotes.length - 3} more note{encounter.clinicalNotes.length - 3 !== 1 ? 's' : ''} — open the encounter to view all.
                            </p>
                        )}
                    </div>
                </div>
            )}

            {/* Inline progress note form */}
            {canCreateNote && isActive && (
                <div>
                    {showNoteForm ? (
                        <ProgressNoteForm encounterId={encounter.id} onCancel={() => setShowNoteForm(false)} />
                    ) : (
                        <Button size="sm" variant="outline" onClick={() => setShowNoteForm(true)}>
                            <Plus className="mr-1 h-3 w-3" />
                            Record Progress Note
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
}

export default function ClinicalProfile({
    admission,
    encounters,
    vitals,
    practitioners,
    clinicalStatuses,
    canOpenEncounter,
    canUpdateClinicalStatus,
    canRecordVitals,
    canCreateNote,
    canManageCarePlan,
    canCreateOrders,
    canViewMedications,
}: ClinicalProfileProps) {
    const patientName = admission.patient
        ? `${admission.patient.first_name} ${admission.patient.last_name}`
        : 'Unknown Patient';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Inpatient Ward', href: route('inpatient.dashboard') },
        { title: patientName, href: '#' },
    ];

    const statusForm = useForm<{ clinical_status: ClinicalStatus | '' }>({
        clinical_status: admission.clinical_status ?? '',
    });

    const encounterForm = useForm<{ practitioner_id: string }>({
        practitioner_id: '',
    });

    const submitClinicalStatus = () => {
        if (!statusForm.data.clinical_status) {
            return;
        }
        statusForm.post(route('inpatient.update-clinical-status', admission.id));
    };

    const openEncounter = () => {
        if (!encounterForm.data.practitioner_id) {
            return;
        }
        encounterForm.post(route('inpatient.open-encounter', admission.id));
    };

    const latestVital = vitals[0] ?? null;

    const hasOpenEncounter = encounters.some((e) => e.status === 'open' || e.status === 'in_progress');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Inpatient — ${patientName}`} />

            <div className="flex flex-col gap-6 p-4">
                {/* Header */}
                <PageHeader
                    title={patientName}
                    description={`${admission.patient?.patient_number ?? ''} · Admission ${admission.admission_number}`}
                    actions={
                        <div className="flex items-center gap-2">
                            {admission.clinical_status && (
                                <Badge variant={clinicalStatusVariant[admission.clinical_status as ClinicalStatus]}>
                                    {clinicalStatusLabel[admission.clinical_status as ClinicalStatus]}
                                </Badge>
                            )}
                            <Button variant="outline" asChild>
                                <Link href={route('admissions.show', admission.id)}>Admission Details</Link>
                            </Button>
                        </div>
                    }
                />

                {/* Summary cards */}
                <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                    <Card>
                        <CardContent className="pt-4">
                            <div className="text-muted-foreground flex items-center gap-2 text-sm">
                                <BedDouble className="h-4 w-4" />
                                Ward / Room
                            </div>
                            <p className="mt-1 font-medium">
                                {[admission.ward_type, admission.room_type].filter(Boolean).join(' / ') || '—'}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-4">
                            <div className="text-muted-foreground flex items-center gap-2 text-sm">
                                <Clock className="h-4 w-4" />
                                Length of Stay
                            </div>
                            <p className="mt-1 font-medium">{daysSince(admission.admitted_at)}</p>
                            <p className="text-muted-foreground text-xs">{formatDate(admission.admitted_at)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-4">
                            <div className="text-muted-foreground flex items-center gap-2 text-sm">
                                <User className="h-4 w-4" />
                                Attending
                            </div>
                            <p className="mt-1 font-medium">
                                {practitionerFullName(admission.practitioner as unknown as InpatientPractitioner)}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-4">
                            <div className="text-muted-foreground flex items-center gap-2 text-sm">
                                <Activity className="h-4 w-4" />
                                Latest Vitals
                            </div>
                            {latestVital ? (
                                (() => {
                                    const pulse = latestVital.pulse_bpm ?? latestVital.pulse_rate;
                                    const sys = latestVital.systolic_mmhg ?? latestVital.blood_pressure_systolic;
                                    const dia = latestVital.diastolic_mmhg ?? latestVital.blood_pressure_diastolic;
                                    const spo2 = latestVital.oxygen_saturation_pct ?? latestVital.oxygen_saturation;

                                    return (
                                        <div className="mt-1 text-sm">
                                            {pulse != null && <span className="mr-2">HR {pulse}</span>}
                                            {sys != null && (
                                                <span className="mr-2">
                                                    BP {sys}/{dia ?? '—'}
                                                </span>
                                            )}
                                            {spo2 != null && (
                                                <span>SpO₂ {spo2}%</span>
                                            )}
                                        </div>
                                    );
                                })()
                            ) : (
                                <p className="text-muted-foreground mt-1 text-sm">No vitals recorded</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Admission diagnosis */}
                {admission.diagnosis_at_admission && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Admission Diagnosis</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm">{admission.diagnosis_at_admission}</p>
                        </CardContent>
                    </Card>
                )}

                {/* Clinical actions */}
                <div className="flex flex-wrap gap-4">
                    {canUpdateClinicalStatus && (
                        <Card className="min-w-[260px] flex-1">
                            <CardHeader>
                                <CardTitle className="text-base">Update Clinical Status</CardTitle>
                            </CardHeader>
                            <CardContent className="flex items-end gap-2">
                                <Select
                                    value={statusForm.data.clinical_status}
                                    onValueChange={(value) => statusForm.setData('clinical_status', value as ClinicalStatus)}
                                >
                                    <SelectTrigger className="flex-1">
                                        <SelectValue placeholder="Select status" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {clinicalStatuses.map((s) => (
                                            <SelectItem key={s} value={s}>
                                                {clinicalStatusLabel[s]}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <Button
                                    onClick={submitClinicalStatus}
                                    disabled={!statusForm.data.clinical_status || statusForm.processing}
                                >
                                    Update
                                </Button>
                            </CardContent>
                        </Card>
                    )}

                    {canOpenEncounter && (
                        <Card className="min-w-[260px] flex-1">
                            <CardHeader>
                                <CardTitle className="text-base">Open Clinical Encounter</CardTitle>
                            </CardHeader>
                            <CardContent className="flex items-end gap-2">
                                <Select
                                    value={encounterForm.data.practitioner_id}
                                    onValueChange={(value) => encounterForm.setData('practitioner_id', value)}
                                >
                                    <SelectTrigger className="flex-1">
                                        <SelectValue placeholder="Select practitioner" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {practitioners.map((p) => (
                                            <SelectItem key={p.id} value={String(p.id)}>
                                                {p.staff
                                                    ? `${p.staff.first_name} ${p.staff.last_name}`
                                                    : p.practitioner_number}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <Button
                                    onClick={openEncounter}
                                    disabled={!encounterForm.data.practitioner_id || encounterForm.processing}
                                >
                                    <Plus className="mr-1 h-4 w-4" />
                                    Open
                                </Button>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Vital signs log */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Activity className="h-4 w-4" />
                            Vital Signs
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {vitals.length === 0 ? (
                            <p className="text-muted-foreground text-sm">No vital signs recorded yet.</p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Time</TableHead>
                                        <TableHead>Temp (°C)</TableHead>
                                        <TableHead>HR</TableHead>
                                        <TableHead>BP</TableHead>
                                        <TableHead>RR</TableHead>
                                        <TableHead>SpO₂ (%)</TableHead>
                                        <TableHead>Weight (kg)</TableHead>
                                        <TableHead>BMI</TableHead>
                                        <TableHead>Recorded by</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {vitals.map((vital) => {
                                        const pulse = vital.pulse_bpm ?? vital.pulse_rate;
                                        const sys = vital.systolic_mmhg ?? vital.blood_pressure_systolic;
                                        const dia = vital.diastolic_mmhg ?? vital.blood_pressure_diastolic;
                                        const resp = vital.respiratory_rate_bpm ?? vital.respiratory_rate;
                                        const spo2 = vital.oxygen_saturation_pct ?? vital.oxygen_saturation;

                                        return (
                                            <TableRow key={vital.id}>
                                                <TableCell className="text-sm">{formatDateTime(vital.recorded_at)}</TableCell>
                                                <TableCell>{vital.temperature_c ?? '—'}</TableCell>
                                                <TableCell>{pulse ?? '—'}</TableCell>
                                                <TableCell>
                                                    {sys != null && dia != null ? `${sys}/${dia}` : '—'}
                                                </TableCell>
                                                <TableCell>{resp ?? '—'}</TableCell>
                                                <TableCell>{spo2 ?? '—'}</TableCell>
                                                <TableCell>{vital.weight_kg ?? '—'}</TableCell>
                                                <TableCell>{vital.bmi ?? '—'}</TableCell>
                                                <TableCell className="text-sm">{vital.recordedBy?.UserName ?? '—'}</TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                {/* Medications link card */}
                {canViewMedications && (
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Pill className="h-4 w-4" />
                                Medications
                            </CardTitle>
                            <Button size="sm" asChild>
                                <Link href={route('inpatient.medications.worklist', admission.id)}>Open MAR</Link>
                            </Button>
                        </CardHeader>
                        <CardContent>
                            <p className="text-muted-foreground text-sm">
                                View and record medication administrations for this admission.
                            </p>
                        </CardContent>
                    </Card>
                )}

                {/* Ward round — per-encounter clinical panels */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Stethoscope className="h-4 w-4" />
                            Ward Round — Clinical Encounters ({encounters.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {encounters.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No clinical encounters opened yet. Use the panel above to open an encounter and begin recording clinical
                                work.
                            </p>
                        ) : (
                            <div className="flex flex-col gap-4">
                                {encounters.map((encounter) => (
                                    <WardRoundEncounterPanel
                                        key={encounter.id}
                                        encounter={encounter}
                                        canCreateNote={canCreateNote}
                                        canManageCarePlan={canManageCarePlan}
                                        canCreateOrders={canCreateOrders}
                                    />
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
