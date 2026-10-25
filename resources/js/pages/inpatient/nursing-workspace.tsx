import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { clinicalStatusLabel, clinicalStatusVariant } from '@/lib/admission-status';
import { type BreadcrumbItem } from '@/types';
import { type Admission, type ClinicalStatus } from '@/types/admission';
import { Head, Link, useForm } from '@inertiajs/react';
import {
    Activity,
    AlertCircle,
    AlertTriangle,
    Baby,
    Bandage,
    CheckCircle2,
    Clock,
    FileText,
    HeartPulse,
    History,
    Pill,
    Plus,
    Scissors,
    ShieldAlert,
    ShieldCheck,
    Stethoscope,
    Users,
} from 'lucide-react';
import * as React from 'react';

interface Props {
    admission: Admission;
    encounters: any[];
    allergies: any[];
    vitals: any[];
    notes: any[];
    prescriptionOrders: any[];
    marHistory: any[];
    todayScheduledDoses: any[];
    handovers: any[];
    woundCareEvents: any[];
    escalations: any[];
    marStatuses: string[];
    routes: string[];
    canRecordVitals: boolean;
    canCreateNotes: boolean;
    canManageWoundCare: boolean;
    canCreateHandover: boolean;
    canEscalate: boolean;
    canAcknowledgeEscalation: boolean;
    canResolveEscalation: boolean;
    canManageAllergies: boolean;
    canAdministerMedication: boolean;
    canAccessMaternity?: boolean;
    maternityEligibility?: {
        allowed: boolean;
        reason: string;
        has_profile: boolean;
        is_eligible: boolean;
    };
}

export default function NursingWorkspace({
    admission,
    encounters,
    allergies,
    vitals,
    notes,
    prescriptionOrders,
    marHistory,
    todayScheduledDoses,
    handovers,
    woundCareEvents,
    escalations,
    marStatuses,
    routes,
    canRecordVitals,
    canCreateNotes,
    canManageWoundCare,
    canCreateHandover,
    canEscalate,
    canAcknowledgeEscalation,
    canResolveEscalation,
    canManageAllergies,
    canAdministerMedication,
    canAccessMaternity = false,
}: Props) {
    const patient = admission.patient;
    const patientName = patient ? `${patient.first_name} ${patient.last_name}` : 'Unknown Patient';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Inpatient Care', href: '/inpatient' },
        { title: 'Nursing Workspace', href: route('inpatient.nursing.dashboard') },
        { title: patientName, href: '#' },
    ];

    // Dialog state controllers
    const [vitalsOpen, setVitalsOpen] = React.useState(false);
    const [woundCareOpen, setWoundCareOpen] = React.useState(false);
    const [handoverOpen, setHandoverOpen] = React.useState(false);
    const [escalateOpen, setEscalateOpen] = React.useState(false);
    const [allergyOpen, setAllergyOpen] = React.useState(false);
    const [marModalOpen, setMarModalOpen] = React.useState(false);
    const [resolveEscalationOpen, setResolveEscalationOpen] = React.useState(false);
    const [selectedEscalation, setSelectedEscalation] = React.useState<any>(null);

    // 1. Vitals form
    const vitalsForm = useForm({
        temperature_c: '',
        systolic_mmhg: '',
        diastolic_mmhg: '',
        pulse_bpm: '',
        respiratory_rate_bpm: '',
        oxygen_saturation_pct: '',
        pain_score: '',
        consciousness_level: 'alert',
        oxygen_support: 'room_air',
        oxygen_flow_lpm: '',
        intake_ml: '',
        output_ml: '',
        observation_notes: '',
        measured_at: new Date().toISOString().slice(0, 16),
    });

    const submitVitals = (e: React.FormEvent) => {
        e.preventDefault();
        vitalsForm.post(route('inpatient.store-vitals', admission.id), {
            onSuccess: () => {
                setVitalsOpen(false);
                vitalsForm.reset();
            },
        });
    };

    // 2. Wound care form
    const woundForm = useForm({
        site: '',
        indication: '',
        assessment: '',
        appearance: '',
        dressing_performed: '',
        materials_used: '',
        pain_score: '',
        patient_response: '',
        complications: '',
        concerning_findings: false,
        performed_at: new Date().toISOString().slice(0, 16),
        next_dressing_due_at: '',
        follow_up_instructions: '',
    });

    const submitWoundCare = (e: React.FormEvent) => {
        e.preventDefault();
        woundForm.post(route('inpatient.nursing.wound-care.store', admission.id), {
            onSuccess: () => {
                setWoundCareOpen(false);
                woundForm.reset();
            },
        });
    };

    // 3. Handover form
    const handoverForm = useForm({
        shift: 'morning',
        handover_at: new Date().toISOString().slice(0, 16),
        situation_summary: '',
        pending_tasks: '',
        outstanding_doses: todayScheduledDoses.filter((d) => d.status === 'pending' || d.is_overdue),
    });

    const submitHandover = (e: React.FormEvent) => {
        e.preventDefault();
        handoverForm.post(route('inpatient.nursing.handovers.store', admission.id), {
            onSuccess: () => {
                setHandoverOpen(false);
                handoverForm.reset();
            },
        });
    };

    // 4. Escalation form
    const escalateForm = useForm({
        reason: '',
        notified_practitioner_id: admission.practitioner_id ? String(admission.practitioner_id) : '',
        vital_sign_id: vitals[0]?.id ? String(vitals[0].id) : '',
    });

    const submitEscalation = (e: React.FormEvent) => {
        e.preventDefault();
        escalateForm.post(route('inpatient.nursing.escalations.store', admission.id), {
            onSuccess: () => {
                setEscalateOpen(false);
                escalateForm.reset();
            },
        });
    };

    // 5. Allergy form
    const allergyForm = useForm({
        allergen: '',
        severity: 'moderate',
        reaction: '',
    });

    const submitAllergy = (e: React.FormEvent) => {
        e.preventDefault();
        allergyForm.post(route('inpatient.nursing.allergies.store', admission.id), {
            onSuccess: () => {
                setAllergyOpen(false);
                allergyForm.reset();
            },
        });
    };

    // 6. MAR Quick record form
    const marForm = useForm({
        prescription_order_item_id: '',
        clinical_encounter_id: '',
        status: 'administered',
        administered_at: new Date().toISOString().slice(0, 16),
        scheduled_at: '',
        slot_key: '',
        dose: '',
        dose_unit: '',
        route: 'oral',
        reason: '',
        allergy_override_reason: '',
        notes: '',
    });

    const openMarModalForDose = (dose: any) => {
        const order = prescriptionOrders.find((o) => o.items.some((i: any) => i.id === dose.prescription_order_item_id));
        marForm.setData({
            prescription_order_item_id: String(dose.prescription_order_item_id),
            clinical_encounter_id: order ? String(order.clinical_encounter_id) : String(encounters[0]?.id || ''),
            status: 'administered',
            administered_at: new Date().toISOString().slice(0, 16),
            scheduled_at: dose.scheduled_at,
            slot_key: dose.slot_key || '',
            dose: dose.dose,
            dose_unit: dose.dose_unit,
            route: dose.route,
            reason: '',
            allergy_override_reason: '',
            notes: '',
        });
        setMarModalOpen(true);
    };

    const submitMar = (e: React.FormEvent) => {
        e.preventDefault();
        marForm.post(route('inpatient.medications.store', admission.id), {
            onSuccess: () => {
                setMarModalOpen(false);
                marForm.reset();
            },
        });
    };

    // 7. Resolve escalation form
    const resolveEscalationForm = useForm({
        resolution_note: '',
    });

    const submitResolveEscalation = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedEscalation) return;
        resolveEscalationForm.post(
            route('inpatient.nursing.escalations.resolve', { admission: admission.id, escalation: selectedEscalation.id }),
            {
                onSuccess: () => {
                    setResolveEscalationOpen(false);
                    setSelectedEscalation(null);
                    resolveEscalationForm.reset();
                },
            },
        );
    };

    const activeAllergies = allergies.filter((a) => a.status === 'active');
    const openEscalationsList = escalations.filter((e) => e.status === 'open');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Nursing — ${patientName}`} />

            <div className="flex flex-col gap-6 p-4">
                {/* Header Profile */}
                <div className="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-border/80 bg-gradient-to-r from-card via-card/90 to-card/50 p-5 shadow-sm">
                    <div className="space-y-1">
                        <div className="flex items-center gap-3">
                            <h1 className="text-2xl font-bold tracking-tight">{patientName}</h1>
                            {admission.clinical_status && (
                                <Badge variant={clinicalStatusVariant[admission.clinical_status as ClinicalStatus]}>
                                    {clinicalStatusLabel[admission.clinical_status as ClinicalStatus]}
                                </Badge>
                            )}
                            <Badge variant="outline" className="font-mono text-xs">
                                Bed: {[admission.ward_type, admission.room_type].filter(Boolean).join(' · ') || 'Ward Bed'}
                            </Badge>
                        </div>
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                            <span>Hospital #: <strong className="text-foreground">{patient?.patient_number}</strong></span>
                            <span>Admission #: <strong className="text-foreground font-mono">{admission.admission_number}</strong></span>
                            <span>Gender: <strong className="text-foreground capitalize">{patient?.gender || '—'}</strong></span>
                            <span>Blood: <strong className="text-foreground">{patient?.blood_group || '—'}</strong></span>
                            <span>Unit: <strong className="text-foreground">{admission.department?.name || 'General'}</strong></span>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {canRecordVitals && (
                            <Button size="sm" onClick={() => setVitalsOpen(true)} className="gap-1.5 shadow-sm">
                                <HeartPulse className="h-4 w-4" />
                                Record Observations
                            </Button>
                        )}
                        {canManageWoundCare && (
                            <Button size="sm" variant="outline" onClick={() => setWoundCareOpen(true)} className="gap-1.5">
                                <Bandage className="h-4 w-4" />
                                Wound Care
                            </Button>
                        )}
                        {canCreateHandover && (
                            <Button size="sm" variant="outline" onClick={() => setHandoverOpen(true)} className="gap-1.5">
                                <Users className="h-4 w-4" />
                                Shift Handover
                            </Button>
                        )}
                        {canEscalate && (
                            <Button size="sm" variant="outline" onClick={() => setEscalateOpen(true)} className="gap-1.5 text-amber-600 dark:text-amber-400">
                                <AlertTriangle className="h-4 w-4" />
                                Escalate
                            </Button>
                        )}
                        <Button size="sm" variant="secondary" asChild>
                            <Link href={route('inpatient.medications.worklist', admission.id)} className="gap-1.5">
                                <Pill className="h-4 w-4" />
                                MAR Worklist
                            </Link>
                        </Button>
                        {canAccessMaternity && (
                            <Button size="sm" variant="outline" className="border-pink-300 text-pink-700 hover:bg-pink-50 dark:hover:bg-pink-950/40" asChild>
                                <Link href={route('inpatient.maternity.show', admission.id)} className="gap-1.5">
                                    <Baby className="h-4 w-4 text-pink-600" />
                                    Maternity & Labour
                                </Link>
                            </Button>
                        )}
                        <Button size="sm" variant="outline" className="border-indigo-300 text-indigo-700 hover:bg-indigo-50 dark:hover:bg-indigo-950/40" asChild>
                            <Link href={route('inpatient.procedures.index', admission.id)} className="gap-1.5">
                                <Scissors className="h-4 w-4 text-indigo-600" />
                                Procedures & OT
                            </Link>
                        </Button>
                        <Button size="sm" variant="ghost" asChild>
                            <Link href={route('inpatient.clinical-profile', admission.id)} className="gap-1.5">
                                <Stethoscope className="h-4 w-4" />
                                Doctor Profile
                            </Link>
                        </Button>
                    </div>
                </div>

                {/* Safety Alerts Banner */}
                {activeAllergies.length > 0 ? (
                    <Alert variant="destructive" className="border-red-500/40 bg-red-500/10 text-red-900 dark:text-red-200">
                        <ShieldAlert className="h-5 w-5 text-red-600 dark:text-red-400" />
                        <div className="flex flex-1 items-center justify-between">
                            <div>
                                <AlertTitle className="font-semibold text-red-700 dark:text-red-300">
                                    Allergy & Safety Alerts Documented ({activeAllergies.length})
                                </AlertTitle>
                                <AlertDescription className="text-sm mt-0.5">
                                    {activeAllergies.map((a) => (
                                        <span key={a.id} className="mr-3 inline-flex items-center gap-1 font-medium">
                                            • {a.allergen} ({a.severity}) {a.reaction ? `— ${a.reaction}` : ''}
                                        </span>
                                    ))}
                                </AlertDescription>
                            </div>
                            {canManageAllergies && (
                                <Button size="sm" variant="outline" className="border-red-400 bg-background text-xs" onClick={() => setAllergyOpen(true)}>
                                    Add Allergy
                                </Button>
                            )}
                        </div>
                    </Alert>
                ) : (
                    <div className="flex items-center justify-between rounded-lg border border-emerald-500/30 bg-emerald-500/5 px-4 py-2.5 text-xs text-emerald-800 dark:text-emerald-300">
                        <div className="flex items-center gap-2">
                            <ShieldCheck className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                            <span>No documented medication or food allergies (NKDA). Always confirm with patient before dosing.</span>
                        </div>
                        {canManageAllergies && (
                            <Button size="sm" variant="ghost" className="h-7 text-xs text-emerald-700 dark:text-emerald-300" onClick={() => setAllergyOpen(true)}>
                                <Plus className="mr-1 h-3.5 w-3.5" />
                                Document Allergy
                            </Button>
                        )}
                    </div>
                )}

                {/* Open Clinician Escalations Banner */}
                {openEscalationsList.length > 0 && (
                    <Alert className="border-amber-500/40 bg-amber-500/10 text-amber-900 dark:text-amber-200">
                        <AlertCircle className="h-5 w-5 text-amber-600 dark:text-amber-400 animate-pulse" />
                        <AlertTitle className="font-semibold text-amber-800 dark:text-amber-300">
                            Observation Escalations Requiring Clinician Review ({openEscalationsList.length})
                        </AlertTitle>
                        <AlertDescription className="mt-1 space-y-1 text-sm">
                            {openEscalationsList.map((esc) => (
                                <div key={esc.id} className="flex items-center justify-between border-t border-amber-500/20 pt-1.5">
                                    <span>
                                        <strong>{esc.reason}</strong> · Raised at {new Date(esc.raised_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })} by {esc.raised_by?.UserName || 'System'}
                                    </span>
                                    <div className="flex items-center gap-2">
                                        {canAcknowledgeEscalation && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                className="h-7 text-xs"
                                                onClick={() => router.post(route('inpatient.nursing.escalations.acknowledge', { admission: admission.id, escalation: esc.id }))}
                                            >
                                                Acknowledge
                                            </Button>
                                        )}
                                        {canResolveEscalation && (
                                            <Button
                                                size="sm"
                                                className="h-7 text-xs"
                                                onClick={() => {
                                                    setSelectedEscalation(esc);
                                                    setResolveEscalationOpen(true);
                                                }}
                                            >
                                                Resolve
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </AlertDescription>
                    </Alert>
                )}

                {/* Main Tabs Workspace */}
                <Tabs defaultValue="observations" className="w-full">
                    <TabsList className="grid w-full grid-cols-6 lg:w-auto">
                        <TabsTrigger value="observations" className="gap-1.5 text-xs">
                            <Activity className="h-4 w-4" />
                            Observations ({vitals.length})
                        </TabsTrigger>
                        <TabsTrigger value="mar" className="gap-1.5 text-xs">
                            <Pill className="h-4 w-4" />
                            Treatment Sheet ({todayScheduledDoses.length})
                        </TabsTrigger>
                        <TabsTrigger value="wound_care" className="gap-1.5 text-xs">
                            <Bandage className="h-4 w-4" />
                            Wound Care ({woundCareEvents.length})
                        </TabsTrigger>
                        <TabsTrigger value="handovers" className="gap-1.5 text-xs">
                            <Users className="h-4 w-4" />
                            Shift Handover ({handovers.length})
                        </TabsTrigger>
                        <TabsTrigger value="notes" className="gap-1.5 text-xs">
                            <FileText className="h-4 w-4" />
                            Nursing Notes ({notes.length})
                        </TabsTrigger>
                        <TabsTrigger value="escalations" className="gap-1.5 text-xs">
                            <AlertTriangle className="h-4 w-4" />
                            Escalations ({escalations.length})
                        </TabsTrigger>
                    </TabsList>

                    {/* 1. Observations Tab */}
                    <TabsContent value="observations" className="space-y-4 pt-2">
                        <div className="flex items-center justify-between">
                            <div>
                                <h3 className="text-base font-semibold">Bedside Observations & Physiological Log</h3>
                                <p className="text-xs text-muted-foreground">Chronological record of measurements, AVPU neurological state, oxygen therapy, and fluid balance.</p>
                            </div>
                            {canRecordVitals && (
                                <Button size="sm" onClick={() => setVitalsOpen(true)} className="gap-1.5">
                                    <Plus className="h-4 w-4" />
                                    New Observation
                                </Button>
                            )}
                        </div>

                        {vitals.length === 0 ? (
                            <EmptyState icon={Activity} title="No observations logged" description="Record the initial bedside vital signs and nursing observations." />
                        ) : (
                            <div className="rounded-md border bg-card">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Time</TableHead>
                                            <TableHead>Temp (°C)</TableHead>
                                            <TableHead>HR (bpm)</TableHead>
                                            <TableHead>BP (mmHg)</TableHead>
                                            <TableHead>RR</TableHead>
                                            <TableHead>SpO₂</TableHead>
                                            <TableHead>Pain (0–10)</TableHead>
                                            <TableHead>AVPU</TableHead>
                                            <TableHead>Oxygen Support</TableHead>
                                            <TableHead>Intake/Output</TableHead>
                                            <TableHead>Nurse</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {vitals.map((v) => (
                                            <TableRow key={v.id} className="hover:bg-muted/40">
                                                <TableCell className="font-mono text-xs">
                                                    <div>{new Date(v.measured_at || v.recorded_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</div>
                                                    <div className="text-[10px] text-muted-foreground">{new Date(v.measured_at || v.recorded_at).toLocaleDateString()}</div>
                                                </TableCell>
                                                <TableCell>
                                                    <span className={v.temperature_c && (v.temperature_c >= 38 || v.temperature_c <= 35.5) ? 'font-bold text-destructive' : ''}>
                                                        {v.temperature_c ? `${v.temperature_c}°C` : '—'}
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    <span className={v.pulse_bpm && (v.pulse_bpm > 100 || v.pulse_bpm < 55) ? 'font-bold text-destructive' : ''}>
                                                        {v.pulse_bpm ?? '—'}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="font-medium">
                                                    {v.systolic_mmhg ? `${v.systolic_mmhg}/${v.diastolic_mmhg}` : '—'}
                                                </TableCell>
                                                <TableCell>{v.respiratory_rate_bpm ?? '—'}</TableCell>
                                                <TableCell>
                                                    <span className={v.oxygen_saturation_pct && v.oxygen_saturation_pct < 95 ? 'font-bold text-destructive' : ''}>
                                                        {v.oxygen_saturation_pct ? `${v.oxygen_saturation_pct}%` : '—'}
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    {v.pain_score !== null && v.pain_score !== undefined ? (
                                                        <Badge variant={v.pain_score >= 7 ? 'destructive' : v.pain_score >= 4 ? 'secondary' : 'outline'} className="text-[11px]">
                                                            {v.pain_score} / 10
                                                        </Badge>
                                                    ) : '—'}
                                                </TableCell>
                                                <TableCell className="capitalize text-xs">{v.consciousness_level || 'Alert'}</TableCell>
                                                <TableCell className="text-xs">
                                                    {v.oxygen_support ? v.oxygen_support.replace('_', ' ') : 'Room air'}
                                                    {v.oxygen_flow_lpm ? ` (${v.oxygen_flow_lpm} L/m)` : ''}
                                                </TableCell>
                                                <TableCell className="text-xs text-muted-foreground font-mono">
                                                    +{v.intake_ml || 0} / -{v.output_ml || 0} ml
                                                </TableCell>
                                                <TableCell className="text-xs text-muted-foreground">{v.recorded_by?.UserName || '—'}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </TabsContent>

                    {/* 2. Treatment Sheet / MAR Tab */}
                    <TabsContent value="mar" className="space-y-4 pt-2">
                        <div className="flex items-center justify-between">
                            <div>
                                <h3 className="text-base font-semibold">Today's Medication Administration Schedule</h3>
                                <p className="text-xs text-muted-foreground">Authorized active medication orders and bed-level administration execution sheet.</p>
                            </div>
                            <Button size="sm" variant="outline" asChild>
                                <Link href={route('inpatient.medications.worklist', admission.id)}>Full MAR History</Link>
                            </Button>
                        </div>

                        {todayScheduledDoses.length === 0 ? (
                            <EmptyState icon={Pill} title="No active scheduled doses today" description="Prescriptions written by the doctor appear here once activated." />
                        ) : (
                            <div className="rounded-md border bg-card">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Time</TableHead>
                                            <TableHead>Medication</TableHead>
                                            <TableHead>Dose & Route</TableHead>
                                            <TableHead>Frequency</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Execution Time</TableHead>
                                            <TableHead className="text-right">Action</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {todayScheduledDoses.map((dose, idx) => (
                                            <TableRow key={idx} className={dose.is_overdue ? 'bg-destructive/5' : ''}>
                                                <TableCell className="font-mono font-bold text-sm">
                                                    {dose.scheduled_time}
                                                </TableCell>
                                                <TableCell className="font-medium text-sm">
                                                    {dose.medication_name}
                                                </TableCell>
                                                <TableCell className="text-sm">
                                                    {dose.dose} {dose.dose_unit} · <span className="capitalize text-muted-foreground">{dose.route}</span>
                                                </TableCell>
                                                <TableCell className="capitalize text-xs text-muted-foreground">
                                                    {dose.frequency.replace(/_/g, ' ')}
                                                </TableCell>
                                                <TableCell>
                                                    {dose.status === 'administered' ? (
                                                        <Badge variant="default" className="bg-emerald-600 hover:bg-emerald-700 text-white gap-1 text-[11px]">
                                                            <CheckCircle2 className="h-3 w-3" /> Given
                                                        </Badge>
                                                    ) : dose.is_overdue ? (
                                                        <Badge variant="destructive" className="gap-1 text-[11px]">
                                                            <Clock className="h-3 w-3" /> Overdue
                                                        </Badge>
                                                    ) : dose.status === 'held' ? (
                                                        <Badge variant="secondary" className="text-amber-700 bg-amber-100 text-[11px]">Held</Badge>
                                                    ) : dose.status === 'refused' ? (
                                                        <Badge variant="secondary" className="text-red-700 bg-red-100 text-[11px]">Refused</Badge>
                                                    ) : (
                                                        <Badge variant="outline" className="text-[11px]">Scheduled</Badge>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-xs text-muted-foreground font-mono">
                                                    {dose.administered_at ? new Date(dose.administered_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—'}
                                                    {dose.administered_by ? ` (${dose.administered_by})` : ''}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {canAdministerMedication && dose.status === 'pending' ? (
                                                        <Button size="sm" className="h-7 text-xs" onClick={() => openMarModalForDose(dose)}>
                                                            Record Dose
                                                        </Button>
                                                    ) : (
                                                        <Button variant="ghost" size="sm" className="h-7 text-xs" asChild>
                                                            <Link href={route('inpatient.medications.worklist', admission.id)}>View</Link>
                                                        </Button>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </TabsContent>

                    {/* 3. Wound Care Tab */}
                    <TabsContent value="wound_care" className="space-y-4 pt-2">
                        <div className="flex items-center justify-between">
                            <div>
                                <h3 className="text-base font-semibold">Wound Assessment & Dressing Log</h3>
                                <p className="text-xs text-muted-foreground">Document repeat dressings, wound assessment, materials used, and patient tolerance without overwriting history.</p>
                            </div>
                            {canManageWoundCare && (
                                <Button size="sm" onClick={() => setWoundCareOpen(true)} className="gap-1.5">
                                    <Plus className="h-4 w-4" />
                                    Document Dressing
                                </Button>
                            )}
                        </div>

                        {woundCareEvents.length === 0 ? (
                            <EmptyState icon={Bandage} title="No wound care recorded" description="Document surgical incision or wound care performed during this admission." />
                        ) : (
                            <div className="grid gap-3">
                                {woundCareEvents.map((w) => (
                                    <Card key={w.id} className={w.concerning_findings ? 'border-destructive/60 bg-destructive/5' : ''}>
                                        <CardHeader className="pb-2">
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-2">
                                                    <CardTitle className="text-sm font-semibold">{w.site}</CardTitle>
                                                    {w.concerning_findings && (
                                                        <Badge variant="destructive" className="gap-1 text-[11px]">
                                                            <AlertTriangle className="h-3 w-3" /> Concerning Findings
                                                        </Badge>
                                                    )}
                                                </div>
                                                <span className="font-mono text-xs text-muted-foreground">
                                                    {new Date(w.performed_at).toLocaleString()} · by {w.recorded_by?.UserName || 'Nurse'}
                                                </span>
                                            </div>
                                            <CardDescription className="text-xs font-medium text-foreground">
                                                Indication: {w.indication}
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent className="space-y-2 text-xs">
                                            <div className="grid grid-cols-2 gap-4 rounded bg-muted/40 p-2.5">
                                                <div>
                                                    <span className="font-medium text-muted-foreground">Assessment:</span>
                                                    <p className="mt-0.5">{w.assessment}</p>
                                                </div>
                                                <div>
                                                    <span className="font-medium text-muted-foreground">Dressing & Materials:</span>
                                                    <p className="mt-0.5">{w.dressing_performed || 'Standard dressing'} · {w.materials_used || 'Sterile gauze'}</p>
                                                </div>
                                            </div>
                                            {w.complications && (
                                                <p className="text-destructive font-medium">Complications: {w.complications}</p>
                                            )}
                                            {w.next_dressing_due_at && (
                                                <div className="text-muted-foreground">
                                                    Next dressing due: <strong>{new Date(w.next_dressing_due_at).toLocaleDateString()}</strong> · {w.follow_up_instructions}
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                ))}
                            </div>
                        )}
                    </TabsContent>

                    {/* 4. Shift Handover Tab */}
                    <TabsContent value="handovers" className="space-y-4 pt-2">
                        <div className="flex items-center justify-between">
                            <div>
                                <h3 className="text-base font-semibold">Nursing Shift Handovers</h3>
                                <p className="text-xs text-muted-foreground">Audited bedside handover summaries and pending task transfer between shifts.</p>
                            </div>
                            {canCreateHandover && (
                                <Button size="sm" onClick={() => setHandoverOpen(true)} className="gap-1.5">
                                    <Plus className="h-4 w-4" />
                                    Record Handover
                                </Button>
                            )}
                        </div>

                        {handovers.length === 0 ? (
                            <EmptyState icon={Users} title="No shift handovers logged" description="Document morning, afternoon, or night shift handovers." />
                        ) : (
                            <div className="grid gap-3">
                                {handovers.map((h) => (
                                    <Card key={h.id}>
                                        <CardHeader className="pb-2">
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-2">
                                                    <Badge variant="secondary" className="capitalize text-xs font-semibold">
                                                        {h.shift} Shift
                                                    </Badge>
                                                    <span className="text-xs text-muted-foreground font-mono">
                                                        {new Date(h.handover_at).toLocaleString()}
                                                    </span>
                                                </div>
                                                <span className="text-xs text-muted-foreground">
                                                    Handed over by: <strong>{h.recorded_by?.UserName || 'Nurse'}</strong>
                                                </span>
                                            </div>
                                        </CardHeader>
                                        <CardContent className="space-y-2 text-xs">
                                            <div className="rounded bg-muted/30 p-2.5">
                                                <span className="font-semibold text-muted-foreground">Situation & Condition Summary:</span>
                                                <p className="mt-1 whitespace-pre-wrap">{h.situation_summary}</p>
                                            </div>
                                            {h.pending_tasks && (
                                                <div className="rounded bg-amber-500/5 border border-amber-500/20 p-2 text-amber-900 dark:text-amber-200">
                                                    <strong>Pending Tasks for Next Shift:</strong>
                                                    <p className="mt-0.5 whitespace-pre-wrap">{h.pending_tasks}</p>
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                ))}
                            </div>
                        )}
                    </TabsContent>

                    {/* 5. Nursing Notes Tab */}
                    <TabsContent value="notes" className="space-y-4 pt-2">
                        <div className="flex items-center justify-between">
                            <div>
                                <h3 className="text-base font-semibold">Nursing Notes & Clinical Progress</h3>
                                <p className="text-xs text-muted-foreground">Time-stamped nursing observations, patient care response, and communications.</p>
                            </div>
                        </div>

                        {notes.length === 0 ? (
                            <EmptyState icon={FileText} title="No clinical notes" description="All notes recorded under this admission's encounters appear here." />
                        ) : (
                            <div className="space-y-3">
                                {notes.map((n) => (
                                    <Card key={n.id}>
                                        <CardHeader className="pb-2">
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-2">
                                                    <Badge variant="outline" className="capitalize text-xs">
                                                        {n.note_type}
                                                    </Badge>
                                                    {n.note_category && (
                                                        <Badge variant="secondary" className="text-[11px] capitalize">
                                                            {n.note_category.replace(/_/g, ' ')}
                                                        </Badge>
                                                    )}
                                                    <Badge variant={n.status === 'signed' ? 'default' : 'secondary'} className="text-[10px]">
                                                        {n.status}
                                                    </Badge>
                                                </div>
                                                <span className="text-xs text-muted-foreground font-mono">
                                                    {new Date(n.created_at).toLocaleString()} by {n.author?.UserName || 'Clinician'}
                                                </span>
                                            </div>
                                        </CardHeader>
                                        <CardContent>
                                            <p className="text-xs whitespace-pre-wrap leading-relaxed">{n.note_content}</p>
                                        </CardContent>
                                    </Card>
                                ))}
                            </div>
                        )}
                    </TabsContent>

                    {/* 6. Escalations Tab */}
                    <TabsContent value="escalations" className="space-y-4 pt-2">
                        <div className="flex items-center justify-between">
                            <div>
                                <h3 className="text-base font-semibold">Observation Escalation History</h3>
                                <p className="text-xs text-muted-foreground">Recorded communication of concerning findings to responsible clinicians.</p>
                            </div>
                            {canEscalate && (
                                <Button size="sm" onClick={() => setEscalateOpen(true)} className="gap-1.5 text-amber-600 dark:text-amber-400">
                                    <Plus className="h-4 w-4" />
                                    New Escalation
                                </Button>
                            )}
                        </div>

                        {escalations.length === 0 ? (
                            <EmptyState icon={AlertTriangle} title="No escalations raised" description="Observations have remained within normal clinical thresholds." />
                        ) : (
                            <div className="space-y-3">
                                {escalations.map((e) => (
                                    <Card key={e.id} className={e.status === 'open' ? 'border-amber-500/60 bg-amber-500/5' : ''}>
                                        <CardHeader className="pb-2">
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-2">
                                                    <Badge variant={e.status === 'open' ? 'destructive' : e.status === 'acknowledged' ? 'secondary' : 'default'} className="text-xs uppercase">
                                                        {e.status}
                                                    </Badge>
                                                    <span className="font-semibold text-sm">{e.reason}</span>
                                                </div>
                                                <span className="text-xs text-muted-foreground font-mono">
                                                    {new Date(e.raised_at).toLocaleString()} · Source: <span className="uppercase">{e.source}</span>
                                                </span>
                                            </div>
                                        </CardHeader>
                                        <CardContent className="space-y-2 text-xs">
                                            <div className="text-muted-foreground">
                                                Raised by: <strong>{e.raised_by?.UserName || 'System'}</strong> · Clinician notified: <strong>{e.notified_practitioner?.staff ? `Dr. ${e.notified_practitioner.staff.first_name} ${e.notified_practitioner.staff.last_name}` : 'Attending Team'}</strong>
                                            </div>
                                            {e.resolution_note && (
                                                <div className="rounded bg-emerald-500/10 border border-emerald-500/20 p-2 text-emerald-900 dark:text-emerald-200">
                                                    <strong>Resolution Documentation:</strong>
                                                    <p className="mt-0.5">{e.resolution_note}</p>
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                ))}
                            </div>
                        )}
                    </TabsContent>
                </Tabs>
            </div>

            {/* Modal 1: Record Observations */}
            <Dialog open={vitalsOpen} onOpenChange={setVitalsOpen}>
                <DialogContent className="max-w-xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Record Bedside Observations & Vitals</DialogTitle>
                        <DialogDescription>Time-stamped objective physiological measurements and nursing assessments.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitVitals} className="space-y-4">
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label htmlFor="measured_at">Time of Measurement</Label>
                                <Input
                                    id="measured_at"
                                    type="datetime-local"
                                    value={vitalsForm.data.measured_at}
                                    onChange={(e) => vitalsForm.setData('measured_at', e.target.value)}
                                    required
                                />
                            </div>
                            <div>
                                <Label htmlFor="pain_score">Pain Score (0–10)</Label>
                                <Input
                                    id="pain_score"
                                    type="number"
                                    min="0"
                                    max="10"
                                    placeholder="0 (none) to 10 (severe)"
                                    value={vitalsForm.data.pain_score}
                                    onChange={(e) => vitalsForm.setData('pain_score', e.target.value)}
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-3 gap-3">
                            <div>
                                <Label htmlFor="temperature_c">Temp (°C)</Label>
                                <Input
                                    id="temperature_c"
                                    type="number"
                                    step="0.1"
                                    placeholder="36.5"
                                    value={vitalsForm.data.temperature_c}
                                    onChange={(e) => vitalsForm.setData('temperature_c', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="pulse_bpm">Pulse / HR (bpm)</Label>
                                <Input
                                    id="pulse_bpm"
                                    type="number"
                                    placeholder="75"
                                    value={vitalsForm.data.pulse_bpm}
                                    onChange={(e) => vitalsForm.setData('pulse_bpm', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="respiratory_rate_bpm">Resp Rate (bpm)</Label>
                                <Input
                                    id="respiratory_rate_bpm"
                                    type="number"
                                    placeholder="16"
                                    value={vitalsForm.data.respiratory_rate_bpm}
                                    onChange={(e) => vitalsForm.setData('respiratory_rate_bpm', e.target.value)}
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-3 gap-3">
                            <div>
                                <Label htmlFor="systolic_mmhg">Systolic BP</Label>
                                <Input
                                    id="systolic_mmhg"
                                    type="number"
                                    placeholder="120"
                                    value={vitalsForm.data.systolic_mmhg}
                                    onChange={(e) => vitalsForm.setData('systolic_mmhg', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="diastolic_mmhg">Diastolic BP</Label>
                                <Input
                                    id="diastolic_mmhg"
                                    type="number"
                                    placeholder="80"
                                    value={vitalsForm.data.diastolic_mmhg}
                                    onChange={(e) => vitalsForm.setData('diastolic_mmhg', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="oxygen_saturation_pct">SpO₂ (%)</Label>
                                <Input
                                    id="oxygen_saturation_pct"
                                    type="number"
                                    placeholder="98"
                                    value={vitalsForm.data.oxygen_saturation_pct}
                                    onChange={(e) => vitalsForm.setData('oxygen_saturation_pct', e.target.value)}
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label htmlFor="consciousness_level">Consciousness (AVPU)</Label>
                                <Select
                                    value={vitalsForm.data.consciousness_level}
                                    onValueChange={(val) => vitalsForm.setData('consciousness_level', val)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select AVPU" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="alert">Alert (A)</SelectItem>
                                        <SelectItem value="voice">Responds to Voice (V)</SelectItem>
                                        <SelectItem value="pain">Responds to Pain (P)</SelectItem>
                                        <SelectItem value="unresponsive">Unresponsive (U)</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label htmlFor="oxygen_support">Oxygen Support</Label>
                                <Select
                                    value={vitalsForm.data.oxygen_support}
                                    onValueChange={(val) => vitalsForm.setData('oxygen_support', val)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select oxygen support" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="room_air">Room Air</SelectItem>
                                        <SelectItem value="nasal_cannula">Nasal Cannula</SelectItem>
                                        <SelectItem value="face_mask">Face Mask</SelectItem>
                                        <SelectItem value="non_rebreather">Non-Rebreather</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label htmlFor="intake_ml">Intake (ml)</Label>
                                <Input
                                    id="intake_ml"
                                    type="number"
                                    placeholder="e.g. 500"
                                    value={vitalsForm.data.intake_ml}
                                    onChange={(e) => vitalsForm.setData('intake_ml', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="output_ml">Output (ml)</Label>
                                <Input
                                    id="output_ml"
                                    type="number"
                                    placeholder="e.g. 400"
                                    value={vitalsForm.data.output_ml}
                                    onChange={(e) => vitalsForm.setData('output_ml', e.target.value)}
                                />
                            </div>
                        </div>

                        <div>
                            <Label htmlFor="observation_notes">Observation Notes</Label>
                            <Textarea
                                id="observation_notes"
                                placeholder="Patient tolerance, respiratory effort, concerning symptoms..."
                                value={vitalsForm.data.observation_notes}
                                onChange={(e) => vitalsForm.setData('observation_notes', e.target.value)}
                            />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setVitalsOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={vitalsForm.processing}>Save Observation</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Modal 2: Wound Care */}
            <Dialog open={woundCareOpen} onOpenChange={setWoundCareOpen}>
                <DialogContent className="max-w-xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Document Wound Care & Dressing</DialogTitle>
                        <DialogDescription>Record clinical wound assessment, materials, and follow-up schedule.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitWoundCare} className="space-y-4">
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label htmlFor="site">Wound Site / Location</Label>
                                <Input
                                    id="site"
                                    placeholder="e.g. Right lower quadrant incision"
                                    value={woundForm.data.site}
                                    onChange={(e) => woundForm.setData('site', e.target.value)}
                                    required
                                />
                            </div>
                            <div>
                                <Label htmlFor="performed_at">Procedure Time</Label>
                                <Input
                                    id="performed_at"
                                    type="datetime-local"
                                    value={woundForm.data.performed_at}
                                    onChange={(e) => woundForm.setData('performed_at', e.target.value)}
                                    required
                                />
                            </div>
                        </div>

                        <div>
                            <Label htmlFor="indication">Indication</Label>
                            <Input
                                id="indication"
                                placeholder="e.g. Routine post-op dressing change, clean and dry"
                                value={woundForm.data.indication}
                                onChange={(e) => woundForm.setData('indication', e.target.value)}
                                required
                            />
                        </div>

                        <div>
                            <Label htmlFor="assessment">Wound Assessment & Appearance</Label>
                            <Textarea
                                id="assessment"
                                placeholder="Margins intact, no erythema, minimal serous exudate..."
                                value={woundForm.data.assessment}
                                onChange={(e) => woundForm.setData('assessment', e.target.value)}
                                required
                            />
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label htmlFor="dressing_performed">Dressing Performed</Label>
                                <Input
                                    id="dressing_performed"
                                    placeholder="e.g. Cleansed with normal saline, dry sterile dressing"
                                    value={woundForm.data.dressing_performed}
                                    onChange={(e) => woundForm.setData('dressing_performed', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="materials_used">Materials Used</Label>
                                <Input
                                    id="materials_used"
                                    placeholder="e.g. Saline, gauze swabs, opsite"
                                    value={woundForm.data.materials_used}
                                    onChange={(e) => woundForm.setData('materials_used', e.target.value)}
                                />
                            </div>
                        </div>

                        <div className="flex items-center space-x-2 rounded-md border border-destructive/20 bg-destructive/5 p-3">
                            <Checkbox
                                id="concerning_findings"
                                checked={woundForm.data.concerning_findings}
                                onCheckedChange={(checked) => woundForm.setData('concerning_findings', Boolean(checked))}
                            />
                            <Label htmlFor="concerning_findings" className="text-xs font-semibold text-destructive cursor-pointer">
                                Flag concerning findings (infection signs, purulent exudate, dehiscence)
                            </Label>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label htmlFor="next_dressing_due_at">Next Dressing Due</Label>
                                <Input
                                    id="next_dressing_due_at"
                                    type="datetime-local"
                                    value={woundForm.data.next_dressing_due_at}
                                    onChange={(e) => woundForm.setData('next_dressing_due_at', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="follow_up_instructions">Instructions</Label>
                                <Input
                                    id="follow_up_instructions"
                                    placeholder="Keep dry, monitor temperature"
                                    value={woundForm.data.follow_up_instructions}
                                    onChange={(e) => woundForm.setData('follow_up_instructions', e.target.value)}
                                />
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setWoundCareOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={woundForm.processing}>Save Dressing Record</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Modal 3: Shift Handover */}
            <Dialog open={handoverOpen} onOpenChange={setHandoverOpen}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Document Nursing Shift Handover</DialogTitle>
                        <DialogDescription>Snapshot patient condition, outstanding doses, and pending clinical tasks.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitHandover} className="space-y-4">
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label htmlFor="shift">Shift</Label>
                                <Select
                                    value={handoverForm.data.shift}
                                    onValueChange={(val) => handoverForm.setData('shift', val)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select shift" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="morning">Morning Shift</SelectItem>
                                        <SelectItem value="afternoon">Afternoon Shift</SelectItem>
                                        <SelectItem value="night">Night Shift</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label htmlFor="handover_at">Handover Time</Label>
                                <Input
                                    id="handover_at"
                                    type="datetime-local"
                                    value={handoverForm.data.handover_at}
                                    onChange={(e) => handoverForm.setData('handover_at', e.target.value)}
                                    required
                                />
                            </div>
                        </div>

                        <div>
                            <Label htmlFor="situation_summary">Situation & Clinical Status Summary</Label>
                            <Textarea
                                id="situation_summary"
                                placeholder="Patient status, pain management, IV line patency, vital stability..."
                                value={handoverForm.data.situation_summary}
                                onChange={(e) => handoverForm.setData('situation_summary', e.target.value)}
                                rows={4}
                                required
                            />
                        </div>

                        <div>
                            <Label htmlFor="pending_tasks">Pending Tasks & Tests for Next Shift</Label>
                            <Textarea
                                id="pending_tasks"
                                placeholder="Dressing due at 20:00, check lab results, doctor review requested..."
                                value={handoverForm.data.pending_tasks}
                                onChange={(e) => handoverForm.setData('pending_tasks', e.target.value)}
                                rows={3}
                            />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setHandoverOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={handoverForm.processing}>Save Shift Handover</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Modal 4: Manual Escalation */}
            <Dialog open={escalateOpen} onOpenChange={setEscalateOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Escalate to Clinician</DialogTitle>
                        <DialogDescription>Document communication of abnormal observations or clinical concerns to the responsible doctor.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitEscalation} className="space-y-4">
                        <div>
                            <Label htmlFor="reason">Clinical Reason for Escalation</Label>
                            <Textarea
                                id="reason"
                                placeholder="Describe concerning observations, acute changes, or unanswered orders..."
                                value={escalateForm.data.reason}
                                onChange={(e) => escalateForm.setData('reason', e.target.value)}
                                rows={4}
                                required
                            />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setEscalateOpen(false)}>Cancel</Button>
                            <Button type="submit" variant="destructive" disabled={escalateForm.processing}>Raise Escalation</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Modal 5: Document Allergy */}
            <Dialog open={allergyOpen} onOpenChange={setAllergyOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Document Patient Allergy</DialogTitle>
                        <DialogDescription>Record substance, severity, and manifestation. Enforced before medication administration.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitAllergy} className="space-y-4">
                        <div>
                            <Label htmlFor="allergen">Allergen / Substance</Label>
                            <Input
                                id="allergen"
                                placeholder="e.g. Penicillin, Aspirin, Sulfa drugs..."
                                value={allergyForm.data.allergen}
                                onChange={(e) => allergyForm.setData('allergen', e.target.value)}
                                required
                            />
                        </div>

                        <div>
                            <Label htmlFor="severity">Severity</Label>
                            <Select
                                value={allergyForm.data.severity}
                                onValueChange={(val) => allergyForm.setData('severity', val)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select severity" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="mild">Mild (rash, pruritus)</SelectItem>
                                    <SelectItem value="moderate">Moderate (urticaria, bronchospasm)</SelectItem>
                                    <SelectItem value="severe">Severe / Anaphylaxis</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label htmlFor="reaction">Reaction Manifestation</Label>
                            <Input
                                id="reaction"
                                placeholder="e.g. Swelling, dyspnea, anaphylaxis..."
                                value={allergyForm.data.reaction}
                                onChange={(e) => allergyForm.setData('reaction', e.target.value)}
                            />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setAllergyOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={allergyForm.processing}>Save Allergy Record</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Modal 6: Record Medication Dose */}
            <Dialog open={marModalOpen} onOpenChange={setMarModalOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Record Medication Administration</DialogTitle>
                        <DialogDescription>Bedside execution record for scheduled or PRN medication dose.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitMar} className="space-y-4">
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label htmlFor="status">Administration Status</Label>
                                <Select
                                    value={marForm.data.status}
                                    onValueChange={(val) => marForm.setData('status', val)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Status" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="administered">Administered (Given)</SelectItem>
                                        <SelectItem value="delayed">Delayed Dose</SelectItem>
                                        <SelectItem value="held">Held (Clinical instruction)</SelectItem>
                                        <SelectItem value="refused">Refused by Patient</SelectItem>
                                        <SelectItem value="missed">Missed (e.g. off ward)</SelectItem>
                                        <SelectItem value="not_applicable">Not Applicable</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label htmlFor="administered_at">Actual Time</Label>
                                <Input
                                    id="administered_at"
                                    type="datetime-local"
                                    value={marForm.data.administered_at}
                                    onChange={(e) => marForm.setData('administered_at', e.target.value)}
                                    required
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label htmlFor="dose">Dose Given</Label>
                                <Input
                                    id="dose"
                                    value={marForm.data.dose}
                                    onChange={(e) => marForm.setData('dose', e.target.value)}
                                    required
                                />
                            </div>
                            <div>
                                <Label htmlFor="dose_unit">Unit</Label>
                                <Input
                                    id="dose_unit"
                                    value={marForm.data.dose_unit}
                                    onChange={(e) => marForm.setData('dose_unit', e.target.value)}
                                    required
                                />
                            </div>
                        </div>

                        {marForm.data.status !== 'administered' && (
                            <div>
                                <Label htmlFor="reason" className="text-destructive font-medium">
                                    Reason for {marForm.data.status} *
                                </Label>
                                <Input
                                    id="reason"
                                    placeholder="Clinical hold instruction, patient NPO, refused..."
                                    value={marForm.data.reason}
                                    onChange={(e) => marForm.setData('reason', e.target.value)}
                                    required
                                />
                            </div>
                        )}

                        {activeAllergies.length > 0 && (
                            <div className="rounded border border-amber-500/30 bg-amber-500/5 p-3 space-y-1.5">
                                <Label htmlFor="allergy_override" className="text-xs font-semibold text-amber-800 dark:text-amber-300">
                                    Allergy Override Reason (if matching allergen)
                                </Label>
                                <Input
                                    id="allergy_override"
                                    placeholder="e.g. Prescriber verified, non-cross-reacting cephalosporin"
                                    value={marForm.data.allergy_override_reason}
                                    onChange={(e) => marForm.setData('allergy_override_reason', e.target.value)}
                                />
                            </div>
                        )}

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setMarModalOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={marForm.processing}>Save MAR Dose</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Modal 7: Resolve Escalation */}
            <Dialog open={resolveEscalationOpen} onOpenChange={setResolveEscalationOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Resolve Observation Escalation</DialogTitle>
                        <DialogDescription>Document the clinician review, findings, and clinical resolution.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitResolveEscalation} className="space-y-4">
                        <div>
                            <Label htmlFor="resolution_note">Clinical Resolution Documentation</Label>
                            <Textarea
                                id="resolution_note"
                                placeholder="Patient examined, fluid bolus administered, repeat vitals ordered..."
                                value={resolveEscalationForm.data.resolution_note}
                                onChange={(e) => resolveEscalationForm.setData('resolution_note', e.target.value)}
                                rows={4}
                                required
                            />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setResolveEscalationOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={resolveEscalationForm.processing}>Mark as Resolved</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
