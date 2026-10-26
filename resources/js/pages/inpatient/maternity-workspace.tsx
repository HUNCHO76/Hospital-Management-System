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
import { MedicationSearch } from '@/components/care-plan/medication-search';
import { LaboratoryOrderTestSelector } from '@/components/laboratory/laboratory-order-test-selector';
import { type LaboratoryOrder, type LaboratoryOrderPriority, type LaboratoryTestSearchResult } from '@/types/laboratory';
import { type MedicationSearchResult } from '@/types/medication';
import { type PrescriptionFrequency, type PrescriptionOrder, type PrescriptionRoute } from '@/types/prescription';
import { type RadiologyOrder, type RadiologyOrderPriority, type RadiologyProcedure } from '@/types/radiology';
import {
    Activity,
    AlertCircle,
    AlertTriangle,
    Baby,
    Banknote,
    Calendar,
    Check,
    CheckCircle2,
    Clock,
    Eye,
    FileSpreadsheet,
    FileText,
    FlaskConical,
    HeartPulse,
    HelpCircle,
    Info,
    Pill,
    Plus,
    Scan,
    Scissors,
    ShieldAlert,
    Stethoscope,
    Trash2,
    User,
    X,
} from 'lucide-react';
import { ViewRadiologyReportDialog, type ViewRadiologyReportData, type ViewRadiologyReportProcedure } from '@/components/radiology/view-radiology-report-dialog';
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
    care_stage?: string;
    care_pathway?: string;
    assigned_midwife_id?: number | string | null;
    primary_clinician_id?: number | string | null;
    clinical_exception_reason?: string | null;
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
    user_id?: number | null;
    name?: string;
    staff?: { id: number; first_name: string; last_name: string; user_id?: number };
}

interface MaternityWorkspaceProps {
    admission: Admission;
    profile: (MaternityProfileData & {
        episode_number?: string;
        care_stage?: string;
        care_pathway?: string;
        assignedMidwife?: { id: number; UserName: string };
        primaryClinician?: { id: number; UserName: string };
    }) | null;
    is_registered?: boolean;
    episode_number?: string | null;
    care_pathway?: string;
    partographEntries: PartographReading[];
    deliveryRecord: DeliverySummary | null;
    practitioners: PractitionerOption[];
    eligibility?: {
        eligible: boolean;
        message: string;
        requires_exception: boolean;
        existing_active_episode: any | null;
    };
    nhif_clearance?: {
        is_nhif: boolean;
        insurance_status: string;
        authorization_required: boolean;
        authorization_status: string;
        service_permitted: boolean;
        is_valid: boolean;
        emergency_override_available: boolean;
        reason: string;
        reference_no?: string | null;
        expires_at?: string | null;
    };
    constants: {
        amniotic_fluid_states: Record<string, string>;
        moulding_grades: Record<string, string>;
        contraction_durations: Record<string, string>;
        presentations: string[];
        membrane_statuses: string[];
        hiv_statuses: string[];
        care_stages?: Record<string, string>;
        care_pathways?: Record<string, string>;
        labour_stages: string[];
        delivery_modes: Record<string, string>;
        perineal_statuses: Record<string, string>;
        baby_outcomes: Record<string, string>;
    };
    latestVital?: any | null;
    clinicalAlerts?: any[];
    medications?: any[];
    laboratoryOrders?: LaboratoryOrder[];
    prescriptionOrders?: PrescriptionOrder[];
    radiologyOrders?: RadiologyOrder[];
    radiologyProcedures?: RadiologyProcedure[];
    canOrderLabs?: boolean;
    canPrescribe?: boolean;
    canOrderRadiology?: boolean;
    activeEncounterId?: number | null;
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
    is_registered,
    episode_number,
    care_pathway,
    partographEntries = [],
    deliveryRecord,
    practitioners = [],
    eligibility,
    nhif_clearance,
    constants,
    latestVital = null,
    clinicalAlerts = [],
    medications = [],
    laboratoryOrders = [],
    prescriptionOrders = [],
    radiologyOrders = [],
    radiologyProcedures = [],
    canOrderLabs = false,
    canPrescribe = false,
    canOrderRadiology = false,
    activeEncounterId = null,
}: MaternityWorkspaceProps) {
    const { flash } = usePage().props as { flash?: { success?: string; error?: string } };

    const insurance = admission?.patient_insurance ?? admission?.patientInsurance;

    // Resolve maternal observations from latest vital sign prop or fallback to latest partograph reading
    const latestPartograph = partographEntries && partographEntries.length > 0
        ? partographEntries[partographEntries.length - 1]
        : null;

    const displayVital = latestVital ?? (latestPartograph ? {
        recorded_at: latestPartograph.recorded_at,
        systolic_bp: latestPartograph.systolic_bp,
        diastolic_bp: latestPartograph.diastolic_bp,
        pulse_rate: latestPartograph.pulse_bpm,
        temperature: latestPartograph.temperature_c,
        oxygen_saturation: null,
        respiratory_rate: null,
        pain_score: null,
    } : null);

    const [activeTab, setActiveTab] = useState<'partograph' | 'profile' | 'delivery' | 'orders'>('partograph');
    const [profileDialogOpen, setProfileDialogOpen] = useState(false);
    const [partographDialogOpen, setPartographDialogOpen] = useState(false);
    const [deliveryDialogOpen, setDeliveryDialogOpen] = useState(false);
    const [stageDialogOpen, setStageDialogOpen] = useState(false);
    const [labOrderDialogOpen, setLabOrderDialogOpen] = useState(false);
    const [prescriptionDialogOpen, setPrescriptionDialogOpen] = useState(false);
    const [radiologyDialogOpen, setRadiologyDialogOpen] = useState(false);
    const [selectedReport, setSelectedReport] = useState<ViewRadiologyReportData | null>(null);
    const [selectedReportProcedure, setSelectedReportProcedure] = useState<ViewRadiologyReportProcedure | null>(null);

    // Radiology Order Form
    const radiologyForm = useForm({
        procedure_ids: [] as number[],
        priority: 'routine' as RadiologyOrderPriority,
        clinical_indication: '',
        notes: '',
        send_preapproval: true,
    });

    const submitRadiologyOrder = (e: React.FormEvent) => {
        e.preventDefault();
        if (radiologyForm.data.procedure_ids.length === 0) return;
        radiologyForm.post(route('inpatient.maternity.radiology-orders.store', admission.id), {
            preserveScroll: true,
            onSuccess: () => {
                setRadiologyDialogOpen(false);
                radiologyForm.reset();
            },
        });
    };

    // Laboratory Order Form
    const [selectedTests, setSelectedTests] = useState<LaboratoryTestSearchResult[]>([]);
    const labOrderForm = useForm({
        test_ids: [] as number[],
        priority: 'routine' as LaboratoryOrderPriority,
        clinical_indication: '',
        notes: '',
    });

    const submitLabOrder = (e: React.FormEvent) => {
        e.preventDefault();
        if (selectedTests.length === 0) return;
        labOrderForm.transform(() => ({
            test_ids: selectedTests.map((t) => t.id),
            priority: labOrderForm.data.priority,
            clinical_indication: labOrderForm.data.clinical_indication,
            notes: labOrderForm.data.notes,
        }));
        labOrderForm.post(route('inpatient.maternity.laboratory-orders.store', admission.id), {
            preserveScroll: true,
            onSuccess: () => {
                setLabOrderDialogOpen(false);
                setSelectedTests([]);
                labOrderForm.reset();
            },
        });
    };

    // Prescription Form
    interface PrescriptionLineItem {
        medication: MedicationSearchResult | null;
        dose: string;
        dose_unit: string;
        route: PrescriptionRoute;
        frequency: PrescriptionFrequency;
        duration: number;
        duration_unit: 'days' | 'weeks' | 'hours' | 'doses';
        quantity: number;
        instructions: string;
    }

    const [prescriptionItems, setPrescriptionItems] = useState<PrescriptionLineItem[]>([
        {
            medication: null,
            dose: '',
            dose_unit: '',
            route: 'oral',
            frequency: 'once_daily',
            duration: 1,
            duration_unit: 'days',
            quantity: 1,
            instructions: '',
        },
    ]);

    const prescriptionForm = useForm({
        items: [] as any[],
        clinical_notes: '',
    });

    const submitPrescription = (e: React.FormEvent) => {
        e.preventDefault();
        const validItems = prescriptionItems
            .filter((i) => i.medication !== null)
            .map((i) => ({
                medication_id: i.medication!.id,
                dose: i.dose || '1',
                dose_unit: i.dose_unit || null,
                route: i.route,
                frequency: i.frequency,
                duration: i.duration || 1,
                duration_unit: i.duration_unit,
                quantity: i.quantity || 1,
                instructions: i.instructions || null,
            }));

        if (validItems.length === 0) return;

        prescriptionForm.transform(() => ({
            items: validItems,
            clinical_notes: prescriptionForm.data.clinical_notes,
        }));
        prescriptionForm.post(route('inpatient.maternity.prescriptions.store', admission.id), {
            preserveScroll: true,
            onSuccess: () => {
                setPrescriptionDialogOpen(false);
                setPrescriptionItems([
                    {
                        medication: null,
                        dose: '',
                        dose_unit: '',
                        route: 'oral',
                        frequency: 'once_daily',
                        duration: 1,
                        duration_unit: 'days',
                        quantity: 1,
                        instructions: '',
                    },
                ]);
                prescriptionForm.reset();
            },
        });
    };

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
        care_stage: profile?.care_stage ?? 'awaiting_assessment',
        assigned_midwife_id: profile?.assigned_midwife_id ? String(profile.assigned_midwife_id) : '',
        primary_clinician_id: profile?.primary_clinician_id ? String(profile.primary_clinician_id) : '',
        clinical_exception_reason: profile?.clinical_exception_reason ?? '',
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
        placenta_complete: true as boolean,
        blood_loss_ml: '250',
        postpartum_hemorrhage: false as boolean,
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
        resuscitation_required: false as boolean,
        resuscitation_notes: '',
        vitamin_k_given: true as boolean,
        eye_ointment_given: true as boolean,
        bcg_given: false as boolean,
        polio_0_given: false as boolean,
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

    // Stage Transition Form
    const stageForm = useForm({
        care_stage: profile?.care_stage ?? 'first_stage_active',
        notes: '',
        is_emergency: false,
        emergency_reason: '',
    });

    const submitStageTransition = (e: React.FormEvent) => {
        e.preventDefault();
        if (!profile) return;
        stageForm.post(route('inpatient.maternity.stage.update', [admission.id, profile.id]), {
            preserveScroll: true,
            onSuccess: () => {
                stageForm.reset();
                setStageDialogOpen(false);
            },
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
                                <div className="flex items-center gap-2">
                                    <Button variant="outline" className="border-pink-300 text-pink-700 hover:bg-pink-50 dark:hover:bg-pink-950/40" onClick={() => setProfileDialogOpen(true)}>
                                        Edit Obstetric Profile
                                    </Button>
                                    <Button variant="outline" className="border-pink-300 text-pink-700 hover:bg-pink-50 dark:hover:bg-pink-950/40" onClick={() => setStageDialogOpen(true)}>
                                        Update Care Stage
                                    </Button>
                                </div>
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
                    <TabsList className="grid w-full grid-cols-2 sm:grid-cols-4 max-w-2xl">
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
                        <TabsTrigger value="orders" className="flex items-center gap-1.5">
                            <HeartPulse className="h-4 w-4 text-rose-600" />
                            Orders & MAR ({medications.length + laboratoryOrders.length + prescriptionOrders.length + radiologyOrders.length})
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
                                action={
                                    <Button
                                        className="bg-pink-600 hover:bg-pink-700"
                                        onClick={() => setProfileDialogOpen(true)}
                                    >
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        Open Maternity Profile
                                    </Button>
                                }
                            />
                        ) : partographEntries.length === 0 ? (
                            <EmptyState
                                icon={FileSpreadsheet}
                                title="No Partograph Readings Yet"
                                description="Document the first partograph reading when the patient enters the active phase of labour (cervical dilation >= 4 cm)."
                                action={
                                    <Button
                                        className="bg-pink-600 hover:bg-pink-700"
                                        onClick={() => setPartographDialogOpen(true)}
                                    >
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        Record First Reading
                                    </Button>
                                }
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
                                                                        className={`font-semibold ${isMeconium ? 'text-amber-600 font-bold' : ''
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
                                    profile ? (
                                        <Button
                                            className="bg-pink-600 hover:bg-pink-700"
                                            onClick={() => setDeliveryDialogOpen(true)}
                                        >
                                            <Baby className="mr-1.5 h-4 w-4" />
                                            Document Delivery
                                        </Button>
                                    ) : undefined
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

                    {/* TAB 4: CLINICAL ORDERS, MATERNAL OBSERVATIONS & MAR */}
                    <TabsContent value="orders" className="mt-4 flex flex-col gap-6">
                        {/* Cash / Self-Pay Notice & Quick Actions */}
                        {!insurance ? (
                            <Alert className="border-emerald-500/40 bg-emerald-500/10 text-emerald-950 dark:border-emerald-500/30 dark:bg-emerald-950/20 dark:text-emerald-100">
                                <Banknote className="h-5 w-5 text-emerald-600 dark:text-emerald-400 mt-0.5" />
                                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 w-full">
                                    <div>
                                        <AlertTitle className="text-sm font-semibold flex items-center gap-2">
                                            Cash / Self-Pay Patient Mode
                                            <Badge variant="outline" className="border-emerald-600 text-emerald-700 dark:text-emerald-300 text-[10px] font-normal">
                                                Direct Inpatient Billing
                                            </Badge>
                                        </AlertTitle>
                                        <AlertDescription className="text-xs text-muted-foreground mt-0.5">
                                            This patient has no NHIF coverage. Diagnostic lab tests and medications are billed directly to the cash account and dispatched to departments without NHIF pre-approval delays.
                                        </AlertDescription>
                                    </div>
                                    <div className="flex items-center gap-2 shrink-0">
                                        {canOrderLabs && (
                                            <Button
                                                size="sm"
                                                className="bg-emerald-600 hover:bg-emerald-700 text-white shadow-xs"
                                                onClick={() => setLabOrderDialogOpen(true)}
                                            >
                                                <FlaskConical className="mr-1.5 h-3.5 w-3.5" />
                                                Order Lab Tests
                                            </Button>
                                        )}
                                        {canOrderRadiology && (
                                            <Button
                                                size="sm"
                                                className="bg-blue-600 hover:bg-blue-700 text-white shadow-xs"
                                                onClick={() => setRadiologyDialogOpen(true)}
                                            >
                                                <Scan className="mr-1.5 h-3.5 w-3.5" />
                                                Order Ultrasound
                                            </Button>
                                        )}
                                        {canPrescribe && (
                                            <Button
                                                size="sm"
                                                className="bg-purple-600 hover:bg-purple-700 text-white shadow-xs"
                                                onClick={() => setPrescriptionDialogOpen(true)}
                                            >
                                                <Pill className="mr-1.5 h-3.5 w-3.5" />
                                                Write Medication
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            </Alert>
                        ) : (
                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/20 p-3">
                                <div>
                                    <h3 className="text-sm font-semibold flex items-center gap-2">
                                        Maternity Clinical Orders
                                        <Badge variant="outline" className="text-[10px]">NHIF Insured</Badge>
                                    </h3>
                                    <p className="text-xs text-muted-foreground">Order diagnostic lab investigations, obstetric ultrasound, and write medications to pharmacy.</p>
                                </div>
                                <div className="flex items-center gap-2">
                                    {canOrderLabs && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="border-emerald-600 text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300"
                                            onClick={() => setLabOrderDialogOpen(true)}
                                        >
                                            <FlaskConical className="mr-1.5 h-3.5 w-3.5" />
                                            Order Lab Tests
                                        </Button>
                                    )}
                                    {canOrderRadiology && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="border-blue-600 text-blue-700 hover:bg-blue-50 dark:text-blue-300"
                                            onClick={() => setRadiologyDialogOpen(true)}
                                        >
                                            <Scan className="mr-1.5 h-3.5 w-3.5" />
                                            Order Ultrasound
                                        </Button>
                                    )}
                                    {canPrescribe && (
                                        <Button
                                            size="sm"
                                            className="bg-purple-600 hover:bg-purple-700 text-white shadow-xs"
                                            onClick={() => setPrescriptionDialogOpen(true)}
                                        >
                                            <Pill className="mr-1.5 h-3.5 w-3.5" />
                                            Write Medication
                                        </Button>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* Laboratory Orders Card */}
                        <Card>
                            <CardHeader className="pb-3">
                                <div className="flex items-center justify-between">
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <FlaskConical className="h-4 w-4 text-emerald-600" />
                                        Maternity Laboratory Orders ({laboratoryOrders.length})
                                    </CardTitle>
                                    {canOrderLabs && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="text-xs h-7 gap-1 border-emerald-500/40 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-50"
                                            onClick={() => setLabOrderDialogOpen(true)}
                                        >
                                            <Plus className="h-3 w-3" />
                                            New Lab Order
                                        </Button>
                                    )}
                                </div>
                                <CardDescription>Laboratory investigations ordered during this maternity admission</CardDescription>
                            </CardHeader>
                            <CardContent className="p-0">
                                {laboratoryOrders.length > 0 ? (
                                    <Table>
                                        <TableHeader className="text-xs bg-muted/40">
                                            <TableRow>
                                                <TableHead>Order #</TableHead>
                                                <TableHead>Tests Requested</TableHead>
                                                <TableHead>Priority</TableHead>
                                                <TableHead>Ordered By</TableHead>
                                                <TableHead>Status</TableHead>
                                                <TableHead>Results</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody className="text-xs">
                                            {laboratoryOrders.map((order) => (
                                                <TableRow key={order.id}>
                                                    <TableCell className="font-mono font-medium">
                                                        <Link href={route('laboratory.orders.show', order.id)} className="text-primary hover:underline">
                                                            {order.order_number}
                                                        </Link>
                                                        <span className="text-[10px] text-muted-foreground block">
                                                            {new Date(order.ordered_at).toLocaleDateString([], { month: 'short', day: 'numeric' })} · {new Date(order.ordered_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                                        </span>
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex flex-wrap gap-1">
                                                            {order.items?.map((item) => (
                                                                <Badge key={item.id} variant="outline" className="text-[10px]">
                                                                    {item.test_name ?? item.laboratoryTest?.name ?? item.test_code}
                                                                </Badge>
                                                            ))}
                                                        </div>
                                                        {order.clinical_indication && (
                                                            <span className="text-[10px] text-muted-foreground italic mt-0.5 block">
                                                                Indication: {order.clinical_indication}
                                                            </span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge
                                                            variant={order.priority === 'stat' ? 'destructive' : order.priority === 'urgent' ? 'default' : 'outline'}
                                                            className="capitalize text-[10px]"
                                                        >
                                                            {order.priority}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {order.ordered_by?.name ?? 'Nurse / Clinician'}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge variant="outline" className="capitalize text-[10px]">
                                                            {order.status.replace('_', ' ')}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex flex-col gap-0.5">
                                                            {order.items?.map((item) => (
                                                                item.result ? (
                                                                    <Link
                                                                        key={item.id}
                                                                        href={route('laboratory.results.show', item.result.id)}
                                                                        className="text-[10px] text-emerald-600 font-semibold hover:underline flex items-center gap-1"
                                                                    >
                                                                        <Check className="h-3 w-3" />
                                                                        {item.result.status}
                                                                    </Link>
                                                                ) : (
                                                                    <span key={item.id} className="text-[10px] text-muted-foreground">
                                                                        Pending
                                                                    </span>
                                                                )
                                                            ))}
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                ) : (
                                    <div className="p-6 text-center text-xs text-muted-foreground">
                                        <FlaskConical className="h-7 w-7 text-muted-foreground/40 mx-auto mb-2" />
                                        <p className="font-medium text-neutral-700 dark:text-neutral-300">No laboratory investigations ordered yet.</p>
                                        <p className="text-[11px] mt-0.5">Click "Order Lab Tests" above to order routine antenatal screening, CBC, blood grouping, or urgent diagnostic tests.</p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Maternity Radiology / Ultrasound Orders Card */}
                        <Card>
                            <CardHeader className="pb-3">
                                <div className="flex items-center justify-between">
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <Scan className="h-4 w-4 text-blue-600" />
                                        Maternity Radiology & Ultrasound Scans ({radiologyOrders.length})
                                    </CardTitle>
                                    {canOrderRadiology && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="text-xs h-7 gap-1 border-blue-500/40 text-blue-700 dark:text-blue-300 hover:bg-blue-50"
                                            onClick={() => setRadiologyDialogOpen(true)}
                                        >
                                            <Plus className="h-3 w-3" />
                                            New Ultrasound Order
                                        </Button>
                                    )}
                                </div>
                                <CardDescription>Obstetric ultrasound, pelvic imaging, and radiology investigations requested for this admission</CardDescription>
                            </CardHeader>
                            <CardContent className="p-0">
                                {radiologyOrders.length > 0 ? (
                                    <Table>
                                        <TableHeader className="text-xs bg-muted/40">
                                            <TableRow>
                                                <TableHead>Order #</TableHead>
                                                <TableHead>Procedures Requested</TableHead>
                                                <TableHead>Priority</TableHead>
                                                <TableHead>Ordered By</TableHead>
                                                <TableHead>Order Status</TableHead>
                                                <TableHead>Findings / Report</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody className="text-xs">
                                            {radiologyOrders.map((order) => (
                                                <TableRow key={order.id}>
                                                    <TableCell className="font-mono font-medium">
                                                        <span>{order.order_number}</span>
                                                        <span className="text-[10px] text-muted-foreground block">
                                                            {new Date(order.ordered_at).toLocaleDateString([], { month: 'short', day: 'numeric' })} · {new Date(order.ordered_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                                        </span>
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex flex-wrap gap-1">
                                                            {order.items?.map((item) => (
                                                                <div key={item.id} className="flex items-center gap-1">
                                                                    <Badge variant="outline" className="text-[10px]">
                                                                        {item.procedure_name ?? item.radiologyProcedure?.name ?? item.procedure_code}
                                                                    </Badge>
                                                                    {item.modality_snapshot && (
                                                                        <Badge className="bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300 text-[9px] uppercase">
                                                                            {item.modality_snapshot}
                                                                        </Badge>
                                                                    )}
                                                                </div>
                                                            ))}
                                                        </div>
                                                        {order.clinical_indication && (
                                                            <span className="text-[10px] text-muted-foreground italic mt-0.5 block">
                                                                Indication: {order.clinical_indication}
                                                            </span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge
                                                            variant={order.priority === 'stat' ? 'destructive' : order.priority === 'urgent' ? 'default' : 'outline'}
                                                            className="capitalize text-[10px]"
                                                        >
                                                            {order.priority}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {order.ordered_by?.name ?? 'Nurse / Clinician'}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge variant="outline" className="capitalize text-[10px]">
                                                            {order.status.replace('_', ' ')}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex flex-col gap-1.5">
                                                            {order.items?.map((item: any) => {
                                                                const appt = item.active_radiology_appointment ?? item.activeRadiologyAppointment;
                                                                const rpt = appt?.report;
                                                                return rpt ? (
                                                                    <div key={item.id} className="flex items-center gap-1.5">
                                                                        <Button
                                                                            type="button"
                                                                            size="sm"
                                                                            variant="outline"
                                                                            className="h-6 px-2 text-[11px] font-medium gap-1 border-emerald-300 text-emerald-800 bg-emerald-50 hover:bg-emerald-100 hover:text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200 dark:border-emerald-800 shadow-none"
                                                                            onClick={() => {
                                                                                setSelectedReport(rpt);
                                                                                setSelectedReportProcedure({
                                                                                    procedure_name: item.radiology_procedure?.name ?? item.radiologyProcedure?.name ?? item.procedure_name,
                                                                                    procedure_code: item.radiology_procedure?.code ?? item.radiologyProcedure?.code ?? item.procedure_code,
                                                                                    modality: item.radiology_procedure?.modality ?? item.radiologyProcedure?.modality ?? appt?.modality,
                                                                                    body_site_snapshot: item.body_site_snapshot,
                                                                                    appointment_id: appt?.id,
                                                                                    images: appt?.images ?? [],
                                                                                });
                                                                            }}
                                                                        >
                                                                            <Eye className="h-3 w-3 text-emerald-600 dark:text-emerald-400" />
                                                                            <span>View Report</span>
                                                                            <span className="text-[9px] uppercase font-bold px-1 py-0.5 rounded bg-emerald-200/80 dark:bg-emerald-800/80 text-emerald-900 dark:text-emerald-100 ml-0.5">
                                                                                {rpt.status ?? 'Result'}
                                                                            </span>
                                                                        </Button>
                                                                    </div>
                                                                ) : (
                                                                    <span key={item.id} className="text-[10px] text-muted-foreground flex items-center gap-1">
                                                                        <Clock className="h-2.5 w-2.5" />
                                                                        Awaiting Scan
                                                                    </span>
                                                                );
                                                            })}
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                ) : (
                                    <div className="p-6 text-center text-xs text-muted-foreground">
                                        <Scan className="h-7 w-7 text-muted-foreground/40 mx-auto mb-2" />
                                        <p className="font-medium text-neutral-700 dark:text-neutral-300">No ultrasound or radiology scans ordered yet.</p>
                                        <p className="text-[11px] mt-0.5">Click "New Ultrasound Order" above to request obstetric scans, fetal assessment, or pelvic ultrasound.</p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Pharmacy Medication Orders Card */}
                        <Card>
                            <CardHeader className="pb-3">
                                <div className="flex items-center justify-between">
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <Pill className="h-4 w-4 text-purple-600" />
                                        Prescriptions Written to Pharmacy ({prescriptionOrders.length})
                                    </CardTitle>
                                    {canPrescribe && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="text-xs h-7 gap-1 border-purple-500/40 text-purple-700 dark:text-purple-300 hover:bg-purple-50"
                                            onClick={() => setPrescriptionDialogOpen(true)}
                                        >
                                            <Plus className="h-3 w-3" />
                                            Write Medication
                                        </Button>
                                    )}
                                </div>
                                <CardDescription>Prescriptions submitted to the hospital pharmacy for dispensing and administration</CardDescription>
                            </CardHeader>
                            <CardContent className="p-0">
                                {prescriptionOrders.length > 0 ? (
                                    <Table>
                                        <TableHeader className="text-xs bg-muted/40">
                                            <TableRow>
                                                <TableHead>Rx #</TableHead>
                                                <TableHead>Medications & Dosage</TableHead>
                                                <TableHead>Route / Frequency</TableHead>
                                                <TableHead>Qty</TableHead>
                                                <TableHead>Prescribed By</TableHead>
                                                <TableHead>Pharmacy Status</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody className="text-xs">
                                            {prescriptionOrders.map((rx) => (
                                                <TableRow key={rx.id}>
                                                    <TableCell className="font-mono font-medium">
                                                        <span>{rx.order_number}</span>
                                                        <span className="text-[10px] text-muted-foreground block">
                                                            {new Date(rx.created_at).toLocaleDateString([], { month: 'short', day: 'numeric' })} · {new Date(rx.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                                        </span>
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex flex-col gap-1">
                                                            {rx.items?.map((item) => (
                                                                <div key={item.id} className="text-xs">
                                                                    <span className="font-semibold text-neutral-800 dark:text-neutral-200">
                                                                        {item.medication_name_snapshot ?? item.medication?.generic_name}
                                                                    </span>
                                                                    <span className="text-muted-foreground text-[10px] ml-1.5">
                                                                        {item.dose} {item.dose_unit ?? ''}
                                                                    </span>
                                                                    {item.instructions && (
                                                                        <span className="text-[10px] text-muted-foreground block italic">
                                                                            "{item.instructions}"
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            ))}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex flex-col gap-1">
                                                            {rx.items?.map((item) => (
                                                                <span key={item.id} className="text-xs capitalize text-muted-foreground">
                                                                    {item.route} · {item.frequency.replace(/_/g, ' ')}
                                                                </span>
                                                            ))}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex flex-col gap-1">
                                                            {rx.items?.map((item) => (
                                                                <span key={item.id} className="text-xs font-mono">
                                                                    {item.quantity} {item.quantity_unit ?? ''}
                                                                </span>
                                                            ))}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {rx.prescriber?.name ?? 'Nurse / Prescriber'}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge
                                                            variant={
                                                                rx.verification?.status === 'verified'
                                                                    ? 'default'
                                                                    : rx.status === 'active'
                                                                      ? 'outline'
                                                                      : 'secondary'
                                                            }
                                                            className="capitalize text-[10px]"
                                                        >
                                                            {rx.verification?.status === 'verified' ? 'Verified (Ready to Dispense)' : rx.status}
                                                        </Badge>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                ) : (
                                    <div className="p-6 text-center text-xs text-muted-foreground">
                                        <Pill className="h-7 w-7 text-muted-foreground/40 mx-auto mb-2" />
                                        <p className="font-medium text-neutral-700 dark:text-neutral-300">No prescriptions written for this admission yet.</p>
                                        <p className="text-[11px] mt-0.5">Click "Write Medication" above to prescribe uterotonics, IV fluids, analgesics, or antibiotics to pharmacy.</p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                            {/* Latest Maternal Vital Signs */}
                            <Card>
                                <CardHeader className="pb-3">
                                    <div className="flex items-center justify-between">
                                        <CardTitle className="text-base flex items-center gap-2">
                                            <Activity className="h-4 w-4 text-emerald-600" />
                                            Latest Maternal Observations
                                        </CardTitle>
                                        {displayVital?.recorded_at && (
                                            <span className="text-xs text-muted-foreground">
                                                {new Date(displayVital.recorded_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })} ({new Date(displayVital.recorded_at).toLocaleDateString([], { month: 'short', day: 'numeric' })})
                                            </span>
                                        )}
                                    </div>
                                    <CardDescription>Routine nursing vital signs recorded in ward</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    {displayVital ? (
                                        <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                            <div className="rounded-lg border p-3">
                                                <span className="text-xs text-muted-foreground">Blood Pressure</span>
                                                <div className="text-base font-bold">
                                                    {displayVital.systolic_bp && displayVital.diastolic_bp
                                                        ? `${displayVital.systolic_bp}/${displayVital.diastolic_bp} mmHg`
                                                        : '—'}
                                                </div>
                                            </div>
                                            <div className="rounded-lg border p-3">
                                                <span className="text-xs text-muted-foreground">Heart Rate / Pulse</span>
                                                <div className="text-base font-bold">
                                                    {displayVital.pulse_rate ? `${displayVital.pulse_rate} bpm` : '—'}
                                                </div>
                                            </div>
                                            <div className="rounded-lg border p-3">
                                                <span className="text-xs text-muted-foreground">Temperature</span>
                                                <div className="text-base font-bold">
                                                    {displayVital.temperature ? `${displayVital.temperature} °C` : '—'}
                                                </div>
                                            </div>
                                            <div className="rounded-lg border p-3">
                                                <span className="text-xs text-muted-foreground">SpO2 (Oxygen)</span>
                                                <div className="text-base font-bold">
                                                    {displayVital.oxygen_saturation ? `${displayVital.oxygen_saturation} %` : '—'}
                                                </div>
                                            </div>
                                            <div className="rounded-lg border p-3">
                                                <span className="text-xs text-muted-foreground">Respiratory Rate</span>
                                                <div className="text-base font-bold">
                                                    {displayVital.respiratory_rate ? `${displayVital.respiratory_rate} /min` : '—'}
                                                </div>
                                            </div>
                                            <div className="rounded-lg border p-3">
                                                <span className="text-xs text-muted-foreground">Pain Score</span>
                                                <div className="text-base font-bold">
                                                    {displayVital.pain_score !== null && displayVital.pain_score !== undefined
                                                        ? `${displayVital.pain_score} / 10`
                                                        : '—'}
                                                </div>
                                            </div>
                                        </div>
                                    ) : (
                                        <p className="text-xs text-muted-foreground italic">No vital signs documented yet for this admission.</p>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Active Clinical Escalations & NHIF Status */}
                            <div className="flex flex-col gap-6">
                                <Card>
                                    <CardHeader className="pb-3">
                                        <CardTitle className="text-base flex items-center gap-2">
                                            <AlertTriangle className="h-4 w-4 text-amber-600" />
                                            Active Clinical Alerts ({clinicalAlerts?.length ?? 0})
                                        </CardTitle>
                                        <CardDescription>Auto-escalated and nursing maternity alerts requiring clinician review</CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        {clinicalAlerts && clinicalAlerts.length > 0 ? (
                                            <div className="flex flex-col gap-2">
                                                {clinicalAlerts.map((alert: any) => (
                                                    <div key={alert.id} className="rounded-lg border border-red-200 bg-red-50/60 p-3 dark:border-red-900/40 dark:bg-red-950/20">
                                                        <div className="flex items-center justify-between">
                                                            <span className="text-xs font-bold text-red-900 dark:text-red-200">{alert.reason}</span>
                                                            <Badge variant="destructive" className="text-[10px]">OPEN</Badge>
                                                        </div>
                                                        <p className="mt-1 text-[11px] text-muted-foreground">
                                                            Raised: {new Date(alert.raised_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })} • Source: {alert.source}
                                                        </p>
                                                    </div>
                                                ))}
                                            </div>
                                        ) : (
                                            <div className="flex items-center gap-2 text-xs text-emerald-700 dark:text-emerald-300">
                                                <CheckCircle2 className="h-4 w-4 text-emerald-600" />
                                                <span>No open maternity escalations. Labour and observations stable.</span>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardHeader className="pb-3">
                                        <CardTitle className="text-base flex items-center gap-2">
                                            <ShieldAlert className="h-4 w-4 text-indigo-600" />
                                            Admission & Insurance Status
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="text-xs space-y-2">
                                        <div className="flex justify-between border-b pb-1">
                                            <span className="text-muted-foreground">Admission Type / Status</span>
                                            <span className="font-semibold capitalize">{admission.admission_type ?? 'Inpatient'} • {admission.status}</span>
                                        </div>
                                        <div className="flex justify-between border-b pb-1">
                                            <span className="text-muted-foreground">Insurance / Payment</span>
                                            <span className="font-semibold">{insurance ? `NHIF (${insurance.membership_number})` : 'Self-Pay / Cash'}</span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">Admitted At</span>
                                            <span className="font-semibold">{admission.admitted_at ? new Date(admission.admitted_at).toLocaleString() : 'Active'}</span>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>
                        </div>

                        {/* Inpatient Medication Administration (MAR) Tasks */}
                        <Card>
                            <CardHeader className="pb-3">
                                <div className="flex items-center justify-between">
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <Pill className="h-4 w-4 text-purple-600" />
                                        Inpatient Medication Treatment Sheet ({medications?.length ?? 0} Active Orders)
                                    </CardTitle>
                                    <Button size="sm" variant="outline" asChild>
                                        <Link href={route('inpatient.medications.worklist', admission.id)}>Open Full MAR Worklist</Link>
                                    </Button>
                                </div>
                                <CardDescription>Physician medication orders and doses scheduled for administration</CardDescription>
                            </CardHeader>
                            <CardContent className="p-0">
                                {medications && medications.length > 0 ? (
                                    <Table>
                                        <TableHeader className="text-xs bg-muted/40">
                                            <TableRow>
                                                <TableHead>Medication</TableHead>
                                                <TableHead>Dose / Route</TableHead>
                                                <TableHead>Frequency</TableHead>
                                                <TableHead>Status</TableHead>
                                                <TableHead>Latest MAR Action</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody className="text-xs">
                                            {medications.map((order: any) => (
                                                <TableRow key={order.id}>
                                                    <TableCell className="font-medium">
                                                        {order.medication?.generic_name ?? 'Medication'}
                                                        <span className="text-muted-foreground text-[10px] block">
                                                            {order.medication?.strength} {order.medication?.form}
                                                        </span>
                                                    </TableCell>
                                                    <TableCell>{order.dose} • {order.route}</TableCell>
                                                    <TableCell>{order.frequency}</TableCell>
                                                    <TableCell>
                                                        <Badge variant="outline" className="capitalize text-[10px]">{order.status}</Badge>
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {order.mar_entries && order.mar_entries.length > 0 ? (
                                                            <span>
                                                                {order.mar_entries[0].status} at{' '}
                                                                {new Date(order.mar_entries[0].scheduled_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                                            </span>
                                                        ) : (
                                                            'Scheduled'
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                ) : (
                                    <p className="p-4 text-xs text-muted-foreground italic">No active inpatient medication treatment orders for this admission.</p>
                                )}
                            </CardContent>
                        </Card>
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
                        {Object.keys(profileForm.errors).length > 0 && (
                            <Alert variant="destructive">
                                <AlertCircle className="h-4 w-4" />
                                <AlertTitle>Please correct the following errors:</AlertTitle>
                                <AlertDescription className="text-xs space-y-0.5 mt-1">
                                    {Object.entries(profileForm.errors).map(([field, err]) => (
                                        <div key={field}>• <span className="font-semibold capitalize">{field.replace(/_/g, ' ')}:</span> {err}</div>
                                    ))}
                                </AlertDescription>
                            </Alert>
                        )}
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
                                {COMMON_RISK_FACTORS.map((factor, idx) => {
                                    const riskFactors = Array.isArray(profileForm.data.risk_factors)
                                        ? profileForm.data.risk_factors
                                        : [];
                                    const checked = riskFactors.includes(factor);
                                    const factorId = `risk_factor_${idx}`;
                                    return (
                                        <div key={factor} className="flex items-center gap-2">
                                            <Checkbox
                                                id={factorId}
                                                checked={checked}
                                                onCheckedChange={(isChecked) => {
                                                    const updated = isChecked
                                                        ? [...riskFactors, factor]
                                                        : riskFactors.filter((f: string) => f !== factor);
                                                    profileForm.setData('risk_factors', updated);
                                                }}
                                            />
                                            <Label htmlFor={factorId} className="cursor-pointer text-xs font-normal">
                                                {factor}
                                            </Label>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div>
                                <Label htmlFor="care_stage">Care Stage</Label>
                                <Select
                                    value={profileForm.data.care_stage}
                                    onValueChange={(v) => profileForm.setData('care_stage', v)}
                                >
                                    <SelectTrigger id="care_stage">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(constants.care_stages ?? {}).map(([stageKey, stageLabel]) => (
                                            <SelectItem key={stageKey} value={stageKey}>
                                                {stageLabel}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label htmlFor="midwife">Assigned Nurse / Midwife</Label>
                                <Select
                                    value={profileForm.data.assigned_midwife_id}
                                    onValueChange={(v) => profileForm.setData('assigned_midwife_id', v)}
                                >
                                    <SelectTrigger id="midwife">
                                        <SelectValue placeholder="Select Midwife" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {practitioners.map((p) => {
                                            const val = String(p.user_id ?? p.staff?.user_id ?? p.id);
                                            return (
                                                <SelectItem key={p.id} value={val}>
                                                    {p.name ?? (p.staff ? `${p.staff.first_name} ${p.staff.last_name}` : `Practitioner #${p.id}`)}
                                                </SelectItem>
                                            );
                                        })}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label htmlFor="clinician">Responsible Clinician</Label>
                                <Select
                                    value={profileForm.data.primary_clinician_id}
                                    onValueChange={(v) => profileForm.setData('primary_clinician_id', v)}
                                >
                                    <SelectTrigger id="clinician">
                                        <SelectValue placeholder="Select Clinician" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {practitioners.map((p) => {
                                            const val = String(p.user_id ?? p.staff?.user_id ?? p.id);
                                            return (
                                                <SelectItem key={p.id} value={val}>
                                                    {p.name ?? (p.staff ? `${p.staff.first_name} ${p.staff.last_name}` : `Practitioner #${p.id}`)}
                                                </SelectItem>
                                            );
                                        })}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        {(eligibility && !eligibility.eligible || profileForm.data.clinical_exception_reason) && (
                            <div className="rounded-lg border border-amber-300 bg-amber-50/70 p-3 dark:border-amber-900/40 dark:bg-amber-950/20">
                                <Label htmlFor="clinical_exception" className="text-amber-900 dark:text-amber-200 font-semibold flex items-center gap-1.5">
                                    <ShieldAlert className="h-4 w-4 text-amber-600" />
                                    Clinical Exception Authorization Justification
                                </Label>
                                <p className="text-xs text-amber-800/80 dark:text-amber-300/80 mb-2">
                                    Patient is recorded as {admission.patient?.gender ?? 'non-female'}. A documented clinical reason and authorized assessment are required by hospital policy to initiate maternity care.
                                </p>
                                <Textarea
                                    id="clinical_exception"
                                    value={profileForm.data.clinical_exception_reason}
                                    onChange={(e) => profileForm.setData('clinical_exception_reason', e.target.value)}
                                    placeholder="Document clinical evaluation, pregnancy confirmation, or demographic correction details..."
                                    rows={2}
                                    className="border-amber-300 bg-white dark:bg-neutral-900"
                                />
                                {profileForm.errors.clinical_exception_reason && (
                                    <span className="text-xs text-red-600">{profileForm.errors.clinical_exception_reason}</span>
                                )}
                            </div>
                        )}

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
                        <div className="flex items-center gap-1.5">
                            <Checkbox
                                id="del_vitamin_k"
                                checked={deliveryForm.data.vitamin_k_given}
                                onCheckedChange={(c) => deliveryForm.setData('vitamin_k_given', Boolean(c))}
                            />
                            <Label htmlFor="del_vitamin_k" className="cursor-pointer text-xs font-normal">
                                Vitamin K Given
                            </Label>
                        </div>
                        <div className="flex items-center gap-1.5">
                            <Checkbox
                                id="del_eye_ointment"
                                checked={deliveryForm.data.eye_ointment_given}
                                onCheckedChange={(c) => deliveryForm.setData('eye_ointment_given', Boolean(c))}
                            />
                            <Label htmlFor="del_eye_ointment" className="cursor-pointer text-xs font-normal">
                                Tetracycline Eye Ointment
                            </Label>
                        </div>
                        <div className="flex items-center gap-1.5">
                            <Checkbox
                                id="del_resuscitation"
                                checked={deliveryForm.data.resuscitation_required}
                                onCheckedChange={(c) => deliveryForm.setData('resuscitation_required', Boolean(c))}
                            />
                            <Label htmlFor="del_resuscitation" className="cursor-pointer text-xs font-normal">
                                Resuscitation Required
                            </Label>
                        </div>
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

    {/* MODAL 4: STAGE TRANSITION DIALOG */}
    <Dialog open={stageDialogOpen} onOpenChange={setStageDialogOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Update Maternity Care Stage</DialogTitle>
                        <DialogDescription>
                            Transition episode #{profile?.episode_number} to the next stage of obstetric care.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submitStageTransition} className="flex flex-col gap-4">
                        <div>
                            <Label htmlFor="new_stage">Target Care Stage</Label>
                            <Select
                                value={stageForm.data.care_stage}
                                onValueChange={(v) => stageForm.setData('care_stage', v)}
                            >
                                <SelectTrigger id="new_stage">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {constants.care_stages && Object.entries(constants.care_stages).map(([k, label]) => (
                                        <SelectItem key={k} value={k}>
                                            {label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {stageForm.errors.care_stage && (
                                <span className="text-xs text-red-600">{stageForm.errors.care_stage}</span>
                            )}
                        </div>

                        <div>
                            <Label htmlFor="stage_notes">Transition & Handover Notes</Label>
                            <Textarea
                                id="stage_notes"
                                value={stageForm.data.notes}
                                onChange={(e) => stageForm.setData('notes', e.target.value)}
                                placeholder="Document clinical observations, shift handover, or reason for stage advance..."
                                rows={3}
                            />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setStageDialogOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={stageForm.processing} className="bg-pink-600 hover:bg-pink-700">
                                Confirm Transition
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* MODAL 5: ORDER LABORATORY TESTS DIALOG */}
            <Dialog open={labOrderDialogOpen} onOpenChange={setLabOrderDialogOpen}>
                <DialogContent className="max-w-xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-base">
                            <FlaskConical className="h-5 w-5 text-emerald-600" />
                            Order Laboratory Tests — Maternity
                        </DialogTitle>
                        <DialogDescription>
                            Select diagnostic investigations for {admission.patient?.first_name} {admission.patient?.last_name}.
                            <span className="font-semibold block mt-1 text-xs text-neutral-700 dark:text-neutral-300">
                                Payment Mode: {insurance ? `NHIF (${insurance.membership_number})` : 'Self-Pay / Cash (Direct Inpatient Billing)'}
                            </span>
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submitLabOrder} className="flex flex-col gap-4">
                        <div>
                            <Label className="text-xs font-semibold">Select Tests *</Label>
                            <p className="text-[11px] text-muted-foreground mb-1.5">Search and select one or more tests to include in this order.</p>
                            <LaboratoryOrderTestSelector
                                selected={selectedTests}
                                onChange={setSelectedTests}
                                clinicalEncounterId={activeEncounterId}
                            />
                            {labOrderForm.errors.test_ids && (
                                <p className="text-xs text-destructive mt-1">{labOrderForm.errors.test_ids}</p>
                            )}
                        </div>

                        <div>
                            <Label htmlFor="lab_priority" className="text-xs font-semibold">Priority</Label>
                            <Select
                                value={labOrderForm.data.priority}
                                onValueChange={(v) => labOrderForm.setData('priority', v as LaboratoryOrderPriority)}
                            >
                                <SelectTrigger id="lab_priority" className="mt-1">
                                    <SelectValue placeholder="Select priority" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="routine">Routine</SelectItem>
                                    <SelectItem value="urgent">Urgent</SelectItem>
                                    <SelectItem value="stat">Stat (Immediate / Emergency)</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label htmlFor="clinical_indication" className="text-xs font-semibold">Clinical Indication</Label>
                            <div className="flex flex-wrap gap-1.5 my-1.5">
                                {[
                                    'Routine Maternity Admission Screening',
                                    'Pre-Eclampsia Workup',
                                    'Severe Anemia Assessment',
                                    'Suspected Chorioamnionitis / Sepsis',
                                    'Pre-Operative C-Section Workup',
                                ].map((preset) => (
                                    <Button
                                        key={preset}
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="h-6 text-[10px] px-2"
                                        onClick={() => labOrderForm.setData('clinical_indication', preset)}
                                    >
                                        {preset}
                                    </Button>
                                ))}
                            </div>
                            <Input
                                id="clinical_indication"
                                value={labOrderForm.data.clinical_indication}
                                onChange={(e) => labOrderForm.setData('clinical_indication', e.target.value)}
                                placeholder="e.g. Obstructed labour screening, high BP on partograph..."
                            />
                        </div>

                        <div>
                            <Label htmlFor="lab_notes" className="text-xs font-semibold">Special Instructions / Notes</Label>
                            <Textarea
                                id="lab_notes"
                                value={labOrderForm.data.notes}
                                onChange={(e) => labOrderForm.setData('notes', e.target.value)}
                                placeholder="Optional specimen or clinical notes for the laboratory team..."
                                rows={2}
                            />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setLabOrderDialogOpen(false)}>
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={labOrderForm.processing || selectedTests.length === 0}
                                className="bg-emerald-600 hover:bg-emerald-700 text-white"
                            >
                                Place Lab Order ({selectedTests.length})
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* MODAL 6: WRITE MEDICATION ORDER TO PHARMACY DIALOG */}
            <Dialog open={prescriptionDialogOpen} onOpenChange={setPrescriptionDialogOpen}>
                <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-base">
                            <Pill className="h-5 w-5 text-purple-600" />
                            Write Medication to Pharmacy — Maternity
                        </DialogTitle>
                        <DialogDescription>
                            Prescribe medications for {admission.patient?.first_name} {admission.patient?.last_name}.
                            <span className="font-semibold block mt-1 text-xs text-neutral-700 dark:text-neutral-300">
                                Payment Mode: {insurance ? `NHIF (${insurance.membership_number})` : 'Self-Pay / Cash (Direct Inpatient Billing)'}
                            </span>
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submitPrescription} className="flex flex-col gap-4">
                        <div className="flex flex-col gap-3">
                            {prescriptionItems.map((item, index) => (
                                <div key={index} className="rounded-lg border p-3 bg-muted/20 relative space-y-3">
                                    <div className="flex items-center justify-between">
                                        <span className="text-xs font-semibold text-neutral-800 dark:text-neutral-200">
                                            Medication #{index + 1}
                                        </span>
                                        {prescriptionItems.length > 1 && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="h-6 w-6 p-0 text-destructive hover:bg-destructive/10"
                                                onClick={() => {
                                                    setPrescriptionItems(prescriptionItems.filter((_, i) => i !== index));
                                                }}
                                            >
                                                <Trash2 className="h-3.5 w-3.5" />
                                            </Button>
                                        )}
                                    </div>

                                    <div>
                                        <Label className="text-xs">Medication Search *</Label>
                                        <div className="mt-1">
                                            <MedicationSearch
                                                selected={item.medication}
                                                onSelect={(med) => {
                                                    const updated = [...prescriptionItems];
                                                    updated[index].medication = med;
                                                    setPrescriptionItems(updated);
                                                }}
                                                onClear={() => {
                                                    const updated = [...prescriptionItems];
                                                    updated[index].medication = null;
                                                    setPrescriptionItems(updated);
                                                }}
                                                clinicalEncounterId={activeEncounterId}
                                            />
                                        </div>
                                    </div>

                                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                        <div>
                                            <Label className="text-[11px]">Dose *</Label>
                                            <Input
                                                value={item.dose}
                                                onChange={(e) => {
                                                    const updated = [...prescriptionItems];
                                                    updated[index].dose = e.target.value;
                                                    setPrescriptionItems(updated);
                                                }}
                                                placeholder="e.g. 10, 500"
                                                className="h-8 text-xs"
                                                required
                                            />
                                        </div>
                                        <div>
                                            <Label className="text-[11px]">Unit</Label>
                                            <Input
                                                value={item.dose_unit}
                                                onChange={(e) => {
                                                    const updated = [...prescriptionItems];
                                                    updated[index].dose_unit = e.target.value;
                                                    setPrescriptionItems(updated);
                                                }}
                                                placeholder="e.g. IU, mg, ml"
                                                className="h-8 text-xs"
                                            />
                                        </div>
                                        <div>
                                            <Label className="text-[11px]">Route *</Label>
                                            <Select
                                                value={item.route}
                                                onValueChange={(v) => {
                                                    const updated = [...prescriptionItems];
                                                    updated[index].route = v as PrescriptionRoute;
                                                    setPrescriptionItems(updated);
                                                }}
                                            >
                                                <SelectTrigger className="h-8 text-xs">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="oral">Oral</SelectItem>
                                                    <SelectItem value="intravenous">Intravenous (IV)</SelectItem>
                                                    <SelectItem value="intramuscular">Intramuscular (IM)</SelectItem>
                                                    <SelectItem value="subcutaneous">Subcutaneous (SC)</SelectItem>
                                                    <SelectItem value="rectal">Rectal</SelectItem>
                                                    <SelectItem value="topical">Topical</SelectItem>
                                                    <SelectItem value="inhalation">Inhalation</SelectItem>
                                                    <SelectItem value="other">Other</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div>
                                            <Label className="text-[11px]">Frequency *</Label>
                                            <Select
                                                value={item.frequency}
                                                onValueChange={(v) => {
                                                    const updated = [...prescriptionItems];
                                                    updated[index].frequency = v as PrescriptionFrequency;
                                                    setPrescriptionItems(updated);
                                                }}
                                            >
                                                <SelectTrigger className="h-8 text-xs">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="stat">Stat (Once immediately)</SelectItem>
                                                    <SelectItem value="once_daily">Once Daily</SelectItem>
                                                    <SelectItem value="twice_daily">Twice Daily (BID)</SelectItem>
                                                    <SelectItem value="three_times_daily">Three Times Daily (TID)</SelectItem>
                                                    <SelectItem value="four_times_daily">Four Times Daily (QID)</SelectItem>
                                                    <SelectItem value="every_4_hours">Every 4 Hours</SelectItem>
                                                    <SelectItem value="every_6_hours">Every 6 Hours</SelectItem>
                                                    <SelectItem value="as_needed">As Needed (PRN)</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    </div>

                                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                        <div>
                                            <Label className="text-[11px]">Duration</Label>
                                            <Input
                                                type="number"
                                                min="1"
                                                value={item.duration}
                                                onChange={(e) => {
                                                    const updated = [...prescriptionItems];
                                                    updated[index].duration = parseInt(e.target.value) || 1;
                                                    setPrescriptionItems(updated);
                                                }}
                                                className="h-8 text-xs"
                                            />
                                        </div>
                                        <div>
                                            <Label className="text-[11px]">Duration Unit</Label>
                                            <Select
                                                value={item.duration_unit}
                                                onValueChange={(v) => {
                                                    const updated = [...prescriptionItems];
                                                    updated[index].duration_unit = v as any;
                                                    setPrescriptionItems(updated);
                                                }}
                                            >
                                                <SelectTrigger className="h-8 text-xs">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="days">Days</SelectItem>
                                                    <SelectItem value="weeks">Weeks</SelectItem>
                                                    <SelectItem value="months">Months</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="col-span-2">
                                            <Label className="text-[11px]">Quantity to Dispense *</Label>
                                            <Input
                                                type="number"
                                                min="1"
                                                value={item.quantity}
                                                onChange={(e) => {
                                                    const updated = [...prescriptionItems];
                                                    updated[index].quantity = parseInt(e.target.value) || 1;
                                                    setPrescriptionItems(updated);
                                                }}
                                                className="h-8 text-xs"
                                                required
                                            />
                                        </div>
                                    </div>

                                    <div>
                                        <Label className="text-[11px]">Nursing Administration Instructions</Label>
                                        <Input
                                            value={item.instructions}
                                            onChange={(e) => {
                                                const updated = [...prescriptionItems];
                                                updated[index].instructions = e.target.value;
                                                setPrescriptionItems(updated);
                                            }}
                                            placeholder="e.g. Infuse in 500ml RL over 4 hrs, or stat after birth of placenta"
                                            className="h-8 text-xs"
                                        />
                                    </div>
                                </div>
                            ))}

                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="w-full text-xs border-dashed gap-1"
                                onClick={() => {
                                    setPrescriptionItems([
                                        ...prescriptionItems,
                                        {
                                            medication: null,
                                            dose: '',
                                            dose_unit: '',
                                            route: 'oral',
                                            frequency: 'once_daily',
                                            duration: 1,
                                            duration_unit: 'days',
                                            quantity: 1,
                                            instructions: '',
                                        },
                                    ]);
                                }}
                            >
                                <Plus className="h-3.5 w-3.5" />
                                Add Another Medication
                            </Button>
                        </div>

                        <div>
                            <Label htmlFor="rx_clinical_notes" className="text-xs font-semibold">Clinical Notes / Indication</Label>
                            <Textarea
                                id="rx_clinical_notes"
                                value={prescriptionForm.data.clinical_notes}
                                onChange={(e) => prescriptionForm.setData('clinical_notes', e.target.value)}
                                placeholder="Document reason for prescription, e.g. active phase labour augmentation, PPH prevention..."
                                rows={2}
                            />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setPrescriptionDialogOpen(false)}>
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={prescriptionForm.processing || prescriptionItems.every((i) => i.medication === null)}
                                className="bg-purple-600 hover:bg-purple-700 text-white"
                            >
                                Send Prescription to Pharmacy ({prescriptionItems.filter((i) => i.medication !== null).length})
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* MODAL 7: ORDER RADIOLOGY & ULTRASOUND DIALOG */}
            <Dialog open={radiologyDialogOpen} onOpenChange={setRadiologyDialogOpen}>
                <DialogContent className="max-w-xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-base">
                            <Scan className="h-5 w-5 text-blue-600" />
                            Order Ultrasound & Radiology — Maternity
                        </DialogTitle>
                        <DialogDescription>
                            Request diagnostic obstetric scans or imaging for {admission.patient?.first_name} {admission.patient?.last_name}.
                            <span className="font-semibold block mt-1 text-xs text-neutral-700 dark:text-neutral-300">
                                Payment Mode: {insurance ? `NHIF (${insurance.membership_number})` : 'Self-Pay / Cash (Direct Inpatient Billing)'}
                            </span>
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submitRadiologyOrder} className="flex flex-col gap-4">
                        {radiologyForm.hasErrors && (
                            <div className="rounded-md bg-destructive/10 p-3 text-xs text-destructive space-y-1">
                                {Object.values(radiologyForm.errors).map((err, idx) => (
                                    <p key={idx}>{err}</p>
                                ))}
                            </div>
                        )}

                        {/* Procedure Selection */}
                        <div>
                            <Label className="text-xs font-semibold">Select Radiology / Ultrasound Procedures *</Label>
                            <p className="text-[11px] text-muted-foreground mb-1.5">Select from active hospital imaging procedures:</p>

                            <div className="max-h-48 overflow-y-auto rounded-lg border p-2 space-y-1.5 bg-muted/20">
                                {radiologyProcedures.map((proc) => {
                                    const isSelected = radiologyForm.data.procedure_ids.includes(proc.id);
                                    const procId = `rad_proc_${proc.id}`;
                                    return (
                                        <div
                                            key={proc.id}
                                            className={`flex items-center justify-between p-2 rounded text-xs transition-colors ${
                                                isSelected
                                                    ? 'bg-blue-100/70 border border-blue-300 text-blue-900 dark:bg-blue-950/40 dark:border-blue-800 dark:text-blue-200'
                                                    : 'hover:bg-muted/50 bg-white dark:bg-neutral-900'
                                            }`}
                                        >
                                            <div className="flex items-center gap-2 flex-1">
                                                <Checkbox
                                                    id={procId}
                                                    checked={isSelected}
                                                    onCheckedChange={(checked) => {
                                                        const updated = checked
                                                            ? [...radiologyForm.data.procedure_ids, proc.id]
                                                            : radiologyForm.data.procedure_ids.filter((id) => id !== proc.id);
                                                        radiologyForm.setData('procedure_ids', updated);
                                                    }}
                                                />
                                                <Label
                                                    htmlFor={procId}
                                                    className="flex-1 cursor-pointer font-normal text-xs flex items-center justify-between"
                                                >
                                                    <div>
                                                        <span className="font-semibold">{proc.name}</span>
                                                        <span className="text-[10px] text-muted-foreground ml-2">({proc.code})</span>
                                                    </div>
                                                    <Badge variant="outline" className="text-[9px] uppercase">
                                                        {proc.modality ?? 'Radiology'}
                                                    </Badge>
                                                </Label>
                                            </div>
                                        </div>
                                    );
                                })}
                                {radiologyProcedures.length === 0 && (
                                    <p className="text-xs text-muted-foreground p-3 text-center">No active radiology procedures found.</p>
                                )}
                            </div>
                            {radiologyForm.errors.procedure_ids && (
                                <p className="text-xs text-destructive mt-1">{radiologyForm.errors.procedure_ids}</p>
                            )}
                        </div>

                        <div>
                            <Label htmlFor="rad_priority" className="text-xs font-semibold">Priority</Label>
                            <Select
                                value={radiologyForm.data.priority}
                                onValueChange={(v) => radiologyForm.setData('priority', v as RadiologyOrderPriority)}
                            >
                                <SelectTrigger id="rad_priority" className="mt-1">
                                    <SelectValue placeholder="Select priority" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="routine">Routine</SelectItem>
                                    <SelectItem value="urgent">Urgent</SelectItem>
                                    <SelectItem value="stat">Stat (Emergency)</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label htmlFor="rad_clinical_indication" className="text-xs font-semibold">Clinical Indication</Label>
                            <div className="flex flex-wrap gap-1.5 my-1.5">
                                {[
                                    'Assess Fetal Viability & Presentation',
                                    'AFI / Liquor Volume Evaluation',
                                    'Suspected Placenta Previa / Abruption',
                                    'Estimate Fetal Weight / Rule Out IUGR',
                                    'Emergency Obstetric Assessment',
                                ].map((preset) => (
                                    <Button
                                        key={preset}
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="h-6 text-[10px] px-2"
                                        onClick={() => radiologyForm.setData('clinical_indication', preset)}
                                    >
                                        {preset}
                                    </Button>
                                ))}
                            </div>
                            <Input
                                id="rad_clinical_indication"
                                value={radiologyForm.data.clinical_indication}
                                onChange={(e) => radiologyForm.setData('clinical_indication', e.target.value)}
                                placeholder="e.g. Determine presentation, rule out placenta previa..."
                            />
                        </div>

                        <div>
                            <Label htmlFor="rad_notes" className="text-xs font-semibold">Special Notes for Radiographer</Label>
                            <Textarea
                                id="rad_notes"
                                value={radiologyForm.data.notes}
                                onChange={(e) => radiologyForm.setData('notes', e.target.value)}
                                placeholder="Optional clinical background or patient mobility restrictions..."
                                rows={2}
                            />
                        </div>

                        {insurance && (
                            <div className="flex items-center gap-2 rounded-lg border p-2.5 bg-muted/20">
                                <Checkbox
                                    id="rad_send_preapproval"
                                    checked={radiologyForm.data.send_preapproval}
                                    onCheckedChange={(c) => radiologyForm.setData('send_preapproval', Boolean(c))}
                                />
                                <Label htmlFor="rad_send_preapproval" className="text-xs cursor-pointer">
                                    Automatically submit NHIF Pre-Approval request for this scan
                                </Label>
                            </div>
                        )}

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setRadiologyDialogOpen(false)}>
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={radiologyForm.processing || radiologyForm.data.procedure_ids.length === 0}
                                className="bg-blue-600 hover:bg-blue-700 text-white"
                            >
                                Place Radiology Order ({radiologyForm.data.procedure_ids.length})
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* View Radiology Report Dialog */}
            <ViewRadiologyReportDialog
                open={selectedReport !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setSelectedReport(null);
                        setSelectedReportProcedure(null);
                    }
                }}
                report={selectedReport}
                procedure={selectedReportProcedure}
                patientName={admission?.patient ? [admission.patient.first_name, admission.patient.middle_name, admission.patient.last_name].filter(Boolean).join(' ') : undefined}
                patientNumber={admission?.patient?.patient_number}
            />
        </AppLayout>
    );
}
