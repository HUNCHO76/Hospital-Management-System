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
import { type BreadcrumbItem } from '@/types';
import { type Admission } from '@/types/admission';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertCircle,
    AlertTriangle,
    Baby,
    Calendar,
    CheckCircle2,
    Clock,
    FileSpreadsheet,
    HeartPulse,
    HelpCircle,
    Info,
    Pill,
    Plus,
    Scissors,
    ShieldAlert,
    Stethoscope,
    User,
} from 'lucide-react';
import { useState } from 'react';

interface MaternityProfileData {
    id: number;
    admission_id: number;
    patient_id: number;
    gravida: number;
    para: number;
    living_children: number;
    abortions: number;
    last_menstrual_period: string | null;
    estimated_date_of_delivery: string | null;
    gestational_age_weeks: number | null;
    presentation: string;
    membrane_status: string;
    membrane_ruptured_at: string | null;
    blood_group: string | null;
    rhesus: string | null;
    hiv_status: string;
    vdrl_rpr_status: string;
    risk_factors: string[] | null;
    labour_stage: string;
    status: string;
    notes: string | null;
    recorded_by?: { id: number; UserName: string };
    created_at: string;
}

interface PartographReading {
    id: number;
    maternity_profile_id: number;
    recorded_at: string;
    fetal_heart_rate: number | null;
    amniotic_fluid: string | null;
    moulding: string | null;
    cervical_dilation_cm: number | null;
    descent_of_head: number | null;
    alert_line_dilation_cm: number | null;
    action_line_dilation_cm: number | null;
    action_line_crossed: boolean;
    contractions_per_10min: number | null;
    contraction_duration: string | null;
    oxytocin_drops_per_min: number | null;
    oxytocin_dose_units: string | null;
    drugs_iv_fluids: string | null;
    systolic_bp: number | null;
    diastolic_bp: number | null;
    pulse_bpm: number | null;
    temperature_c: number | null;
    urine_protein: string | null;
    urine_acetone: string | null;
    urine_volume_ml: number | null;
    notes: string | null;
    recorded_by?: { id: number; UserName: string };
}

interface DeliverySummary {
    id: number;
    maternity_profile_id: number;
    delivered_at: string;
    delivery_mode: string;
    indication: string | null;
    placenta_delivered_at: string | null;
    placenta_mode: string;
    placenta_complete: boolean;
    blood_loss_ml: number;
    postpartum_hemorrhage: boolean;
    perineal_status: string;
    suture_materials_used: string | null;
    oxytocic_given: string | null;
    baby_outcome: string;
    baby_gender: string;
    birth_weight_grams: number | null;
    birth_length_cm: number | null;
    head_circumference_cm: number | null;
    apgar_1min: number | null;
    apgar_5min: number | null;
    apgar_10min: number | null;
    resuscitation_required: boolean;
    resuscitation_notes: string | null;
    vitamin_k_given: boolean;
    eye_ointment_given: boolean;
    bcg_given: boolean;
    polio_0_given: boolean;
    notes: string | null;
    conducted_by?: { id: number; UserName: string };
    assisted_by?: { id: number; UserName: string };
    baby_patient?: { id: number; patient_number: string; first_name: string; last_name: string };
}

interface PractitionerOption {
    id: number;
    staff?: { id: number; first_name: string; last_name: string };
}

interface MaternityWorkspaceProps {
    admission: Admission;
    profile: MaternityProfileData | null;
    partographEntries: PartographReading[];
    deliveryRecord: DeliverySummary | null;
    practitioners: PractitionerOption[];
    constants: {
        amniotic_fluid_states: Record<string, string>;
        moulding_grades: Record<string, string>;
        contraction_durations: Record<string, string>;
        presentations: string[];
        membrane_statuses: string[];
        hiv_statuses: string[];
        labour_stages: string[];
        delivery_modes: Record<string, string>;
        perineal_statuses: Record<string, string>;
        baby_outcomes: Record<string, string>;
    };
}

const COMMON_RISK_FACTORS = [
    'Previous Caesarean Section',
    'Pre-eclampsia / Gestational Hypertension',
    'Gestational Diabetes',
    'Multiple Gestation',
    'Antepartum Hemorrhage',
    'Breech / Malpresentation',
    'Severe Anemia (Hb < 8 g/dl)',
    'Premature Rupture of Membranes (> 18h)',
    'Grand Multipara (Para >= 5)',
    'Elderly Primi (Age >= 35)',
];

export default function MaternityWorkspace({
    admission,
    profile,
    partographEntries,
    deliveryRecord,
    practitioners,
    constants,
}: MaternityWorkspaceProps) {
    const { flash } = usePage().props as { flash?: { success?: string; error?: string } };

    const [activeTab, setActiveTab] = useState<'partograph' | 'profile' | 'delivery'>('partograph');
    const [profileDialogOpen, setProfileDialogOpen] = useState(false);
    const [partographDialogOpen, setPartographDialogOpen] = useState(false);
    const [deliveryDialogOpen, setDeliveryDialogOpen] = useState(false);

    // Profile Form
    const profileForm = useForm({
        gravida: profile?.gravida ?? 1,
        para: profile?.para ?? 0,
        living_children: profile?.living_children ?? 0,
        abortions: profile?.abortions ?? 0,
        last_menstrual_period: profile?.last_menstrual_period ?? '',
        estimated_date_of_delivery: profile?.estimated_date_of_delivery ?? '',
        gestational_age_weeks: profile?.gestational_age_weeks ?? '',
        presentation: profile?.presentation ?? 'cephalic',
        membrane_status: profile?.membrane_status ?? 'intact',
        membrane_ruptured_at: profile?.membrane_ruptured_at ?? '',
        blood_group: profile?.blood_group ?? '',
        rhesus: profile?.rhesus ?? 'positive',
        hiv_status: profile?.hiv_status ?? 'unknown',
        vdrl_rpr_status: profile?.vdrl_rpr_status ?? 'non_reactive',
        risk_factors: profile?.risk_factors ?? ([] as string[]),
        labour_stage: profile?.labour_stage ?? 'latent_phase',
        notes: profile?.notes ?? '',
    });

    const submitProfile = (e: React.FormEvent) => {
        e.preventDefault();
        profileForm.post(route('inpatient.maternity.profile.store', admission.id), {
            preserveScroll: true,
            onSuccess: () => setProfileDialogOpen(false),
        });
    };

    // Partograph Form
    const partographForm = useForm({
        recorded_at: new Date().toISOString().slice(0, 16),
        fetal_heart_rate: '',
        amniotic_fluid: 'C',
        moulding: '0',
        cervical_dilation_cm: '',
        descent_of_head: '',
        contractions_per_10min: '',
        contraction_duration: 'moderate',
        oxytocin_drops_per_min: '',
        oxytocin_dose_units: '',
        drugs_iv_fluids: '',
        systolic_bp: '',
        diastolic_bp: '',
        pulse_bpm: '',
        temperature_c: '',
        urine_protein: 'nil',
        urine_acetone: 'nil',
        urine_volume_ml: '',
        notes: '',
    });

    const submitPartograph = (e: React.FormEvent) => {
        e.preventDefault();
        if (!profile) return;
        partographForm.post(route('inpatient.maternity.partograph.store', [admission.id, profile.id]), {
            preserveScroll: true,
            onSuccess: () => {
                partographForm.reset();
                setPartographDialogOpen(false);
            },
        });
    };

    // Delivery Form
    const deliveryForm = useForm({
        delivered_at: new Date().toISOString().slice(0, 16),
        delivery_mode: 'spontaneous_vaginal',
        indication: '',
        conducted_by: '',
        assisted_by: '',
        placenta_delivered_at: new Date().toISOString().slice(0, 16),
        placenta_mode: 'spontaneous',
        placenta_complete: true,
        blood_loss_ml: '250',
        postpartum_hemorrhage: false,
        perineal_status: 'intact',
        suture_materials_used: '',
        oxytocic_given: 'Oxytocin 10 IU IM',
        baby_outcome: 'live_birth',
        baby_gender: 'female',
        birth_weight_grams: '3200',
        birth_length_cm: '50.0',
        head_circumference_cm: '34.0',
        apgar_1min: '8',
        apgar_5min: '10',
        apgar_10min: '10',
        resuscitation_required: false,
        resuscitation_notes: '',
        vitamin_k_given: true,
        eye_ointment_given: true,
        bcg_given: false,
        polio_0_given: false,
        notes: '',
    });

    const submitDelivery = (e: React.FormEvent) => {
        e.preventDefault();
        if (!profile) return;
        deliveryForm.post(route('inpatient.maternity.delivery.store', [admission.id, profile.id]), {
            preserveScroll: true,
            onSuccess: () => setDeliveryDialogOpen(false),
        });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Inpatient Ward', href: '/inpatient' },
        { title: 'Nursing Station', href: '/inpatient/nursing' },
        { title: `Patient #${admission.patient?.patient_number ?? admission.patient_id}`, href: route('inpatient.nursing.workspace', admission.id) },
        { title: 'Maternity & Labour', href: '#' },
    ];

    const hasActionLineCrossed = partographEntries.some((e) => e.action_line_crossed);
    const hasAbnormalFhr = partographEntries.some(
        (e) => e.fetal_heart_rate !== null && (e.fetal_heart_rate < 110 || e.fetal_heart_rate > 160)
    );
    const hasMeconium = partographEntries.some((e) => e.amniotic_fluid === 'M');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Maternity Workspace — ${admission.patient?.first_name} ${admission.patient?.last_name}`} />

            <div className="flex flex-col gap-6 p-4">
                {/* Header Banner */}
                <div className="flex flex-col gap-4 rounded-xl border border-pink-200 bg-gradient-to-r from-pink-50 via-rose-50 to-white p-5 shadow-sm dark:border-pink-900/40 dark:from-pink-950/20 dark:via-rose-950/10 dark:to-neutral-900">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex items-start gap-3">
                            <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-pink-600 text-white shadow-md">
                                <Baby className="h-6 w-6" />
                            </div>
                            <div>
                                <div className="flex items-center gap-2">
                                    <h1 className="text-2xl font-bold tracking-tight text-neutral-900 dark:text-neutral-50">
                                        {admission.patient?.first_name} {admission.patient?.last_name}
                                    </h1>
                                    <Badge variant="outline" className="border-pink-400 bg-pink-100/60 text-pink-800 dark:bg-pink-950/40 dark:text-pink-300">
                                        Maternity & Labour
                                    </Badge>
                                    {profile?.labour_stage && (
                                        <Badge className="bg-pink-700 text-white">
                                            {profile.labour_stage.replace('_', ' ').toUpperCase()}
                                        </Badge>
                                    )}
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    Hospital No: <span className="font-medium text-foreground">{admission.patient?.patient_number}</span> • Ward:{' '}
                                    <span className="font-medium text-foreground">{admission.department?.name ?? 'Maternity'}</span> • Adm No:{' '}
                                    <span className="font-medium text-foreground">{admission.admission_number}</span>
                                </p>
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <Link href={route('inpatient.nursing.workspace', admission.id)}>
                                    <Activity className="mr-1.5 h-4 w-4 text-emerald-600" />
                                    Nursing Workspace
                                </Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={route('inpatient.medications.worklist', admission.id)}>
                                    <Pill className="mr-1.5 h-4 w-4 text-purple-600" />
                                    MAR Sheet
                                </Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={route('inpatient.procedures.index', admission.id)}>
                                    <Scissors className="mr-1.5 h-4 w-4 text-indigo-600" />
                                    Procedures & OT
                                </Link>
                            </Button>
                            {!profile ? (
                                <Button className="bg-pink-600 hover:bg-pink-700" onClick={() => setProfileDialogOpen(true)}>
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Open Maternity Profile
                                </Button>
                            ) : (
                                <Button variant="outline" className="border-pink-300 text-pink-700 hover:bg-pink-50 dark:hover:bg-pink-950/40" onClick={() => setProfileDialogOpen(true)}>
                                    Edit Obstetric Profile
                                </Button>
                            )}
                        </div>
                    </div>

                    {/* Fast Obstetric Vitals Strip */}
                    {profile && (
                        <div className="grid grid-cols-2 gap-3 border-t border-pink-200/60 pt-3 sm:grid-cols-4 lg:grid-cols-7 dark:border-pink-900/40">
                            <div className="rounded-lg bg-white/70 p-2 text-center shadow-xs dark:bg-neutral-900/60">
                                <span className="text-[11px] font-medium text-muted-foreground uppercase">Parity</span>
                                <div className="text-base font-bold text-neutral-800 dark:text-neutral-100">
                                    G{profile.gravida} P{profile.para}+{profile.abortions}
                                </div>
                            </div>
                            <div className="rounded-lg bg-white/70 p-2 text-center shadow-xs dark:bg-neutral-900/60">
                                <span className="text-[11px] font-medium text-muted-foreground uppercase">Gestation (GA)</span>
                                <div className="text-base font-bold text-neutral-800 dark:text-neutral-100">
                                    {profile.gestational_age_weeks ? `${profile.gestational_age_weeks} wks` : 'Not set'}
                                </div>
                            </div>
                            <div className="rounded-lg bg-white/70 p-2 text-center shadow-xs dark:bg-neutral-900/60">
                                <span className="text-[11px] font-medium text-muted-foreground uppercase">EDD</span>
                                <div className="text-sm font-semibold text-neutral-800 dark:text-neutral-100">
                                    {profile.estimated_date_of_delivery ? new Date(profile.estimated_date_of_delivery).toLocaleDateString() : 'N/A'}
                                </div>
                            </div>
                            <div className="rounded-lg bg-white/70 p-2 text-center shadow-xs dark:bg-neutral-900/60">
                                <span className="text-[11px] font-medium text-muted-foreground uppercase">Presentation</span>
                                <div className="text-sm font-semibold capitalize text-neutral-800 dark:text-neutral-100">
                                    {profile.presentation}
                                </div>
                            </div>
                            <div className="rounded-lg bg-white/70 p-2 text-center shadow-xs dark:bg-neutral-900/60">
                                <span className="text-[11px] font-medium text-muted-foreground uppercase">Membranes</span>
                                <div className="text-sm font-semibold capitalize text-neutral-800 dark:text-neutral-100">
                                    {profile.membrane_status.replace('_', ' ')}
                                </div>
                            </div>
                            <div className="rounded-lg bg-white/70 p-2 text-center shadow-xs dark:bg-neutral-900/60">
                                <span className="text-[11px] font-medium text-muted-foreground uppercase">Blood / Rhesus</span>
                                <div className="text-sm font-semibold text-neutral-800 dark:text-neutral-100">
                                    {profile.blood_group ? `${profile.blood_group} ${profile.rhesus}` : 'Unknown'}
                                </div>
                            </div>
                            <div className="rounded-lg bg-white/70 p-2 text-center shadow-xs dark:bg-neutral-900/60">
                                <span className="text-[11px] font-medium text-muted-foreground uppercase">HIV / VDRL</span>
                                <div className="text-sm font-semibold capitalize text-neutral-800 dark:text-neutral-100">
                                    {profile.hiv_status.includes('positive') ? (
                                        <span className="font-bold text-amber-600">HIV Pos</span>
                                    ) : (
                                        <span className="text-emerald-600">HIV Neg</span>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}
                </div>

                {/* Critical Obstetric Alerts */}
                {hasActionLineCrossed && (
                    <Alert variant="destructive" className="border-red-600 bg-red-50 text-red-900 dark:bg-red-950/40 dark:text-red-200">
                        <ShieldAlert className="h-5 w-5 text-red-600" />
                        <AlertTitle className="text-base font-bold">CRITICAL: WHO Partograph Action Line Crossed</AlertTitle>
                        <AlertDescription className="text-sm">
                            Cervical dilation has crossed or fallen to the right of the 4-hour action line. This indicates obstructed or delayed active labour progress. An immediate obstetric consultation is mandated for augmentation or surgical intervention.
                        </AlertDescription>
                    </Alert>
                )}

                {(hasAbnormalFhr || hasMeconium) && (
                    <Alert className="border-amber-500 bg-amber-50 text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                        <AlertTriangle className="h-5 w-5 text-amber-600" />
                        <AlertTitle className="text-sm font-bold">Fetal Wellbeing Alert</AlertTitle>
                        <AlertDescription className="text-xs">
                            {hasAbnormalFhr && '• Fetal heart rate is outside normal baseline bounds (110 - 160 bpm). '}
                            {hasMeconium && '• Meconium-stained liquor detected. Assess for fetal distress, prepare neonatal resuscitation.'}
                        </AlertDescription>
                    </Alert>
                )}

                {/* Tabs */}
                <Tabs value={activeTab} onValueChange={(v) => setActiveTab(v as any)} className="w-full">
                    <TabsList className="grid w-full grid-cols-3 max-w-md">
                        <TabsTrigger value="partograph" className="flex items-center gap-1.5">
                            <FileSpreadsheet className="h-4 w-4" />
                            Partograph ({partographEntries.length})
                        </TabsTrigger>
                        <TabsTrigger value="profile" className="flex items-center gap-1.5">
                            <Info className="h-4 w-4" />
                            Obstetric Context
                        </TabsTrigger>
                        <TabsTrigger value="delivery" className="flex items-center gap-1.5">
                            <Baby className="h-4 w-4" />
                            Delivery Summary
                        </TabsTrigger>
                    </TabsList>

                    {/* TAB 1: WHO PARTOGRAPH */}
                    <TabsContent value="partograph" className="mt-4 flex flex-col gap-6">
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <h2 className="text-lg font-bold">WHO-Aligned Partograph Observations</h2>
                                <p className="text-xs text-muted-foreground">
                                    Tracking cervical dilation, descent, fetal heart rate, contractions, and maternal vitals in active labour.
                                </p>
                            </div>
                            <Button
                                className="bg-pink-600 hover:bg-pink-700"
                                disabled={!profile || profile.status === 'completed'}
                                onClick={() => setPartographDialogOpen(true)}
                            >
                                <Plus className="mr-1.5 h-4 w-4" />
                                Record Partograph Reading
                            </Button>
                        </div>

                        {!profile ? (
                            <EmptyState
                                icon={Baby}
                                title="No Maternity Profile"
                                description="Open a maternity profile first before recording partograph readings."
                                action={{
                                    label: 'Open Maternity Profile',
                                    onClick: () => setProfileDialogOpen(true),
                                }}
                            />
                        ) : partographEntries.length === 0 ? (
                            <EmptyState
                                icon={FileSpreadsheet}
                                title="No Partograph Readings Yet"
                                description="Document the first partograph reading when the patient enters the active phase of labour (cervical dilation >= 4 cm)."
                                action={{
                                    label: 'Record First Reading',
                                    onClick: () => setPartographDialogOpen(true),
                                }}
                            />
                        ) : (
                            <div className="flex flex-col gap-4">
                                <Card>
                                    <CardHeader className="pb-3">
                                        <CardTitle className="text-base flex items-center justify-between">
                                            <span>Labour Progress Table</span>
                                            <span className="text-xs font-normal text-muted-foreground">
                                                WHO Standards: Alert line = 1 cm/hr from 4cm; Action line = Alert + 4 hrs
                                            </span>
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="p-0">
                                        <div className="overflow-x-auto">
                                            <Table>
                                                <TableHeader className="bg-muted/50 text-xs">
                                                    <TableRow>
                                                        <TableHead>Time</TableHead>
                                                        <TableHead>FHR (bpm)</TableHead>
                                                        <TableHead>Liquor</TableHead>
                                                        <TableHead>Moulding</TableHead>
                                                        <TableHead>Dilation (cm)</TableHead>
                                                        <TableHead>Descent (/5)</TableHead>
                                                        <TableHead>Alert Line</TableHead>
                                                        <TableHead>Action Line</TableHead>
                                                        <TableHead>Contractions</TableHead>
                                                        <TableHead>Maternal BP / Pulse</TableHead>
                                                        <TableHead>Oxytocin / Fluids</TableHead>
                                                        <TableHead>Recorded By</TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody className="text-xs">
                                                    {partographEntries.map((entry) => {
                                                        const isFhrAbnormal = entry.fetal_heart_rate !== null && (entry.fetal_heart_rate < 110 || entry.fetal_heart_rate > 160);
                                                        const isMeconium = entry.amniotic_fluid === 'M';
                                                        return (
                                                            <TableRow
                                                                key={entry.id}
                                                                className={entry.action_line_crossed ? 'bg-red-50/70 dark:bg-red-950/20' : undefined}
                                                            >
                                                                <TableCell className="font-medium whitespace-nowrap">
                                                                    {new Date(entry.recorded_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                                                    <div className="text-[10px] text-muted-foreground">
                                                                        {new Date(entry.recorded_at).toLocaleDateString([], { month: 'short', day: 'numeric' })}
                                                                    </div>
                                                                </TableCell>
                                                                <TableCell>
                                                                    {entry.fetal_heart_rate ? (
                                                                        <Badge variant={isFhrAbnormal ? 'destructive' : 'secondary'} className="font-mono">
                                                                            {entry.fetal_heart_rate}
                                                                        </Badge>
                                                                    ) : (
                                                                        '—'
                                                                    )}
                                                                </TableCell>
                                                                <TableCell>
                                                                    <span
                                                                        className={`font-semibold ${
                                                                            isMeconium ? 'text-amber-600 font-bold' : ''
                                                                        }`}
                                                                        title={entry.amniotic_fluid ? constants.amniotic_fluid_states[entry.amniotic_fluid] : ''}
                                                                    >
                                                                        {entry.amniotic_fluid ?? '—'}
                                                                    </span>
                                                                </TableCell>
                                                                <TableCell>{entry.moulding ?? '0'}</TableCell>
                                                                <TableCell>
                                                                    {entry.cervical_dilation_cm !== null ? (
                                                                        <span className="font-bold text-sm text-pink-700 dark:text-pink-400">
                                                                            {entry.cervical_dilation_cm} cm
                                                                        </span>
                                                                    ) : (
                                                                        '—'
                                                                    )}
                                                                </TableCell>
                                                                <TableCell>{entry.descent_of_head !== null ? `${entry.descent_of_head}/5` : '—'}</TableCell>
                                                                <TableCell className="text-muted-foreground">
                                                                    {entry.alert_line_dilation_cm !== null ? `${entry.alert_line_dilation_cm} cm` : '—'}
                                                                </TableCell>
                                                                <TableCell>
                                                                    {entry.action_line_dilation_cm !== null ? (
                                                                        <span className={entry.action_line_crossed ? 'font-bold text-red-600' : 'text-muted-foreground'}>
                                                                            {entry.action_line_dilation_cm} cm
                                                                            {entry.action_line_crossed && ' ⚠️'}
                                                                        </span>
                                                                    ) : (
                                                                        '—'
                                                                    )}
                                                                </TableCell>
                                                                <TableCell>
                                                                    {entry.contractions_per_10min ? (
                                                                        <span>
                                                                            {entry.contractions_per_10min}/10m{' '}
                                                                            <span className="text-[10px] text-muted-foreground">({entry.contraction_duration})</span>
                                                                        </span>
                                                                    ) : (
                                                                        '—'
                                                                    )}
                                                                </TableCell>
                                                                <TableCell className="whitespace-nowrap">
                                                                    {entry.systolic_bp && entry.diastolic_bp ? `${entry.systolic_bp}/${entry.diastolic_bp}` : '—'}
                                                                    {entry.pulse_bpm && <span className="text-[10px] text-muted-foreground"> • {entry.pulse_bpm}bpm</span>}
                                                                </TableCell>
                                                                <TableCell className="max-w-[150px] truncate">
                                                                    {entry.oxytocin_drops_per_min ? `${entry.oxytocin_drops_per_min} dpm` : ''}
                                                                    {entry.drugs_iv_fluids ? ` ${entry.drugs_iv_fluids}` : '—'}
                                                                </TableCell>
                                                                <TableCell className="text-muted-foreground whitespace-nowrap">
                                                                    {entry.recorded_by?.UserName ?? 'Staff'}
                                                                </TableCell>
                                                            </TableRow>
                                                        );
                                                    })}
                                                </TableBody>
                                            </Table>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>
                        )}
                    </TabsContent>

                    {/* TAB 2: OBSTETRIC PROFILE & RISKS */}
                    <TabsContent value="profile" className="mt-4 flex flex-col gap-6">
                        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Antenatal & Obstetric Summary</CardTitle>
                                    <CardDescription>Parity, dates, and baseline obstetric parameters.</CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-3 text-sm">
                                    <div className="flex justify-between border-b pb-2">
                                        <span className="text-muted-foreground">Gravida / Para</span>
                                        <span className="font-semibold">
                                            Gravida {profile?.gravida ?? 1}, Para {profile?.para ?? 0} (Living: {profile?.living_children ?? 0}, Abortions:{' '}
                                            {profile?.abortions ?? 0})
                                        </span>
                                    </div>
                                    <div className="flex justify-between border-b pb-2">
                                        <span className="text-muted-foreground">Last Menstrual Period (LMP)</span>
                                        <span className="font-medium">
                                            {profile?.last_menstrual_period ? new Date(profile.last_menstrual_period).toLocaleDateString() : 'Not recorded'}
                                        </span>
                                    </div>
                                    <div className="flex justify-between border-b pb-2">
                                        <span className="text-muted-foreground">Expected Date of Delivery (EDD)</span>
                                        <span className="font-medium">
                                            {profile?.estimated_date_of_delivery ? new Date(profile.estimated_date_of_delivery).toLocaleDateString() : 'Not calculated'}
                                        </span>
                                    </div>
                                    <div className="flex justify-between border-b pb-2">
                                        <span className="text-muted-foreground">Gestational Age</span>
                                        <span className="font-medium">{profile?.gestational_age_weeks ? `${profile.gestational_age_weeks} weeks` : 'Unknown'}</span>
                                    </div>
                                    <div className="flex justify-between border-b pb-2">
                                        <span className="text-muted-foreground">Presentation</span>
                                        <span className="font-medium capitalize">{profile?.presentation ?? 'cephalic'}</span>
                                    </div>
                                    <div className="flex justify-between border-b pb-2">
                                        <span className="text-muted-foreground">Membrane Status</span>
                                        <span className="font-medium capitalize">
                                            {profile?.membrane_status.replace('_', ' ')}
                                            {profile?.membrane_ruptured_at && ` (Ruptured: ${new Date(profile.membrane_ruptured_at).toLocaleTimeString()})`}
                                        </span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Labour Status</span>
                                        <Badge variant="outline" className="capitalize">
                                            {profile?.labour_stage?.replace('_', ' ') ?? 'latent_phase'}
                                        </Badge>
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Documented Risk Factors & Serology</CardTitle>
                                    <CardDescription>Maternal risk assessment and infection screening.</CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-4">
                                    <div className="grid grid-cols-2 gap-2 text-xs">
                                        <div className="rounded border p-2">
                                            <span className="text-muted-foreground">Blood Group & Rh:</span>
                                            <div className="font-semibold text-sm">
                                                {profile?.blood_group ? `${profile.blood_group} ${profile.rhesus}` : 'Not tested'}
                                            </div>
                                        </div>
                                        <div className="rounded border p-2">
                                            <span className="text-muted-foreground">HIV Status:</span>
                                            <div className="font-semibold text-sm capitalize">{profile?.hiv_status ?? 'Unknown'}</div>
                                        </div>
                                        <div className="rounded border p-2">
                                            <span className="text-muted-foreground">VDRL / RPR:</span>
                                            <div className="font-semibold text-sm capitalize">{profile?.vdrl_rpr_status ?? 'Non Reactive'}</div>
                                        </div>
                                        <div className="rounded border p-2">
                                            <span className="text-muted-foreground">Recorded By:</span>
                                            <div className="font-semibold text-sm">{profile?.recorded_by?.UserName ?? 'Staff'}</div>
                                        </div>
                                    </div>

                                    <div>
                                        <h3 className="text-xs font-semibold uppercase text-muted-foreground mb-2">Active Obstetric Risk Factors</h3>
                                        {profile?.risk_factors && profile.risk_factors.length > 0 ? (
                                            <div className="flex flex-wrap gap-1.5">
                                                {profile.risk_factors.map((risk, idx) => (
                                                    <Badge key={idx} variant="destructive" className="text-xs">
                                                        {risk}
                                                    </Badge>
                                                ))}
                                            </div>
                                        ) : (
                                            <p className="text-xs text-muted-foreground">No specific high-risk obstetric factors documented.</p>
                                        )}
                                    </div>

                                    {profile?.notes && (
                                        <div className="rounded bg-muted/40 p-3 text-xs">
                                            <span className="font-semibold">Notes: </span>
                                            {profile.notes}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    {/* TAB 3: DELIVERY SUMMARY */}
                    <TabsContent value="delivery" className="mt-4 flex flex-col gap-6">
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <h2 className="text-lg font-bold">Labour Delivery Summary & Birth Outcome</h2>
                                <p className="text-xs text-muted-foreground">
                                    Document maternal delivery details, placenta delivery, blood loss, and newborn APGAR scoring.
                                </p>
                            </div>
                            {!deliveryRecord && profile && (
                                <Button className="bg-pink-600 hover:bg-pink-700" onClick={() => setDeliveryDialogOpen(true)}>
                                    <Baby className="mr-1.5 h-4 w-4" />
                                    Document Delivery
                                </Button>
                            )}
                        </div>

                        {!deliveryRecord ? (
                            <EmptyState
                                icon={Baby}
                                title="No Delivery Documented Yet"
                                description="Once the baby has been delivered, record the maternal and neonatal outcomes here."
                                action={
                                    profile
                                        ? {
                                              label: 'Document Delivery',
                                              onClick: () => setDeliveryDialogOpen(true),
                                          }
                                        : undefined
                                }
                            />
                        ) : (
                            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                                {/* Maternal Outcome Card */}
                                <Card>
                                    <CardHeader className="bg-pink-50/50 pb-3 dark:bg-pink-950/20">
                                        <CardTitle className="text-base flex items-center justify-between">
                                            <span>Maternal Delivery Outcome</span>
                                            <Badge variant="outline" className="border-pink-300 capitalize text-pink-700">
                                                {deliveryRecord.delivery_mode.replace('_', ' ')}
                                            </Badge>
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="flex flex-col gap-3 pt-4 text-sm">
                                        <div className="flex justify-between border-b pb-2">
                                            <span className="text-muted-foreground">Delivered At</span>
                                            <span className="font-medium">{new Date(deliveryRecord.delivered_at).toLocaleString()}</span>
                                        </div>
                                        <div className="flex justify-between border-b pb-2">
                                            <span className="text-muted-foreground">Estimated Blood Loss (EBL)</span>
                                            <span className={`font-bold ${deliveryRecord.postpartum_hemorrhage ? 'text-red-600' : ''}`}>
                                                {deliveryRecord.blood_loss_ml} ml {deliveryRecord.postpartum_hemorrhage && '(PPH ⚠️)'}
                                            </span>
                                        </div>
                                        <div className="flex justify-between border-b pb-2">
                                            <span className="text-muted-foreground">Placenta</span>
                                            <span className="font-medium">
                                                {deliveryRecord.placenta_complete ? 'Complete' : 'Incomplete ⚠️'} (
                                                {deliveryRecord.placenta_mode})
                                            </span>
                                        </div>
                                        <div className="flex justify-between border-b pb-2">
                                            <span className="text-muted-foreground">Perineal Status</span>
                                            <span className="font-medium capitalize">{deliveryRecord.perineal_status.replace('_', ' ')}</span>
                                        </div>
                                        {deliveryRecord.suture_materials_used && (
                                            <div className="flex justify-between border-b pb-2">
                                                <span className="text-muted-foreground">Suture Material</span>
                                                <span className="font-medium">{deliveryRecord.suture_materials_used}</span>
                                            </div>
                                        )}
                                        <div className="flex justify-between border-b pb-2">
                                            <span className="text-muted-foreground">Oxytocic Given</span>
                                            <span className="font-medium">{deliveryRecord.oxytocic_given ?? 'None'}</span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">Conducted By</span>
                                            <span className="font-medium">{deliveryRecord.conducted_by?.UserName ?? 'Staff'}</span>
                                        </div>
                                    </CardContent>
                                </Card>

                                {/* Neonatal Outcome Card */}
                                <Card>
                                    <CardHeader className="bg-sky-50/50 pb-3 dark:bg-sky-950/20">
                                        <CardTitle className="text-base flex items-center justify-between">
                                            <span>Neonatal Outcome & APGAR</span>
                                            <Badge variant="outline" className="border-sky-300 capitalize text-sky-700">
                                                {deliveryRecord.baby_gender} • {deliveryRecord.baby_outcome.replace('_', ' ')}
                                            </Badge>
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="flex flex-col gap-3 pt-4 text-sm">
                                        <div className="grid grid-cols-3 gap-2 text-center border-b pb-3">
                                            <div className="rounded bg-muted/40 p-2">
                                                <span className="text-xs text-muted-foreground">APGAR 1 min</span>
                                                <div className="text-lg font-bold">{deliveryRecord.apgar_1min ?? '—'}/10</div>
                                            </div>
                                            <div className="rounded bg-muted/40 p-2">
                                                <span className="text-xs text-muted-foreground">APGAR 5 min</span>
                                                <div className="text-lg font-bold">{deliveryRecord.apgar_5min ?? '—'}/10</div>
                                            </div>
                                            <div className="rounded bg-muted/40 p-2">
                                                <span className="text-xs text-muted-foreground">APGAR 10 min</span>
                                                <div className="text-lg font-bold">{deliveryRecord.apgar_10min ?? '—'}/10</div>
                                            </div>
                                        </div>

                                        <div className="flex justify-between border-b pb-2">
                                            <span className="text-muted-foreground">Birth Weight</span>
                                            <span className="font-bold">{deliveryRecord.birth_weight_grams ? `${deliveryRecord.birth_weight_grams} g` : 'Not weighed'}</span>
                                        </div>
                                        <div className="flex justify-between border-b pb-2">
                                            <span className="text-muted-foreground">Length / Head Circ</span>
                                            <span className="font-medium">
                                                {deliveryRecord.birth_length_cm ?? '—'} cm / {deliveryRecord.head_circumference_cm ?? '—'} cm
                                            </span>
                                        </div>
                                        <div className="flex justify-between border-b pb-2">
                                            <span className="text-muted-foreground">Resuscitation</span>
                                            <span className={`font-medium ${deliveryRecord.resuscitation_required ? 'text-amber-600' : ''}`}>
                                                {deliveryRecord.resuscitation_required ? `Yes (${deliveryRecord.resuscitation_notes ?? 'Required'})` : 'No'}
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">Prophylaxis</span>
                                            <span className="font-medium text-xs">
                                                {deliveryRecord.vitamin_k_given && 'Vit K ✓ '}
                                                {deliveryRecord.eye_ointment_given && 'Tetracycline Eye ✓ '}
                                                {deliveryRecord.bcg_given && 'BCG ✓ '}
                                                {deliveryRecord.polio_0_given && 'Polio 0 ✓'}
                                            </span>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>
                        )}
                    </TabsContent>
                </Tabs>
            </div>

            {/* MODAL 1: OBSTETRIC PROFILE DIALOG */}
            <Dialog open={profileDialogOpen} onOpenChange={setProfileDialogOpen}>
                <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Document Obstetric Context & Admission</DialogTitle>
                        <DialogDescription>
                            Enter parity, gestational age calculations, membranes status, and high-risk flags.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submitProfile} className="flex flex-col gap-4">
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div>
                                <Label htmlFor="gravida">Gravida</Label>
                                <Input
                                    id="gravida"
                                    type="number"
                                    min="1"
                                    value={profileForm.data.gravida}
                                    onChange={(e) => profileForm.setData('gravida', parseInt(e.target.value) || 1)}
                                    required
                                />
                            </div>
                            <div>
                                <Label htmlFor="para">Para</Label>
                                <Input
                                    id="para"
                                    type="number"
                                    min="0"
                                    value={profileForm.data.para}
                                    onChange={(e) => profileForm.setData('para', parseInt(e.target.value) || 0)}
                                    required
                                />
                            </div>
                            <div>
                                <Label htmlFor="living">Living</Label>
                                <Input
                                    id="living"
                                    type="number"
                                    min="0"
                                    value={profileForm.data.living_children}
                                    onChange={(e) => profileForm.setData('living_children', parseInt(e.target.value) || 0)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="abortions">Abortions</Label>
                                <Input
                                    id="abortions"
                                    type="number"
                                    min="0"
                                    value={profileForm.data.abortions}
                                    onChange={(e) => profileForm.setData('abortions', parseInt(e.target.value) || 0)}
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div>
                                <Label htmlFor="lmp">Last Menstrual Period (LMP)</Label>
                                <Input
                                    id="lmp"
                                    type="date"
                                    value={profileForm.data.last_menstrual_period}
                                    onChange={(e) => profileForm.setData('last_menstrual_period', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="edd">EDD (auto if LMP set)</Label>
                                <Input
                                    id="edd"
                                    type="date"
                                    value={profileForm.data.estimated_date_of_delivery}
                                    onChange={(e) => profileForm.setData('estimated_date_of_delivery', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="ga">Gestational Age (weeks)</Label>
                                <Input
                                    id="ga"
                                    type="number"
                                    step="0.1"
                                    value={profileForm.data.gestational_age_weeks}
                                    onChange={(e) => profileForm.setData('gestational_age_weeks', e.target.value)}
                                    placeholder="e.g. 39.2"
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div>
                                <Label htmlFor="presentation">Presentation</Label>
                                <Select
                                    value={profileForm.data.presentation}
                                    onValueChange={(v) => profileForm.setData('presentation', v)}
                                >
                                    <SelectTrigger id="presentation">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {constants.presentations.map((p) => (
                                            <SelectItem key={p} value={p} className="capitalize">
                                                {p}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label htmlFor="membrane">Membrane Status</Label>
                                <Select
                                    value={profileForm.data.membrane_status}
                                    onValueChange={(v) => profileForm.setData('membrane_status', v)}
                                >
                                    <SelectTrigger id="membrane">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {constants.membrane_statuses.map((m) => (
                                            <SelectItem key={m} value={m} className="capitalize">
                                                {m.replace('_', ' ')}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label htmlFor="labour_stage">Labour Stage</Label>
                                <Select
                                    value={profileForm.data.labour_stage}
                                    onValueChange={(v) => profileForm.setData('labour_stage', v)}
                                >
                                    <SelectTrigger id="labour_stage">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {constants.labour_stages.map((s) => (
                                            <SelectItem key={s} value={s} className="capitalize">
                                                {s.replace('_', ' ')}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                            <div>
                                <Label htmlFor="blood_group">Blood Group</Label>
                                <Select
                                    value={profileForm.data.blood_group}
                                    onValueChange={(v) => profileForm.setData('blood_group', v)}
                                >
                                    <SelectTrigger id="blood_group">
                                        <SelectValue placeholder="Select" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {['A', 'B', 'AB', 'O'].map((bg) => (
                                            <SelectItem key={bg} value={bg}>
                                                {bg}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label htmlFor="rhesus">Rhesus</Label>
                                <Select
                                    value={profileForm.data.rhesus}
                                    onValueChange={(v) => profileForm.setData('rhesus', v)}
                                >
                                    <SelectTrigger id="rhesus">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="positive">Positive (+)</SelectItem>
                                        <SelectItem value="negative">Negative (-)</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label htmlFor="hiv">HIV Status</Label>
                                <Select
                                    value={profileForm.data.hiv_status}
                                    onValueChange={(v) => profileForm.setData('hiv_status', v)}
                                >
                                    <SelectTrigger id="hiv">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {constants.hiv_statuses.map((h) => (
                                            <SelectItem key={h} value={h} className="capitalize">
                                                {h.replace(/_/g, ' ')}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label htmlFor="vdrl">VDRL / RPR</Label>
                                <Select
                                    value={profileForm.data.vdrl_rpr_status}
                                    onValueChange={(v) => profileForm.setData('vdrl_rpr_status', v)}
                                >
                                    <SelectTrigger id="vdrl">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="non_reactive">Non Reactive</SelectItem>
                                        <SelectItem value="reactive">Reactive</SelectItem>
                                        <SelectItem value="unknown">Unknown</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        {/* Risk Factors Selection */}
                        <div>
                            <Label className="mb-1.5 block">Documented Obstetric Risk Factors</Label>
                            <div className="grid grid-cols-1 gap-2 rounded-lg border p-3 sm:grid-cols-2 text-xs">
                                {COMMON_RISK_FACTORS.map((factor) => {
                                    const checked = profileForm.data.risk_factors.includes(factor);
                                    return (
                                        <label key={factor} className="flex items-center gap-2 cursor-pointer">
                                            <Checkbox
                                                checked={checked}
                                                onCheckedChange={(isChecked) => {
                                                    const updated = isChecked
                                                        ? [...profileForm.data.risk_factors, factor]
                                                        : profileForm.data.risk_factors.filter((f: string) => f !== factor);
                                                    profileForm.setData('risk_factors', updated);
                                                }}
                                            />
                                            <span>{factor}</span>
                                        </label>
                                    );
                                })}
                            </div>
                        </div>

                        <div>
                            <Label htmlFor="notes">Clinical / Antenatal Notes</Label>
                            <Textarea
                                id="notes"
                                value={profileForm.data.notes}
                                onChange={(e) => profileForm.setData('notes', e.target.value)}
                                placeholder="Any additional clinical history or instructions..."
                                rows={2}
                            />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setProfileDialogOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={profileForm.processing} className="bg-pink-600 hover:bg-pink-700">
                                Save Maternity Profile
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* MODAL 2: PARTOGRAPH READING DIALOG */}
            <Dialog open={partographDialogOpen} onOpenChange={setPartographDialogOpen}>
                <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Record WHO Partograph Reading</DialogTitle>
                        <DialogDescription>
                            Document progress of labour and fetal/maternal observations.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submitPartograph} className="flex flex-col gap-4">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="recorded_at">Observation Time</Label>
                                <Input
                                    id="recorded_at"
                                    type="datetime-local"
                                    value={partographForm.data.recorded_at}
                                    onChange={(e) => partographForm.setData('recorded_at', e.target.value)}
                                    required
                                />
                            </div>
                            <div>
                                <Label htmlFor="fhr">Fetal Heart Rate (bpm)</Label>
                                <Input
                                    id="fhr"
                                    type="number"
                                    min="60"
                                    max="220"
                                    placeholder="Normal: 110 - 160 bpm"
                                    value={partographForm.data.fetal_heart_rate}
                                    onChange={(e) => partographForm.setData('fetal_heart_rate', e.target.value)}
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div>
                                <Label htmlFor="dilation">Cervical Dilation (cm)</Label>
                                <Input
                                    id="dilation"
                                    type="number"
                                    step="0.5"
                                    min="0"
                                    max="10"
                                    placeholder="0 - 10 cm"
                                    value={partographForm.data.cervical_dilation_cm}
                                    onChange={(e) => partographForm.setData('cervical_dilation_cm', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="descent">Head Descent (/5)</Label>
                                <Input
                                    id="descent"
                                    type="number"
                                    min="0"
                                    max="5"
                                    placeholder="0 to 5"
                                    value={partographForm.data.descent_of_head}
                                    onChange={(e) => partographForm.setData('descent_of_head', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="liquor">Amniotic Fluid</Label>
                                <Select
                                    value={partographForm.data.amniotic_fluid}
                                    onValueChange={(v) => partographForm.setData('amniotic_fluid', v)}
                                >
                                    <SelectTrigger id="liquor">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(constants.amniotic_fluid_states).map(([code, name]) => (
                                            <SelectItem key={code} value={code}>
                                                {code} — {name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label htmlFor="moulding">Moulding</Label>
                                <Select
                                    value={partographForm.data.moulding}
                                    onValueChange={(v) => partographForm.setData('moulding', v)}
                                >
                                    <SelectTrigger id="moulding">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(constants.moulding_grades).map(([code, name]) => (
                                            <SelectItem key={code} value={code}>
                                                {code} ({name})
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="contractions">Contractions / 10 min</Label>
                                <Input
                                    id="contractions"
                                    type="number"
                                    min="1"
                                    max="7"
                                    placeholder="1 to 5"
                                    value={partographForm.data.contractions_per_10min}
                                    onChange={(e) => partographForm.setData('contractions_per_10min', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="duration">Contraction Duration</Label>
                                <Select
                                    value={partographForm.data.contraction_duration}
                                    onValueChange={(v) => partographForm.setData('contraction_duration', v)}
                                >
                                    <SelectTrigger id="duration">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(constants.contraction_durations).map(([code, label]) => (
                                            <SelectItem key={code} value={code}>
                                                {label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div>
                                <Label htmlFor="systolic">Maternal SBP (mmHg)</Label>
                                <Input
                                    id="systolic"
                                    type="number"
                                    placeholder="e.g. 120"
                                    value={partographForm.data.systolic_bp}
                                    onChange={(e) => partographForm.setData('systolic_bp', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="diastolic">Maternal DBP (mmHg)</Label>
                                <Input
                                    id="diastolic"
                                    type="number"
                                    placeholder="e.g. 80"
                                    value={partographForm.data.diastolic_bp}
                                    onChange={(e) => partographForm.setData('diastolic_bp', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="pulse">Maternal Pulse (bpm)</Label>
                                <Input
                                    id="pulse"
                                    type="number"
                                    placeholder="e.g. 82"
                                    value={partographForm.data.pulse_bpm}
                                    onChange={(e) => partographForm.setData('pulse_bpm', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="temp">Temp (°C)</Label>
                                <Input
                                    id="temp"
                                    type="number"
                                    step="0.1"
                                    placeholder="e.g. 36.8"
                                    value={partographForm.data.temperature_c}
                                    onChange={(e) => partographForm.setData('temperature_c', e.target.value)}
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="oxytocin">Oxytocin Infusion (drops/min)</Label>
                                <Input
                                    id="oxytocin"
                                    type="number"
                                    placeholder="e.g. 20"
                                    value={partographForm.data.oxytocin_drops_per_min}
                                    onChange={(e) => partographForm.setData('oxytocin_drops_per_min', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="drugs">Drugs & IV Fluids</Label>
                                <Input
                                    id="drugs"
                                    placeholder="e.g. Ringer's Lactate 500ml"
                                    value={partographForm.data.drugs_iv_fluids}
                                    onChange={(e) => partographForm.setData('drugs_iv_fluids', e.target.value)}
                                />
                            </div>
                        </div>

                        <div>
                            <Label htmlFor="parto_notes">Clinical Observations / Action Taken</Label>
                            <Textarea
                                id="parto_notes"
                                value={partographForm.data.notes}
                                onChange={(e) => partographForm.setData('notes', e.target.value)}
                                placeholder="Any maternal complaints, vaginal exam findings, or interventions..."
                                rows={2}
                            />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setPartographDialogOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={partographForm.processing} className="bg-pink-600 hover:bg-pink-700">
                                Save Reading
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* MODAL 3: DOCUMENT DELIVERY DIALOG */}
            <Dialog open={deliveryDialogOpen} onOpenChange={setDeliveryDialogOpen}>
                <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Document Delivery & Birth Outcome</DialogTitle>
                        <DialogDescription>
                            Record delivery mode, third stage outcome, blood loss, and newborn APGAR scores.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submitDelivery} className="flex flex-col gap-4">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="delivered_at">Delivery Time</Label>
                                <Input
                                    id="delivered_at"
                                    type="datetime-local"
                                    value={deliveryForm.data.delivered_at}
                                    onChange={(e) => deliveryForm.setData('delivered_at', e.target.value)}
                                    required
                                />
                            </div>
                            <div>
                                <Label htmlFor="delivery_mode">Delivery Mode</Label>
                                <Select
                                    value={deliveryForm.data.delivery_mode}
                                    onValueChange={(v) => deliveryForm.setData('delivery_mode', v)}
                                >
                                    <SelectTrigger id="delivery_mode">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(constants.delivery_modes).map(([key, label]) => (
                                            <SelectItem key={key} value={key}>
                                                {label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        {deliveryForm.data.delivery_mode.includes('c_section') || deliveryForm.data.delivery_mode.includes('assisted') ? (
                            <div>
                                <Label htmlFor="indication">Indication for Intervention</Label>
                                <Input
                                    id="indication"
                                    value={deliveryForm.data.indication}
                                    onChange={(e) => deliveryForm.setData('indication', e.target.value)}
                                    placeholder="e.g. Fetal distress, obstructed labour, failed induction"
                                    required
                                />
                            </div>
                        ) : null}

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="conducted_by">Conducted By</Label>
                                <Select
                                    value={deliveryForm.data.conducted_by}
                                    onValueChange={(v) => deliveryForm.setData('conducted_by', v)}
                                >
                                    <SelectTrigger id="conducted_by">
                                        <SelectValue placeholder="Select practitioner" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {practitioners.map((p) => (
                                            <SelectItem key={p.id} value={String(p.id)}>
                                                {p.staff ? `${p.staff.first_name} ${p.staff.last_name}` : `Practitioner #${p.id}`}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label htmlFor="ebl">Estimated Blood Loss (ml)</Label>
                                <Input
                                    id="ebl"
                                    type="number"
                                    min="0"
                                    max="5000"
                                    value={deliveryForm.data.blood_loss_ml}
                                    onChange={(e) => deliveryForm.setData('blood_loss_ml', e.target.value)}
                                    required
                                />
                                {parseInt(deliveryForm.data.blood_loss_ml) >= 500 && (
                                    <span className="text-[11px] font-semibold text-red-600">
                                        ⚠️ Blood loss &gt;= 500ml qualifies as Postpartum Hemorrhage (PPH)
                                    </span>
                                )}
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div>
                                <Label htmlFor="perineal">Perineal Status</Label>
                                <Select
                                    value={deliveryForm.data.perineal_status}
                                    onValueChange={(v) => deliveryForm.setData('perineal_status', v)}
                                >
                                    <SelectTrigger id="perineal">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(constants.perineal_statuses).map(([key, label]) => (
                                            <SelectItem key={key} value={key}>
                                                {label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label htmlFor="placenta_complete">Placenta</Label>
                                <Select
                                    value={deliveryForm.data.placenta_complete ? 'true' : 'false'}
                                    onValueChange={(v) => deliveryForm.setData('placenta_complete', v === 'true')}
                                >
                                    <SelectTrigger id="placenta_complete">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="true">Complete</SelectItem>
                                        <SelectItem value="false">Incomplete (Retained fragments)</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label htmlFor="oxytocic">Oxytocic Given</Label>
                                <Input
                                    id="oxytocic"
                                    value={deliveryForm.data.oxytocic_given}
                                    onChange={(e) => deliveryForm.setData('oxytocic_given', e.target.value)}
                                    placeholder="e.g. Oxytocin 10 IU IM"
                                />
                            </div>
                        </div>

                        {/* Newborn Assessment Section */}
                        <div className="rounded-lg border bg-muted/20 p-3 flex flex-col gap-3">
                            <h3 className="text-xs font-bold uppercase text-muted-foreground flex items-center gap-1.5">
                                <Baby className="h-4 w-4 text-sky-600" />
                                Newborn Assessment & APGAR Scores
                            </h3>

                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <div>
                                    <Label htmlFor="baby_gender">Baby Sex</Label>
                                    <Select
                                        value={deliveryForm.data.baby_gender}
                                        onValueChange={(v) => deliveryForm.setData('baby_gender', v)}
                                    >
                                        <SelectTrigger id="baby_gender">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="male">Male</SelectItem>
                                            <SelectItem value="female">Female</SelectItem>
                                            <SelectItem value="ambiguous">Ambiguous</SelectItem>
                                        </SelectContent>
                                    </SelectContent>
                                </Select>
                                </div>
                                <div>
                                    <Label htmlFor="baby_outcome">Outcome</Label>
                                    <Select
                                        value={deliveryForm.data.baby_outcome}
                                        onValueChange={(v) => deliveryForm.setData('baby_outcome', v)}
                                    >
                                        <SelectTrigger id="baby_outcome">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(constants.baby_outcomes).map(([key, label]) => (
                                                <SelectItem key={key} value={key}>
                                                    {label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </SelectContent>
                                </Select>
                                </div>
                                <div>
                                    <Label htmlFor="weight">Birth Weight (g)</Label>
                                    <Input
                                        id="weight"
                                        type="number"
                                        min="200"
                                        max="8000"
                                        value={deliveryForm.data.birth_weight_grams}
                                        onChange={(e) => deliveryForm.setData('birth_weight_grams', e.target.value)}
                                        placeholder="e.g. 3200"
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="length">Length (cm)</Label>
                                    <Input
                                        id="length"
                                        type="number"
                                        step="0.5"
                                        value={deliveryForm.data.birth_length_cm}
                                        onChange={(e) => deliveryForm.setData('birth_length_cm', e.target.value)}
                                        placeholder="e.g. 50"
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-3 gap-3">
                                <div>
                                    <Label htmlFor="apgar1">APGAR 1 min (0-10)</Label>
                                    <Input
                                        id="apgar1"
                                        type="number"
                                        min="0"
                                        max="10"
                                        value={deliveryForm.data.apgar_1min}
                                        onChange={(e) => deliveryForm.setData('apgar_1min', e.target.value)}
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="apgar5">APGAR 5 min (0-10)</Label>
                                    <Input
                                        id="apgar5"
                                        type="number"
                                        min="0"
                                        max="10"
                                        value={deliveryForm.data.apgar_5min}
                                        onChange={(e) => deliveryForm.setData('apgar_5min', e.target.value)}
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="apgar10">APGAR 10 min (0-10)</Label>
                                    <Input
                                        id="apgar10"
                                        type="number"
                                        min="0"
                                        max="10"
                                        value={deliveryForm.data.apgar_10min}
                                        onChange={(e) => deliveryForm.setData('apgar_10min', e.target.value)}
                                    />
                                </div>
                            </div>

                            <div className="flex flex-wrap items-center gap-4 pt-1 text-xs">
                                <label className="flex items-center gap-1.5 cursor-pointer">
                                    <Checkbox
                                        checked={deliveryForm.data.vitamin_k_given}
                                        onCheckedChange={(c) => deliveryForm.setData('vitamin_k_given', Boolean(c))}
                                    />
                                    <span>Vitamin K Given</span>
                                </label>
                                <label className="flex items-center gap-1.5 cursor-pointer">
                                    <Checkbox
                                        checked={deliveryForm.data.eye_ointment_given}
                                        onCheckedChange={(c) => deliveryForm.setData('eye_ointment_given', Boolean(c))}
                                    />
                                    <span>Tetracycline Eye Ointment</span>
                                </label>
                                <label className="flex items-center gap-1.5 cursor-pointer">
                                    <Checkbox
                                        checked={deliveryForm.data.resuscitation_required}
                                        onCheckedChange={(c) => deliveryForm.setData('resuscitation_required', Boolean(c))}
                                    />
                                    <span>Resuscitation Required</span>
                                </label>
                            </div>

                            {deliveryForm.data.resuscitation_required && (
                                <div>
                                    <Label htmlFor="resus_notes">Resuscitation Details</Label>
                                    <Textarea
                                        id="resus_notes"
                                        value={deliveryForm.data.resuscitation_notes}
                                        onChange={(e) => deliveryForm.setData('resuscitation_notes', e.target.value)}
                                        placeholder="Bag and mask ventilation, suctioning, duration..."
                                        rows={2}
                                    />
                                </div>
                            )}
                        </div>

                        <div>
                            <Label htmlFor="del_notes">Delivery Notes & Postpartum Instructions</Label>
                            <Textarea
                                id="del_notes"
                                value={deliveryForm.data.notes}
                                onChange={(e) => deliveryForm.setData('notes', e.target.value)}
                                placeholder="Summary of 3rd stage, immediate maternal response..."
                                rows={2}
                            />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setDeliveryDialogOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={deliveryForm.processing} className="bg-pink-600 hover:bg-pink-700">
                                Save Delivery Record
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
