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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { MedicationSearch } from '@/components/care-plan/medication-search';
import { LaboratoryOrderTestSelector } from '@/components/laboratory/laboratory-order-test-selector';
import { ViewRadiologyReportDialog, type ViewRadiologyReportData, type ViewRadiologyReportProcedure } from '@/components/radiology/view-radiology-report-dialog';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type LaboratoryOrder, type LaboratoryOrderPriority, type LaboratoryTestSearchResult } from '@/types/laboratory';
import { type MedicationSearchResult } from '@/types/medication';
import { type PrescriptionFrequency, type PrescriptionOrder, type PrescriptionRoute } from '@/types/prescription';
import { type RadiologyOrder, type RadiologyOrderPriority, type RadiologyProcedure } from '@/types/radiology';
import { Head, Link, router, useForm } from '@inertiajs/react';

function formatDateTime(value: string | null | undefined): string {
    if (!value) return '—';
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

function parseBlood(str: string | null | undefined): { abo: string; rh: string } {
    if (!str) return { abo: '', rh: '+' };
    const trimmed = str.trim().toUpperCase();
    const rh = trimmed.endsWith('-') ? '-' : '+';
    const abo = trimmed.replace(/[+-]/g, '');
    return { abo: ['A', 'B', 'AB', 'O'].includes(abo) ? abo : '', rh };
}
import {
    Activity,
    AlertCircle,
    AlertTriangle,
    ArrowLeft,
    Baby,
    Banknote,
    Bed,
    Calendar,
    Check,
    CheckCircle2,
    Clock,
    Eye,
    FileSpreadsheet,
    FileText,
    FlaskConical,
    HeartPulse,
    History,
    Info,
    LineChart,
    Pill,
    Plus,
    Ruler,
    Save,
    Scale,
    Scan,
    ShieldAlert,
    ShieldCheck,
    Sparkles,
    Stethoscope,
    Trash2,
    User,
    UserCheck,
} from 'lucide-react';
import { useState } from 'react';

interface PatientData {
    id: number;
    patient_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    phone: string | null;
    date_of_birth: string | null;
    gender: string;
    blood_group?: string | null;
    rhesus?: string | null;
}

interface ReceptionVisitData {
    id: number;
    visit_number: string;
    patient_id: number;
    department_id: number;
    visit_type: string;
    visit_purpose: string | null;
    status: string;
    maternity_status: string | null;
    created_at: string;
    patient: PatientData;
    department?: { id: number; name: string; code?: string } | null;
    practitioner?: {
        staff?: { first_name: string; last_name: string; job_title?: string } | null;
    } | null;
}

interface ClinicalEncounterData {
    id: number;
    encounter_number?: string;
    status: string;
    started_at: string | null;
}

interface MaternityProfileData {
    id: number;
    episode_number?: string;
    patient_id: number;
    reception_visit_id?: number | null;
    clinical_encounter_id?: number | null;
    gravida: number;
    para: number;
    living_children: number;
    abortions: number;
    last_menstrual_period: string | null;
    estimated_date_of_delivery: string | null;
    gestational_age_weeks: number | null;
    risk_classification: string;
    risk_factors: string[] | null;
    previous_pregnancy_history: string | null;
    medical_history: string | null;
    obstetric_history: string | null;
    allergies: string | null;
    current_medications: string | null;
    presentation: string | null;
    membrane_status: string | null;
    membrane_ruptured_at?: string | null;
    delivery_record?: unknown;
    blood_group: string | null;
    rhesus: string | null;
    hiv_status: string | null;
    vdrl_rpr_status: string | null;
    labour_stage?: string;
    labour_onset_at?: string | null;
    labour_onset_type?: string | null;
    cervix_effacement_pct?: number | null;
    care_stage: string;
    care_pathway: string;
    notes: string | null;
    follow_up_date?: string | null;
    follow_up_purpose?: string | null;
    follow_up_notes?: string | null;
    assigned_midwife?: { id: number; UserName: string } | null;
    primary_clinician?: { id: number; UserName: string } | null;
    recorded_by?: { id: number; UserName: string } | null;
    created_at: string;
}

interface VitalSignReading {
    id: number;
    clinical_encounter_id?: number | null;
    reception_visit_id?: number | null;
    maternity_profile_id?: number | null;
    recorded_at: string;
    measured_at: string | null;
    temperature_c: number | null;
    systolic_mmhg: number | null;
    diastolic_mmhg: number | null;
    pulse_bpm: number | null;
    respiratory_rate_bpm: number | null;
    oxygen_saturation_pct: number | null;
    weight_kg: number | null;
    height_cm: number | null;
    bmi: number | null;
    urine_protein: string | null;
    urine_glucose: string | null;
    urine_acetone: string | null;
    blood_glucose_mmol: number | null;
    observation_notes: string | null;
    recorded_by?: { id: number; UserName: string } | null;
}

interface FetalAssessmentReading {
    id: number;
    maternity_profile_id: number;
    patient_id: number;
    reception_visit_id?: number | null;
    clinical_encounter_id?: number | null;
    assessed_at: string;
    gestational_age_weeks: number | null;
    number_of_fetuses: number;
    fetus_identifier: string;
    fetal_heart_rate: number | null;
    fetal_movements: string | null;
    fundal_height_cm: number | null;
    presentation: string | null;
    lie: string | null;
    estimated_fetal_weight_grams: number | null;
    ultrasound_findings: string | null;
    clinical_notes: string | null;
    assessed_by?: { id: number; UserName: string } | null;
}

interface PartographEntryData {
    id: number;
    maternity_profile_id: number;
    patient_id: number;
    admission_id?: number | null;
    clinical_encounter_id?: number | null;
    reception_visit_id?: number | null;
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
    is_emergency: boolean;
    emergency_reason?: string | null;
    recorded_by?: { id: number; UserName: string } | null;
}

interface NewbornGrowthRecordData {
    id: number;
    patient_id: number;
    recorded_at: string;
    age_days: number;
    weight_kg: number;
    length_height_cm: number;
    head_circumference_cm: number;
    temperature_c?: number | null;
    feeding_method: string;
    immunizations_given?: string[] | null;
    developmental_milestones?: string | null;
    growth_reference_standard: string;
    clinical_notes?: string | null;
    recorded_by?: { id: number; UserName: string } | null;
}

interface DeliveryRecordData {
    id: number;
    delivered_at: string;
    delivery_mode: string;
    indication?: string | null;
    baby_outcome: string;
    baby_gender: string;
    baby_name?: string | null;
    birth_order: number;
    birth_weight_grams?: number | null;
    birth_length_cm?: number | null;
    head_circumference_cm?: number | null;
    apgar_1min?: number | null;
    apgar_5min?: number | null;
    apgar_10min?: number | null;
    resuscitation_required: boolean;
    resuscitation_notes?: string | null;
    placenta_delivered_at?: string | null;
    placenta_mode?: string | null;
    placenta_complete?: boolean;
    blood_loss_ml?: number | null;
    postpartum_hemorrhage: boolean;
    perineal_status?: string | null;
    suture_materials_used?: string | null;
    oxytocic_given?: string | null;
    vitamin_k_given?: boolean;
    eye_ointment_given?: boolean;
    bcg_given?: boolean;
    polio_0_given?: boolean;
    baby_patient_id?: number | null;
    baby_patient?: {
        id: number;
        patient_number: string;
        first_name: string;
        last_name: string;
        gender: string;
        newborn_growth_records?: NewbornGrowthRecordData[];
    } | null;
    conducted_by?: { id: number; UserName: string } | null;
    assisted_by?: { id: number; UserName: string } | null;
    recorded_by?: { id: number; UserName: string } | null;
    notes?: string | null;
}

interface MotherAssessmentProps {
    visit: ReceptionVisitData;
    encounter: ClinicalEncounterData;
    profile: MaternityProfileData;
    vitalSigns: VitalSignReading[];
    fetalAssessments: FetalAssessmentReading[];
    partographEntries: PartographEntryData[];
    previousProfiles: MaternityProfileData[];
    practitioners: Array<{ id: number; name: string; type: string }>;
    laboratoryOrders?: LaboratoryOrder[];
    prescriptionOrders?: PrescriptionOrder[];
    radiologyOrders?: RadiologyOrder[];
    radiologyProcedures?: RadiologyProcedure[];
    patientInsurance?: {
        id: number;
        membership_number?: string;
        insurance_provider?: { name: string } | null;
        insuranceProvider?: { name: string } | null;
    } | null;
    canOrderLabs?: boolean;
    canPrescribe?: boolean;
    canOrderRadiology?: boolean;
    riskClassifications: Record<string, string>;
    maternityStatuses: Record<string, string>;
    urineOptions: Record<string, string>;
    fetalPresentations: Record<string, string>;
    fetalLies: Record<string, string>;
    fetalMovements: Record<string, string>;
    labourStages: Record<string, string>;
    labourOnsetTypes: Record<string, string>;
    amnioticFluidStates: Record<string, string>;
    mouldingGrades: Record<string, string>;
    contractionDurations: Record<string, string>;
    deliveryRecords?: DeliveryRecordData[];
    deliveryModes?: Record<string, string>;
    perinealStatuses?: Record<string, string>;
    babyOutcomes?: Record<string, string>;
    feedingMethods?: Record<string, string>;
}

const COMMON_RISK_FACTORS = [
    'Previous Caesarean Section',
    'History of Pre-eclampsia/Eclampsia',
    'Gestational Diabetes',
    'Severe Anemia (< 8 g/dl)',
    'Multiple Gestation',
    'Antepartum Hemorrhage',
    'Advanced Maternal Age (>= 35)',
    'Adolescent Pregnancy (< 18)',
    'History of Stillbirth/Perinatal Loss',
    'Known Cardiac / Renal Disease',
    'HIV Positive on ART',
    'Rh Negative Sensitization Risk',
];

export default function MotherAssessment({
    visit,
    encounter,
    profile,
    vitalSigns,
    fetalAssessments = [],
    partographEntries = [],
    previousProfiles,
    practitioners,
    laboratoryOrders = [],
    prescriptionOrders = [],
    radiologyOrders = [],
    radiologyProcedures = [],
    patientInsurance = null,
    canOrderLabs = true,
    canPrescribe = true,
    canOrderRadiology = true,
    riskClassifications,
    maternityStatuses,
    urineOptions,
    fetalPresentations = {},
    fetalLies = {},
    fetalMovements = {},
    labourStages = {},
    labourOnsetTypes = {},
    amnioticFluidStates = {},
    mouldingGrades = {},
    contractionDurations = {},
    deliveryRecords = [],
    deliveryModes = {},
    perinealStatuses = {},
    babyOutcomes = {},
    feedingMethods = {},
}: MotherAssessmentProps) {
    const [statusDialogOpen, setStatusDialogOpen] = useState(false);
    const [labOrderDialogOpen, setLabOrderDialogOpen] = useState(false);
    const [prescriptionDialogOpen, setPrescriptionDialogOpen] = useState(false);
    const [radiologyDialogOpen, setRadiologyDialogOpen] = useState(false);
    const [ultrasoundOnlyFilter, setUltrasoundOnlyFilter] = useState(false);
    const [selectedReport, setSelectedReport] = useState<ViewRadiologyReportData | null>(null);
    const [selectedReportProcedure, setSelectedReportProcedure] = useState<ViewRadiologyReportProcedure | null>(null);
    const [selectedFetusFilter, setSelectedFetusFilter] = useState<string>('all');
    const SECTION_VALUES = ['assessment', 'vitals', 'orders', 'fetal', 'labour', 'delivery', 'newborn', 'history', 'follow-up'];
    const [activeTab, setActiveTab] = useState<string>(() => {
        if (typeof window === 'undefined') return 'assessment';
        const tab = new URLSearchParams(window.location.search).get('tab');
        return tab && SECTION_VALUES.includes(tab) ? tab : 'assessment';
    });
    const changeTab = (value: string) => {
        setActiveTab(value);
        const url = new URL(window.location.href);
        url.searchParams.set('tab', value);
        window.history.replaceState(window.history.state, '', url);
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Maternity Queue', href: '/maternity/queue' },
        { title: `Visit #${visit.visit_number} Mother Assessment`, href: `/maternity/visits/${visit.id}/mother-assessment` },
    ];

    const patientName = `${visit.patient.first_name} ${visit.patient.middle_name ? visit.patient.middle_name + ' ' : ''}${visit.patient.last_name}`;

    // Blood group resolution & "capture once" state
    const parsedPatientBlood = parseBlood(visit.patient.blood_group);
    const initialAbo = profile.blood_group || parsedPatientBlood.abo || '';
    const initialRh = profile.rhesus || parsedPatientBlood.rh || '+';
    const hasKnownBloodGroup = Boolean(visit.patient.blood_group || profile.blood_group);
    const [isEditingBlood, setIsEditingBlood] = useState(false);

    // Profile Form
    const profileForm = useForm({
        gravida: profile.gravida ?? 1,
        para: profile.para ?? 0,
        living_children: profile.living_children ?? 0,
        abortions: profile.abortions ?? 0,
        last_menstrual_period: profile.last_menstrual_period ? profile.last_menstrual_period.substring(0, 10) : '',
        estimated_date_of_delivery: profile.estimated_date_of_delivery ? profile.estimated_date_of_delivery.substring(0, 10) : '',
        gestational_age_weeks: profile.gestational_age_weeks ?? '',
        risk_classification: profile.risk_classification ?? 'low_risk',
        risk_factors: Array.isArray(profile.risk_factors) ? profile.risk_factors : [],
        previous_pregnancy_history: profile.previous_pregnancy_history ?? '',
        medical_history: profile.medical_history ?? '',
        obstetric_history: profile.obstetric_history ?? '',
        allergies: profile.allergies ?? '',
        current_medications: profile.current_medications ?? '',
        presentation: profile.presentation ?? 'cephalic',
        membrane_status: profile.membrane_status ?? 'intact',
        blood_group: initialAbo,
        rhesus: initialRh,
        hiv_status: profile.hiv_status ?? 'negative',
        vdrl_rpr_status: profile.vdrl_rpr_status ?? 'non_reactive',
        notes: profile.notes ?? '',
    });

    // Clean display string for blood group (e.g. "A-", "O+", or "Not Tested")
    const displayBloodGroup = (profileForm.data.blood_group || initialAbo)
        ? `${profileForm.data.blood_group || initialAbo}${profileForm.data.rhesus || initialRh}`
        : (visit.patient.blood_group || 'Not Tested');

    // Helper: recalculate EDD & Gestational Age on LMP change
    const handleLmpChange = (lmpVal: string) => {
        profileForm.setData((prev) => {
            const next = { ...prev, last_menstrual_period: lmpVal };
            if (lmpVal) {
                const lmpDate = new Date(lmpVal);
                if (!isNaN(lmpDate.getTime())) {
                    // Naegele's rule: +280 days
                    const eddDate = new Date(lmpDate.getTime() + 280 * 24 * 60 * 60 * 1000);
                    next.estimated_date_of_delivery = eddDate.toISOString().substring(0, 10);

                    // Gestational age in weeks
                    const now = new Date();
                    const diffDays = Math.max(0, Math.floor((now.getTime() - lmpDate.getTime()) / (1000 * 60 * 60 * 24)));
                    next.gestational_age_weeks = (diffDays / 7).toFixed(1);
                }
            }
            return next;
        });
    };

    const toggleRiskFactor = (factor: string) => {
        const current = [...profileForm.data.risk_factors];
        const idx = current.indexOf(factor);
        if (idx >= 0) {
            current.splice(idx, 1);
        } else {
            current.push(factor);
        }
        profileForm.setData('risk_factors', current);
    };

    const handleProfileSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        profileForm.post(route('maternity.mother-profile.update', visit.id), {
            preserveScroll: true,
        });
    };

    // Vital Sign Observation Form
    const vitalForm = useForm({
        systolic_mmhg: '',
        diastolic_mmhg: '',
        pulse_bpm: '',
        temperature_c: '',
        respiratory_rate_bpm: '',
        oxygen_saturation_pct: '',
        weight_kg: '',
        height_cm: '',
        urine_protein: 'nil',
        urine_glucose: 'nil',
        urine_acetone: 'nil',
        blood_glucose_mmol: '',
        observation_notes: '',
        measured_at: new Date().toISOString().slice(0, 16),
    });

    const handleVitalSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        vitalForm.post(route('maternity.vital-signs.store', visit.id), {
            preserveScroll: true,
            onSuccess: () => {
                vitalForm.reset();
                vitalForm.setData('measured_at', new Date().toISOString().slice(0, 16));
            },
        });
    };

    // Dedicated Fetal Assessment & Monitoring Form (Phase 4)
    const fetalForm = useForm({
        gestational_age_weeks: profile.gestational_age_weeks ?? '',
        number_of_fetuses: 1,
        fetus_identifier: 'Singleton',
        fetal_heart_rate: '',
        fetal_movements: 'normal',
        fundal_height_cm: '',
        presentation: 'cephalic',
        lie: 'longitudinal',
        estimated_fetal_weight_grams: '',
        ultrasound_findings: '',
        clinical_notes: '',
        assessed_at: new Date().toISOString().slice(0, 16),
    });

    const handleFetalSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        fetalForm.post(route('maternity.fetal-assessments.store', visit.id), {
            preserveScroll: true,
            onSuccess: () => {
                fetalForm.reset('fetal_heart_rate', 'fundal_height_cm', 'estimated_fetal_weight_grams', 'ultrasound_findings', 'clinical_notes');
                fetalForm.setData('assessed_at', new Date().toISOString().slice(0, 16));
            },
        });
    };

    // Labour Status & Onset Form (Phase 5)
    const labourStatusForm = useForm({
        labour_stage: profile.labour_stage ?? 'latent_phase',
        labour_onset_at: profile.labour_onset_at ? profile.labour_onset_at.slice(0, 16) : '',
        labour_onset_type: profile.labour_onset_type ?? 'spontaneous',
        cervix_effacement_pct: profile.cervix_effacement_pct ?? '',
        membrane_status: profile.membrane_status ?? 'intact',
        membrane_ruptured_at: profile.membrane_ruptured_at ? profile.membrane_ruptured_at.slice(0, 16) : '',
        notes: '',
    });

    const handleLabourStatusSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        labourStatusForm.post(route('maternity.labour-status.update', visit.id), {
            preserveScroll: true,
        });
    };

    // Partograph Observation Entry Form (Phase 5)
    const partographForm = useForm({
        recorded_at: new Date().toISOString().slice(0, 16),
        cervical_dilation_cm: '',
        descent_of_head: '',
        fetal_heart_rate: '',
        amniotic_fluid: 'I',
        moulding: '0',
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
        is_emergency: false,
        emergency_reason: '',
    });

    const handlePartographSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        partographForm.post(route('maternity.partograph.store', visit.id), {
            preserveScroll: true,
            onSuccess: () => {
                partographForm.reset('cervical_dilation_cm', 'descent_of_head', 'fetal_heart_rate', 'contractions_per_10min', 'notes', 'drugs_iv_fluids');
                partographForm.setData('recorded_at', new Date().toISOString().slice(0, 16));
            },
        });
    };

    // Status Dialog Form
    const statusForm = useForm({
        maternity_status: visit.maternity_status ?? 'assessment_in_progress',
        notes: '',
    });

    const handleStatusSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        statusForm.post(route('maternity.queue.status.update', visit.id), {
            preserveScroll: true,
            onSuccess: () => setStatusDialogOpen(false),
        });
    };

    // Delivery Documentation Form (Phase 7)
    const deliveryForm = useForm({
        delivered_at: new Date().toISOString().slice(0, 16),
        delivery_mode: 'spontaneous_vaginal',
        indication: '',
        baby_outcome: 'live_birth',
        baby_gender: 'female',
        baby_name: '',
        birth_order: (deliveryRecords?.length ?? 0) + 1,
        birth_weight_grams: '',
        birth_length_cm: '',
        head_circumference_cm: '',
        apgar_1min: '',
        apgar_5min: '',
        apgar_10min: '',
        resuscitation_required: false,
        resuscitation_notes: '',
        placenta_delivered_at: '',
        placenta_mode: 'spontaneous',
        placenta_complete: true,
        blood_loss_ml: '',
        perineal_status: 'intact',
        suture_materials_used: '',
        oxytocic_given: 'Oxytocin 10 IU IM',
        vitamin_k_given: true as boolean,
        eye_ointment_given: true as boolean,
        bcg_given: false as boolean,
        polio_0_given: false as boolean,
        notes: '',
        conducted_by: '',
        assisted_by: '',
    });

    const handleDeliverySubmit = (e: React.FormEvent) => {
        e.preventDefault();
        deliveryForm.post(route('maternity.deliveries.store', visit.id), {
            preserveScroll: true,
            onSuccess: () => {
                deliveryForm.reset('birth_weight_grams', 'birth_length_cm', 'head_circumference_cm', 'notes');
            },
        });
    };

    // Newborn Growth Monitoring Form (Phase 8)
    const liveBabies = (deliveryRecords ?? []).filter((d) => d.baby_patient_id && d.baby_patient);
    const [selectedBabyId, setSelectedBabyId] = useState<number | null>(
        liveBabies.length > 0 ? (liveBabies[0].baby_patient_id ?? null) : null
    );

    const growthForm = useForm({
        recorded_at: new Date().toISOString().slice(0, 16),
        weight_kg: '',
        length_height_cm: '',
        head_circumference_cm: '',
        temperature_c: '',
        feeding_method: 'exclusive_breastfeeding',
        immunizations_given: [] as string[],
        developmental_milestones: '',
        clinical_notes: '',
    });

    const handleGrowthSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedBabyId) return;
        growthForm.post(route('maternity.newborn-growth.store', selectedBabyId), {
            preserveScroll: true,
            onSuccess: () => {
                growthForm.reset('weight_kg', 'length_height_cm', 'head_circumference_cm', 'temperature_c', 'developmental_milestones', 'clinical_notes');
            },
        });
    };

    // Follow-up Plan Form (Phase 9)
    const followUpForm = useForm({
        follow_up_date: profile.follow_up_date ? profile.follow_up_date.slice(0, 10) : '',
        follow_up_purpose: profile.follow_up_purpose ?? 'anc_routine',
        follow_up_notes: profile.follow_up_notes ?? '',
        maternity_status: visit.maternity_status ?? 'follow_up_required',
    });

    const handleFollowUpSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        followUpForm.post(route('maternity.follow-up.update', visit.id), {
            preserveScroll: true,
        });
    };

    // Radiology / Ultrasound Order Form
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
        radiologyForm.post(route('maternity.radiology-orders.store', visit.id), {
            preserveScroll: true,
            onSuccess: () => {
                setRadiologyDialogOpen(false);
                radiologyForm.reset();
                changeTab('orders');
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
        labOrderForm.post(route('maternity.laboratory-orders.store', visit.id), {
            preserveScroll: true,
            onSuccess: () => {
                setLabOrderDialogOpen(false);
                setSelectedTests([]);
                labOrderForm.reset();
                changeTab('orders');
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
        prescriptionForm.post(route('maternity.prescriptions.store', visit.id), {
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
                changeTab('orders');
            },
        });
    };

    const totalOrdersCount =
        (prescriptionOrders?.length ?? 0) + (laboratoryOrders?.length ?? 0) + (radiologyOrders?.length ?? 0);

    const filteredRadiologyProcedures = ultrasoundOnlyFilter
        ? radiologyProcedures.filter((p) => {
            const mod = (p.modality ?? '').toLowerCase();
            const name = (p.name ?? '').toLowerCase();
            return mod.includes('ultrasound') || mod === 'us' || name.includes('ultrasound') || name.includes('obstetric');
        })
        : radiologyProcedures;

    // Computed BMI preview
    const weightNum = parseFloat(vitalForm.data.weight_kg);
    const heightNum = parseFloat(vitalForm.data.height_cm);
    const computedBmi =
        !isNaN(weightNum) && !isNaN(heightNum) && heightNum > 0
            ? (weightNum / ((heightNum / 100) * (heightNum / 100))).toFixed(1)
            : null;

    const sections: {
        group: string;
        items: { value: string; label: string; icon: typeof Stethoscope; color: string; count?: number }[];
    }[] = [
            {
                group: 'Assessment',
                items: [
                    { value: 'assessment', label: 'Mother & Pregnancy', icon: Stethoscope, color: 'text-pink-600' },
                    { value: 'vitals', label: 'Vital Signs', icon: HeartPulse, color: 'text-rose-600', count: vitalSigns.length },
                ],
            },
            {
                group: 'Clinical Orders',
                items: [
                    { value: 'orders', label: 'Orders & Rx', icon: Pill, color: 'text-purple-600', count: totalOrdersCount },
                ],
            },
            {
                group: 'Monitoring',
                items: [
                    { value: 'fetal', label: 'Fetal Monitoring', icon: Baby, color: 'text-indigo-600', count: fetalAssessments.length },
                    { value: 'labour', label: 'Labour & Partograph', icon: Activity, color: 'text-amber-600', count: partographEntries?.length ?? 0 },
                ],
            },
            {
                group: 'Delivery & Newborn',
                items: [
                    { value: 'delivery', label: 'Delivery Record', icon: Sparkles, color: 'text-emerald-600', count: deliveryRecords?.length ?? 0 },
                    { value: 'newborn', label: 'Newborn Growth', icon: Baby, color: 'text-cyan-600', count: liveBabies.length },
                ],
            },
            {
                group: 'Records & Plan',
                items: [
                    { value: 'history', label: 'Obstetric History', icon: History, color: 'text-purple-600', count: previousProfiles.length },
                    { value: 'follow-up', label: 'Follow-up Plan', icon: Calendar, color: 'text-emerald-600' },
                ],
            },
        ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Mother Assessment - ${patientName}`} />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                {/* ── Top Header Banner ── */}
                <div className="rounded-xl border border-pink-200 bg-gradient-to-r from-pink-50 via-rose-50 to-purple-50 p-5 shadow-sm dark:border-pink-900/60 dark:from-pink-950/30 dark:via-rose-950/20 dark:to-purple-950/30">
                    <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                        <div className="space-y-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <Link
                                    href={route('maternity.queue')}
                                    className="inline-flex items-center text-xs text-pink-700 hover:underline dark:text-pink-300 font-medium"
                                >
                                    <ArrowLeft className="mr-1 h-3.5 w-3.5" />
                                    Back to Maternity Queue
                                </Link>
                                <span className="text-muted-foreground">•</span>
                                <Badge variant="outline" className="border-pink-300 text-pink-800 dark:border-pink-800 dark:text-pink-200 bg-white/70 dark:bg-pink-950/50">
                                    Visit #{visit.visit_number}
                                </Badge>
                                {profile.episode_number && (
                                    <Badge variant="secondary" className="bg-purple-100 text-purple-800 dark:bg-purple-950 dark:text-purple-300">
                                        Episode #{profile.episode_number}
                                    </Badge>
                                )}
                                <Badge className="bg-pink-600 text-white hover:bg-pink-700">
                                    {maternityStatuses[visit.maternity_status ?? 'waiting_for_assessment'] ?? visit.maternity_status}
                                </Badge>
                            </div>

                            <h1 className="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2">
                                <Baby className="h-6 w-6 text-pink-600" />
                                {patientName}
                            </h1>

                            <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                <span>Patient #: <strong className="text-foreground">{visit.patient.patient_number}</strong></span>
                                <span>Gender: <strong className="text-foreground">{visit.patient.gender}</strong></span>
                                <span>Phone: <strong className="text-foreground">{visit.patient.phone ?? '—'}</strong></span>
                                <span>DOB: <strong className="text-foreground">{visit.patient.date_of_birth ?? '—'}</strong></span>
                                <span>Blood Group: <strong className="text-pink-700 dark:text-pink-300">{displayBloodGroup}</strong></span>
                                <span>Encounter #: <strong className="text-foreground">{encounter.id}</strong></span>
                            </div>
                        </div>

                        {/* Top Action Buttons */}
                        <div className="flex flex-wrap items-center gap-2">
                            {canPrescribe && (
                                <Button
                                    size="sm"
                                    className="bg-purple-600 hover:bg-purple-700 text-white shadow-xs"
                                    onClick={() => setPrescriptionDialogOpen(true)}
                                >
                                    <Pill className="mr-1.5 h-4 w-4" />
                                    Write Medication
                                </Button>
                            )}
                            {canOrderLabs && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="border-emerald-300 text-emerald-800 hover:bg-emerald-50 dark:border-emerald-800 dark:text-emerald-300"
                                    onClick={() => setLabOrderDialogOpen(true)}
                                >
                                    <FlaskConical className="mr-1.5 h-4 w-4 text-emerald-600" />
                                    Order Lab
                                </Button>
                            )}
                            {canOrderRadiology && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="border-blue-300 text-blue-800 hover:bg-blue-50 dark:border-blue-800 dark:text-blue-300"
                                    onClick={() => setRadiologyDialogOpen(true)}
                                >
                                    <Scan className="mr-1.5 h-4 w-4 text-blue-600" />
                                    Order Ultrasound
                                </Button>
                            )}
                            <Button
                                size="sm"
                                variant="outline"
                                className="border-pink-300 text-pink-700 hover:bg-pink-100 dark:border-pink-800 dark:text-pink-300"
                                onClick={() => setStatusDialogOpen(true)}
                            >
                                <Clock className="mr-1.5 h-4 w-4" />
                                Update Queue Status
                            </Button>

                            <Button
                                size="sm"
                                variant="outline"
                                className="border-amber-300 text-amber-800 hover:bg-amber-100 dark:border-amber-800 dark:text-amber-200"
                                asChild
                            >
                                <Link
                                    href={route('admissions.create', {
                                        patient_id: visit.patient_id,
                                        department_id: visit.department_id,
                                        reception_visit_id: visit.id,
                                        clinical_encounter_id: encounter.id,
                                        preset: 'maternity',
                                    })}
                                >
                                    <Bed className="mr-1.5 h-4 w-4" />
                                    Admit Patient (Optional)
                                </Link>
                            </Button>

                            <Button
                                size="sm"
                                variant="ghost"
                                className="text-muted-foreground"
                                asChild
                            >
                                <Link href={route('reception.visits.show', visit.id)}>
                                    Visit Details
                                </Link>
                            </Button>
                        </div>
                    </div>
                </div>

                {/* â”€â”€ Sticky patient context bar â”€â”€ */}
                <div className="sticky top-0 z-20 rounded-lg border border-pink-200 bg-background/95 px-3 py-2 shadow-sm backdrop-blur dark:border-pink-900/50">
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        <span className="flex items-center gap-1.5 text-sm font-bold text-foreground">
                            <Baby className="h-4 w-4 text-pink-600" />
                            {patientName}
                        </span>
                        <span>
                            Parity: <strong className="text-foreground">G{profileForm.data.gravida} P{profileForm.data.para}</strong>
                            <span className="ml-1">(L{profileForm.data.living_children} A{profileForm.data.abortions})</span>
                        </span>
                        <span>
                            GA: <strong className="text-foreground">{profileForm.data.gestational_age_weeks ? `${profileForm.data.gestational_age_weeks} wks` : 'â€”'}</strong>
                        </span>
                        <span>
                            EDD: <strong className="text-foreground">{profileForm.data.estimated_date_of_delivery || 'Not set'}</strong>
                        </span>
                        <span>
                            Blood: <strong className="text-pink-700 dark:text-pink-300">{displayBloodGroup}</strong>
                        </span>
                        <span
                            className={`rounded-full px-2 py-0.5 font-semibold ${profileForm.data.risk_classification === 'high_risk'
                                    ? 'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300'
                                    : profileForm.data.risk_classification === 'moderate_risk'
                                        ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300'
                                        : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300'
                                }`}
                        >
                            {riskClassifications[profileForm.data.risk_classification] ?? profileForm.data.risk_classification}
                            {profileForm.data.risk_factors.length > 0 && ` Â· ${profileForm.data.risk_factors.length} factor(s)`}
                        </span>
                        <span>
                            Last FHR:{' '}
                            <strong className="text-foreground">
                                {fetalAssessments.length > 0 && fetalAssessments[0].fetal_heart_rate ? `${fetalAssessments[0].fetal_heart_rate} bpm` : 'â€”'}
                            </strong>
                        </span>
                        <span>
                            Last BP:{' '}
                            <strong className="text-foreground">
                                {vitalSigns.length > 0 && vitalSigns[0].systolic_mmhg ? `${vitalSigns[0].systolic_mmhg}/${vitalSigns[0].diastolic_mmhg} mmHg` : 'â€”'}
                            </strong>
                        </span>
                    </div>
                </div>

                {/* â”€â”€ Sections: grouped navigation rail (desktop) / wrapping chips (mobile) + content â”€â”€ */}
                <Tabs value={activeTab} onValueChange={changeTab} className="space-y-0">
                    <div className="grid gap-6 lg:grid-cols-[13rem_minmax(0,1fr)]">
                        <nav aria-label="Clinical sections" className="lg:sticky lg:top-16 lg:self-start">
                            <div className="space-y-2 rounded-xl border border-pink-200 bg-pink-50/40 p-2 dark:border-pink-900/50 dark:bg-pink-950/20 lg:space-y-3">
                                {sections.map((group) => (
                                    <div key={group.group}>
                                        <p className="px-2 pb-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{group.group}</p>
                                        <div className="grid grid-cols-2 gap-1 sm:grid-cols-4 lg:grid-cols-1">
                                            {group.items.map((item) => {
                                                const Icon = item.icon;
                                                const active = activeTab === item.value;
                                                return (
                                                    <button
                                                        key={item.value}
                                                        type="button"
                                                        role="tab"
                                                        aria-selected={active}
                                                        onClick={() => changeTab(item.value)}
                                                        className={`flex min-w-0 items-center gap-2 rounded-md px-2.5 py-2 text-left text-sm font-medium transition-colors ${active
                                                                ? 'bg-white text-foreground shadow-sm ring-1 ring-pink-300 dark:bg-gray-900 dark:ring-pink-800'
                                                                : 'text-muted-foreground hover:bg-white/70 hover:text-foreground dark:hover:bg-gray-900/60'
                                                            }`}
                                                    >
                                                        <Icon className={`h-4 w-4 shrink-0 ${item.color}`} />
                                                        <span className="truncate">{item.label}</span>
                                                        {item.count !== undefined && item.count > 0 && (
                                                            <span className="ml-auto rounded-full bg-pink-100 px-1.5 text-[10px] font-semibold text-pink-700 dark:bg-pink-950 dark:text-pink-300">
                                                                {item.count}
                                                            </span>
                                                        )}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </nav>

                        <div className="min-w-0 space-y-6">

                            {/* â•â•â•â•â•â•â•â•â• TAB 1: MOTHER & PREGNANCY ASSESSMENT â•â•â•â•â•â•â•â•â• */}
                            <TabsContent value="assessment" className="space-y-6">
                                <form onSubmit={handleProfileSubmit}>
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        {/* Pregnancy Overview */}
                                        <Card>
                                            <CardHeader>
                                                <CardTitle className="text-base flex items-center gap-2">
                                                    <Baby className="h-5 w-5 text-pink-600" />
                                                    Pregnancy & Parity Context
                                                </CardTitle>
                                                <CardDescription>
                                                    Document current gestation, menstrual history, and parity.
                                                </CardDescription>
                                            </CardHeader>
                                            <CardContent className="space-y-4">
                                                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="gravida">Gravida (G) *</Label>
                                                        <Input
                                                            id="gravida"
                                                            type="number"
                                                            min="1"
                                                            max="25"
                                                            required
                                                            value={profileForm.data.gravida}
                                                            onChange={(e) => profileForm.setData('gravida', parseInt(e.target.value) || 1)}
                                                        />
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="para">Para (P) *</Label>
                                                        <Input
                                                            id="para"
                                                            type="number"
                                                            min="0"
                                                            max="25"
                                                            required
                                                            value={profileForm.data.para}
                                                            onChange={(e) => profileForm.setData('para', parseInt(e.target.value) || 0)}
                                                        />
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="living_children">Living Children</Label>
                                                        <Input
                                                            id="living_children"
                                                            type="number"
                                                            min="0"
                                                            max="25"
                                                            value={profileForm.data.living_children}
                                                            onChange={(e) => profileForm.setData('living_children', parseInt(e.target.value) || 0)}
                                                        />
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="abortions">Abortions/Miscarriages</Label>
                                                        <Input
                                                            id="abortions"
                                                            type="number"
                                                            min="0"
                                                            max="25"
                                                            value={profileForm.data.abortions}
                                                            onChange={(e) => profileForm.setData('abortions', parseInt(e.target.value) || 0)}
                                                        />
                                                    </div>
                                                </div>

                                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="lmp">LMP (Last Menstrual Period)</Label>
                                                        <Input
                                                            id="lmp"
                                                            type="date"
                                                            value={profileForm.data.last_menstrual_period}
                                                            onChange={(e) => handleLmpChange(e.target.value)}
                                                        />
                                                        <p className="text-[11px] text-muted-foreground">Auto-computes EDD & GA</p>
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="edd">EDD (Estimated Due Date)</Label>
                                                        <Input
                                                            id="edd"
                                                            type="date"
                                                            value={profileForm.data.estimated_date_of_delivery}
                                                            onChange={(e) => profileForm.setData('estimated_date_of_delivery', e.target.value)}
                                                        />
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="ga_weeks">Gestational Age (Weeks)</Label>
                                                        <Input
                                                            id="ga_weeks"
                                                            type="number"
                                                            step="0.1"
                                                            placeholder="e.g. 28.4"
                                                            value={profileForm.data.gestational_age_weeks}
                                                            onChange={(e) => profileForm.setData('gestational_age_weeks', e.target.value)}
                                                        />
                                                    </div>
                                                </div>

                                                <div className="grid grid-cols-2 gap-3">
                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="presentation">Fetal Presentation</Label>
                                                        <Select
                                                            value={profileForm.data.presentation || 'cephalic'}
                                                            onValueChange={(val) => profileForm.setData('presentation', val)}
                                                        >
                                                            <SelectTrigger id="presentation">
                                                                <SelectValue placeholder="Presentation" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="cephalic">Cephalic (Vertex)</SelectItem>
                                                                <SelectItem value="breech">Breech</SelectItem>
                                                                <SelectItem value="shoulder">Shoulder / Transverse</SelectItem>
                                                                <SelectItem value="face">Face</SelectItem>
                                                                <SelectItem value="compound">Compound</SelectItem>
                                                            </SelectContent>
                                                        </Select>
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="membrane_status">Membrane Status</Label>
                                                        <Select
                                                            value={profileForm.data.membrane_status || 'intact'}
                                                            onValueChange={(val) => profileForm.setData('membrane_status', val)}
                                                        >
                                                            <SelectTrigger id="membrane_status">
                                                                <SelectValue placeholder="Membrane" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="intact">Intact</SelectItem>
                                                                <SelectItem value="ruptured">Ruptured (SROM)</SelectItem>
                                                                <SelectItem value="artificial_rupture">Artificial Rupture (ARM)</SelectItem>
                                                            </SelectContent>
                                                        </Select>
                                                    </div>
                                                </div>

                                                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                                    {hasKnownBloodGroup && !isEditingBlood ? (
                                                        <div className="col-span-2 rounded-lg border border-emerald-200 bg-emerald-50/70 p-2.5 dark:border-emerald-800 dark:bg-emerald-950/30 flex items-center justify-between">
                                                            <div className="flex items-center gap-2">
                                                                <ShieldCheck className="h-5 w-5 text-emerald-600 shrink-0" />
                                                                <div>
                                                                    <p className="text-[11px] text-muted-foreground font-medium">Maternal Blood Group & Rh</p>
                                                                    <p className="text-sm font-bold text-foreground">
                                                                        {displayBloodGroup} <span className="text-[11px] font-normal text-muted-foreground">({profileForm.data.rhesus === '-' ? 'Rh-Negative' : 'Rh-Positive'})</span>
                                                                    </p>
                                                                </div>
                                                            </div>
                                                            <div className="flex items-center gap-1.5">
                                                                <Badge variant="outline" className="border-emerald-300 text-emerald-700 dark:text-emerald-300 text-[10px] bg-emerald-100/50">
                                                                    Verified Profile
                                                                </Badge>
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    className="h-6 px-1.5 text-xs text-muted-foreground hover:text-foreground"
                                                                    onClick={() => setIsEditingBlood(true)}
                                                                >
                                                                    Edit
                                                                </Button>
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <>
                                                            <div className="space-y-1.5">
                                                                <div className="flex items-center justify-between">
                                                                    <Label htmlFor="blood_group">Blood Group</Label>
                                                                    {hasKnownBloodGroup && (
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => setIsEditingBlood(false)}
                                                                            className="text-[10px] text-muted-foreground hover:underline"
                                                                        >
                                                                            Cancel
                                                                        </button>
                                                                    )}
                                                                </div>
                                                                <Select
                                                                    value={profileForm.data.blood_group || 'none'}
                                                                    onValueChange={(val) => profileForm.setData('blood_group', val === 'none' ? '' : val)}
                                                                >
                                                                    <SelectTrigger id="blood_group">
                                                                        <SelectValue placeholder="Select" />
                                                                    </SelectTrigger>
                                                                    <SelectContent>
                                                                        <SelectItem value="none">Unknown</SelectItem>
                                                                        <SelectItem value="A">A</SelectItem>
                                                                        <SelectItem value="B">B</SelectItem>
                                                                        <SelectItem value="AB">AB</SelectItem>
                                                                        <SelectItem value="O">O</SelectItem>
                                                                    </SelectContent>
                                                                </Select>
                                                                <p className="text-[10px] text-muted-foreground">Saves to patient record permanently.</p>
                                                            </div>

                                                            <div className="space-y-1.5">
                                                                <Label htmlFor="rhesus">Rhesus (Rh)</Label>
                                                                <Select
                                                                    value={profileForm.data.rhesus || '+'}
                                                                    onValueChange={(val) => profileForm.setData('rhesus', val)}
                                                                >
                                                                    <SelectTrigger id="rhesus">
                                                                        <SelectValue placeholder="Rh" />
                                                                    </SelectTrigger>
                                                                    <SelectContent>
                                                                        <SelectItem value="+">Positive (+)</SelectItem>
                                                                        <SelectItem value="-">Negative (-)</SelectItem>
                                                                    </SelectContent>
                                                                </Select>
                                                            </div>
                                                        </>
                                                    )}

                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="hiv_status">HIV Status</Label>
                                                        <Select
                                                            value={profileForm.data.hiv_status || 'negative'}
                                                            onValueChange={(val) => profileForm.setData('hiv_status', val)}
                                                        >
                                                            <SelectTrigger id="hiv_status">
                                                                <SelectValue placeholder="HIV" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="negative">Negative</SelectItem>
                                                                <SelectItem value="positive_on_art">Positive (On ART)</SelectItem>
                                                                <SelectItem value="positive_not_on_art">Positive (Not on ART)</SelectItem>
                                                                <SelectItem value="unknown">Unknown / Not Tested</SelectItem>
                                                            </SelectContent>
                                                        </Select>
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="vdrl_rpr">VDRL / RPR (Syphilis)</Label>
                                                        <Select
                                                            value={profileForm.data.vdrl_rpr_status || 'non_reactive'}
                                                            onValueChange={(val) => profileForm.setData('vdrl_rpr_status', val)}
                                                        >
                                                            <SelectTrigger id="vdrl_rpr">
                                                                <SelectValue placeholder="VDRL" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="non_reactive">Non-Reactive</SelectItem>
                                                                <SelectItem value="reactive">Reactive</SelectItem>
                                                                <SelectItem value="unknown">Not Tested</SelectItem>
                                                            </SelectContent>
                                                        </Select>
                                                    </div>
                                                </div>
                                            </CardContent>
                                        </Card>

                                        {/* Risk Classification & Risk Factors */}
                                        <Card>
                                            <CardHeader>
                                                <CardTitle className="text-base flex items-center gap-2">
                                                    <ShieldAlert className="h-5 w-5 text-amber-600" />
                                                    Pregnancy Risk Classification
                                                </CardTitle>
                                                <CardDescription>
                                                    Classify pregnancy risk and select recognized clinical risk flags.
                                                </CardDescription>
                                            </CardHeader>
                                            <CardContent className="space-y-4">
                                                <div className="space-y-1.5">
                                                    <Label htmlFor="risk_classification">Clinical Risk Category *</Label>
                                                    <Select
                                                        value={profileForm.data.risk_classification}
                                                        onValueChange={(val) => profileForm.setData('risk_classification', val)}
                                                    >
                                                        <SelectTrigger id="risk_classification" className="h-10 font-medium">
                                                            <SelectValue placeholder="Select risk level" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {Object.entries(riskClassifications).map(([key, label]) => (
                                                                <SelectItem key={key} value={key}>
                                                                    {label}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </div>

                                                <div className="space-y-2">
                                                    <Label className="text-xs font-semibold text-muted-foreground uppercase">
                                                        Recognized Risk Factors
                                                    </Label>
                                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-56 overflow-y-auto p-1 border rounded-lg bg-muted/20">
                                                        {COMMON_RISK_FACTORS.map((factor) => {
                                                            const isChecked = profileForm.data.risk_factors.includes(factor);
                                                            return (
                                                                <button
                                                                    key={factor}
                                                                    type="button"
                                                                    onClick={() => toggleRiskFactor(factor)}
                                                                    className={`flex items-start gap-2 p-2 rounded text-left text-xs transition border ${isChecked
                                                                            ? 'bg-amber-100 border-amber-300 text-amber-900 font-semibold dark:bg-amber-950/60 dark:border-amber-800 dark:text-amber-200'
                                                                            : 'bg-background hover:bg-muted/50 border-transparent text-muted-foreground'
                                                                        }`}
                                                                >
                                                                    <div className={`mt-0.5 h-3.5 w-3.5 rounded border flex items-center justify-center ${isChecked ? 'bg-amber-600 border-amber-600 text-white' : 'border-gray-400'
                                                                        }`}>
                                                                        {isChecked && <CheckCircle2 className="h-3 w-3" />}
                                                                    </div>
                                                                    <span>{factor}</span>
                                                                </button>
                                                            );
                                                        })}
                                                    </div>
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="allergies">Allergies (Medication & Environmental)</Label>
                                                    <Textarea
                                                        id="allergies"
                                                        rows={2}
                                                        placeholder="e.g. Penicillin (Anaphylaxis), Sulfa drugs, Latex"
                                                        value={profileForm.data.allergies}
                                                        onChange={(e) => profileForm.setData('allergies', e.target.value)}
                                                    />
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="medications">Current Medications & Supplements</Label>
                                                    <Textarea
                                                        id="medications"
                                                        rows={2}
                                                        placeholder="e.g. Ferrous Sulphate 200mg od, Folic Acid 5mg od, Methyldopa 250mg tds"
                                                        value={profileForm.data.current_medications}
                                                        onChange={(e) => profileForm.setData('current_medications', e.target.value)}
                                                    />
                                                </div>
                                            </CardContent>
                                        </Card>

                                        {/* Medical & Obstetric History */}
                                        <Card className="md:col-span-2">
                                            <CardHeader>
                                                <CardTitle className="text-base flex items-center gap-2">
                                                    <FileText className="h-5 w-5 text-indigo-600" />
                                                    Medical, Surgical & Obstetric History
                                                </CardTitle>
                                                <CardDescription>
                                                    Document chronic conditions, past surgeries, and prior pregnancy outcomes.
                                                </CardDescription>
                                            </CardHeader>
                                            <CardContent className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                                <div className="space-y-1.5">
                                                    <Label htmlFor="previous_pregnancy_history">Previous Pregnancy History</Label>
                                                    <Textarea
                                                        id="previous_pregnancy_history"
                                                        rows={4}
                                                        placeholder="Detail past deliveries, year, mode of delivery (SVD, C/S), birth weight, complications (PPH, tears, retained placenta)..."
                                                        value={profileForm.data.previous_pregnancy_history}
                                                        onChange={(e) => profileForm.setData('previous_pregnancy_history', e.target.value)}
                                                    />
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="medical_history">Medical & Surgical History</Label>
                                                    <Textarea
                                                        id="medical_history"
                                                        rows={4}
                                                        placeholder="Chronic hypertension, asthma, diabetes mellitus, sickle cell disease, previous pelvic or abdominal surgeries..."
                                                        value={profileForm.data.medical_history}
                                                        onChange={(e) => profileForm.setData('medical_history', e.target.value)}
                                                    />
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="obstetric_notes">Clinical Notes & Action Plan</Label>
                                                    <Textarea
                                                        id="obstetric_notes"
                                                        rows={4}
                                                        placeholder="Nurse/midwife initial assessment notes, examination findings, follow-up instructions..."
                                                        value={profileForm.data.notes}
                                                        onChange={(e) => profileForm.setData('notes', e.target.value)}
                                                    />
                                                </div>
                                            </CardContent>
                                        </Card>
                                    </div>

                                    <div className="mt-6 flex justify-end">
                                        <Button
                                            type="submit"
                                            disabled={profileForm.processing}
                                            className="bg-pink-600 hover:bg-pink-700 text-white gap-2 px-6"
                                        >
                                            <Save className="h-4 w-4" />
                                            Save Mother Assessment Details
                                        </Button>
                                    </div>
                                </form>
                            </TabsContent>

                            {/* â•â•â•â•â•â•â•â•â• TAB 2: FETAL ASSESSMENT & MONITORING (PHASE 4) â•â•â•â•â•â•â•â•â• */}
                            <TabsContent value="fetal" className="space-y-6">
                                {/* New Fetal Observation Form */}
                                <Card className="border-indigo-200 dark:border-indigo-900/50">
                                    <CardHeader className="bg-indigo-50/50 dark:bg-indigo-950/20 pb-3">
                                        <CardTitle className="text-base flex items-center justify-between text-indigo-950 dark:text-indigo-200">
                                            <span className="flex items-center gap-2">
                                                <Baby className="h-5 w-5 text-indigo-600" />
                                                Record Fetal Assessment & Monitoring
                                            </span>
                                            <div className="flex items-center gap-1.5">
                                                <Badge variant="outline" className="text-xs bg-white/80 dark:bg-gray-900">
                                                    {fetalForm.data.fetus_identifier}
                                                </Badge>
                                            </div>
                                        </CardTitle>
                                        <CardDescription>
                                            Dedicated fetal wellbeing parameters: FHR, fetal movements, fundal height, presentation, lie, EFW, and sonography. Distinct from maternal vitals.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="pt-4">
                                        <form onSubmit={handleFetalSubmit} className="space-y-4">
                                            {/* Multi-Fetal Context & Identifier */}
                                            <div className="rounded-lg border border-indigo-100 bg-indigo-50/40 p-3 dark:border-indigo-900/30 dark:bg-indigo-950/20">
                                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="num_fetuses">Number of Fetuses</Label>
                                                        <Select
                                                            value={String(fetalForm.data.number_of_fetuses)}
                                                            onValueChange={(val) => {
                                                                const num = parseInt(val) || 1;
                                                                fetalForm.setData((prev) => ({
                                                                    ...prev,
                                                                    number_of_fetuses: num,
                                                                    fetus_identifier: num === 1 ? 'Singleton' : prev.fetus_identifier === 'Singleton' ? 'Fetus A' : prev.fetus_identifier,
                                                                }));
                                                            }}
                                                        >
                                                            <SelectTrigger id="num_fetuses">
                                                                <SelectValue placeholder="Number of fetuses" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="1">1 (Singleton)</SelectItem>
                                                                <SelectItem value="2">2 (Twins)</SelectItem>
                                                                <SelectItem value="3">3 (Triplets)</SelectItem>
                                                                <SelectItem value="4">4 (Quadruplets)</SelectItem>
                                                            </SelectContent>
                                                        </Select>
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="fetus_id">Fetus Identifier *</Label>
                                                        <div className="flex gap-1.5">
                                                            <Input
                                                                id="fetus_id"
                                                                value={fetalForm.data.fetus_identifier}
                                                                onChange={(e) => fetalForm.setData('fetus_identifier', e.target.value)}
                                                                placeholder="e.g. Fetus A, Fetus B, Singleton"
                                                                required
                                                            />
                                                        </div>
                                                    </div>

                                                    <div className="flex items-end pb-1 gap-1.5 flex-wrap">
                                                        <span className="text-xs text-muted-foreground mr-1">Quick label:</span>
                                                        {fetalForm.data.number_of_fetuses === 1 ? (
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant={fetalForm.data.fetus_identifier === 'Singleton' ? 'default' : 'outline'}
                                                                className="h-7 text-xs"
                                                                onClick={() => fetalForm.setData('fetus_identifier', 'Singleton')}
                                                            >
                                                                Singleton
                                                            </Button>
                                                        ) : (
                                                            ['Fetus A', 'Fetus B', 'Fetus C'].slice(0, fetalForm.data.number_of_fetuses).map((lbl) => (
                                                                <Button
                                                                    key={lbl}
                                                                    type="button"
                                                                    size="sm"
                                                                    variant={fetalForm.data.fetus_identifier === lbl ? 'default' : 'outline'}
                                                                    className="h-7 text-xs"
                                                                    onClick={() => fetalForm.setData('fetus_identifier', lbl)}
                                                                >
                                                                    {lbl}
                                                                </Button>
                                                            ))
                                                        )}
                                                    </div>
                                                </div>
                                            </div>

                                            {/* Primary Fetal Measurements */}
                                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                                <div className="space-y-1.5">
                                                    <Label htmlFor="fhr">Fetal Heart Rate (bpm)</Label>
                                                    <Input
                                                        id="fhr"
                                                        type="number"
                                                        min="60"
                                                        max="240"
                                                        placeholder="e.g. 142"
                                                        value={fetalForm.data.fetal_heart_rate}
                                                        onChange={(e) => fetalForm.setData('fetal_heart_rate', e.target.value)}
                                                    />
                                                    <p className="text-[10px] text-muted-foreground">Normal: 110â€“160 bpm</p>
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="fundal_ht">Fundal Height (cm)</Label>
                                                    <Input
                                                        id="fundal_ht"
                                                        type="number"
                                                        step="0.5"
                                                        placeholder="e.g. 28.0"
                                                        value={fetalForm.data.fundal_height_cm}
                                                        onChange={(e) => fetalForm.setData('fundal_height_cm', e.target.value)}
                                                    />
                                                    <p className="text-[10px] text-muted-foreground">Symphysis-fundal height</p>
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="f_movements">Fetal Movements</Label>
                                                    <Select
                                                        value={fetalForm.data.fetal_movements}
                                                        onValueChange={(val) => fetalForm.setData('fetal_movements', val)}
                                                    >
                                                        <SelectTrigger id="f_movements">
                                                            <SelectValue placeholder="Select" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {Object.entries(fetalMovements).map(([k, label]) => (
                                                                <SelectItem key={k} value={k}>{label}</SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="ga_at_assessment">GA at Assessment (wks)</Label>
                                                    <Input
                                                        id="ga_at_assessment"
                                                        type="number"
                                                        step="0.1"
                                                        placeholder="e.g. 28.4"
                                                        value={fetalForm.data.gestational_age_weeks}
                                                        onChange={(e) => fetalForm.setData('gestational_age_weeks', e.target.value)}
                                                    />
                                                </div>
                                            </div>

                                            {/* Presentation, Lie, and Estimated Weight */}
                                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-2 border-t">
                                                <div className="space-y-1.5">
                                                    <Label htmlFor="f_presentation">Fetal Presentation</Label>
                                                    <Select
                                                        value={fetalForm.data.presentation}
                                                        onValueChange={(val) => fetalForm.setData('presentation', val)}
                                                    >
                                                        <SelectTrigger id="f_presentation">
                                                            <SelectValue placeholder="Presentation" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {Object.entries(fetalPresentations).map(([k, label]) => (
                                                                <SelectItem key={k} value={k}>{label}</SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="f_lie">Fetal Lie</Label>
                                                    <Select
                                                        value={fetalForm.data.lie}
                                                        onValueChange={(val) => fetalForm.setData('lie', val)}
                                                    >
                                                        <SelectTrigger id="f_lie">
                                                            <SelectValue placeholder="Lie" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {Object.entries(fetalLies).map(([k, label]) => (
                                                                <SelectItem key={k} value={k}>{label}</SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="efw">Estimated Fetal Weight (grams)</Label>
                                                    <Input
                                                        id="efw"
                                                        type="number"
                                                        placeholder="e.g. 2450"
                                                        value={fetalForm.data.estimated_fetal_weight_grams}
                                                        onChange={(e) => fetalForm.setData('estimated_fetal_weight_grams', e.target.value)}
                                                    />
                                                    <p className="text-[10px] text-muted-foreground">Clinical / Ultrasound estimate</p>
                                                </div>
                                            </div>

                                            {/* Ultrasound & Clinical Notes */}
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
                                                <div className="space-y-1.5">
                                                    <Label htmlFor="us_findings">Ultrasound Findings & Biometry</Label>
                                                    <Textarea
                                                        id="us_findings"
                                                        rows={3}
                                                        placeholder="e.g. Placenta: fundal anterior, grade II; Liquor: adequate (AFI 14 cm); BPD/FL concordant with 28w"
                                                        value={fetalForm.data.ultrasound_findings}
                                                        onChange={(e) => fetalForm.setData('ultrasound_findings', e.target.value)}
                                                    />
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="f_notes">Clinical Notes & Fetal Plan</Label>
                                                    <Textarea
                                                        id="f_notes"
                                                        rows={3}
                                                        placeholder="e.g. Good variability by hand-held Doppler; active kicks reported by mother; re-evaluate next ANC visit"
                                                        value={fetalForm.data.clinical_notes}
                                                        onChange={(e) => fetalForm.setData('clinical_notes', e.target.value)}
                                                    />
                                                </div>
                                            </div>

                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
                                                <div className="space-y-1.5">
                                                    <Label htmlFor="f_assessed_at">Assessment Date & Time *</Label>
                                                    <Input
                                                        id="f_assessed_at"
                                                        type="datetime-local"
                                                        value={fetalForm.data.assessed_at}
                                                        onChange={(e) => fetalForm.setData('assessed_at', e.target.value)}
                                                    />
                                                </div>

                                                <div className="flex items-end justify-end">
                                                    <Button
                                                        type="submit"
                                                        disabled={fetalForm.processing}
                                                        className="bg-indigo-600 hover:bg-indigo-700 text-white gap-2 w-full sm:w-auto"
                                                    >
                                                        <Plus className="h-4 w-4" />
                                                        Save Fetal Assessment
                                                    </Button>
                                                </div>
                                            </div>
                                        </form>
                                    </CardContent>
                                </Card>

                                {/* Fetal Monitoring Progress Log Table */}
                                <Card>
                                    <CardHeader>
                                        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                            <div>
                                                <CardTitle className="text-base flex items-center gap-2">
                                                    <History className="h-5 w-5 text-indigo-600" />
                                                    Fetal Monitoring & Growth Progress History
                                                </CardTitle>
                                                <CardDescription>
                                                    Chronological record of repeated fetal observations. Distinguishes each fetus in multiple pregnancies.
                                                </CardDescription>
                                            </div>

                                            {/* Fetus Filter Pills */}
                                            <div className="flex items-center gap-1.5 flex-wrap">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant={selectedFetusFilter === 'all' ? 'default' : 'outline'}
                                                    className="h-7 text-xs"
                                                    onClick={() => setSelectedFetusFilter('all')}
                                                >
                                                    All ({fetalAssessments.length})
                                                </Button>
                                                {Array.from(new Set(fetalAssessments.map((a) => a.fetus_identifier))).map((id) => (
                                                    <Button
                                                        key={id}
                                                        type="button"
                                                        size="sm"
                                                        variant={selectedFetusFilter === id ? 'default' : 'outline'}
                                                        className="h-7 text-xs"
                                                        onClick={() => setSelectedFetusFilter(id)}
                                                    >
                                                        {id}
                                                    </Button>
                                                ))}
                                            </div>
                                        </div>
                                    </CardHeader>
                                    <CardContent>
                                        {fetalAssessments.length === 0 ? (
                                            <div className="text-center py-8 text-muted-foreground text-sm">
                                                <Baby className="h-10 w-10 text-muted-foreground/30 mx-auto mb-2" />
                                                No fetal observations recorded yet for this pregnancy. Record initial assessment above.
                                            </div>
                                        ) : (
                                            <div className="overflow-x-auto">
                                                <Table>
                                                    <TableHeader>
                                                        <TableRow>
                                                            <TableHead>Date & Time</TableHead>
                                                            <TableHead>Fetus</TableHead>
                                                            <TableHead>GA (wks)</TableHead>
                                                            <TableHead>FHR (bpm)</TableHead>
                                                            <TableHead>Movements</TableHead>
                                                            <TableHead>Fundal Ht</TableHead>
                                                            <TableHead>Presentation</TableHead>
                                                            <TableHead>Lie</TableHead>
                                                            <TableHead>EFW</TableHead>
                                                            <TableHead>Ultrasound & Notes</TableHead>
                                                            <TableHead>Assessed By</TableHead>
                                                        </TableRow>
                                                    </TableHeader>
                                                    <TableBody>
                                                        {fetalAssessments
                                                            .filter((f) => selectedFetusFilter === 'all' || f.fetus_identifier === selectedFetusFilter)
                                                            .map((a) => {
                                                                const isAbnormalFhr = a.fetal_heart_rate !== null && (a.fetal_heart_rate < 110 || a.fetal_heart_rate > 160);
                                                                return (
                                                                    <TableRow key={a.id}>
                                                                        <TableCell className="font-medium whitespace-nowrap text-xs">
                                                                            {formatDateTime(a.assessed_at)}
                                                                        </TableCell>
                                                                        <TableCell className="whitespace-nowrap">
                                                                            <Badge variant="outline" className="border-indigo-300 bg-indigo-50 text-indigo-800 text-[11px] font-semibold dark:bg-indigo-950/40 dark:text-indigo-200">
                                                                                {a.fetus_identifier}
                                                                            </Badge>
                                                                        </TableCell>
                                                                        <TableCell className="text-xs">
                                                                            {a.gestational_age_weeks ? `${a.gestational_age_weeks} w` : 'â€”'}
                                                                        </TableCell>
                                                                        <TableCell className="whitespace-nowrap">
                                                                            {a.fetal_heart_rate ? (
                                                                                <Badge
                                                                                    variant="outline"
                                                                                    className={
                                                                                        isAbnormalFhr
                                                                                            ? 'border-red-400 bg-red-50 text-red-700 font-bold dark:bg-red-950/40 dark:text-red-300'
                                                                                            : 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
                                                                                    }
                                                                                >
                                                                                    {a.fetal_heart_rate} bpm
                                                                                </Badge>
                                                                            ) : 'â€”'}
                                                                        </TableCell>
                                                                        <TableCell className="text-xs capitalize">
                                                                            {a.fetal_movements ? a.fetal_movements.replace('_', ' ') : 'â€”'}
                                                                        </TableCell>
                                                                        <TableCell className="text-xs whitespace-nowrap">
                                                                            {a.fundal_height_cm ? `${a.fundal_height_cm} cm` : 'â€”'}
                                                                        </TableCell>
                                                                        <TableCell className="text-xs capitalize">
                                                                            {a.presentation || 'â€”'}
                                                                        </TableCell>
                                                                        <TableCell className="text-xs capitalize">
                                                                            {a.lie || 'â€”'}
                                                                        </TableCell>
                                                                        <TableCell className="text-xs whitespace-nowrap">
                                                                            {a.estimated_fetal_weight_grams ? `${a.estimated_fetal_weight_grams} g` : 'â€”'}
                                                                        </TableCell>
                                                                        <TableCell className="text-xs max-w-xs truncate" title={`${a.ultrasound_findings ?? ''} ${a.clinical_notes ?? ''}`}>
                                                                            {a.ultrasound_findings || a.clinical_notes || 'â€”'}
                                                                        </TableCell>
                                                                        <TableCell className="text-xs whitespace-nowrap">
                                                                            {a.assessed_by?.UserName ?? 'Clinician'}
                                                                        </TableCell>
                                                                    </TableRow>
                                                                );
                                                            })}
                                                    </TableBody>
                                                </Table>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            </TabsContent>

                            {/* â•â•â•â•â•â•â•â•â• TAB 3: MATERNAL VITAL SIGNS & OBSERVATIONS â•â•â•â•â•â•â•â•â• */}
                            <TabsContent value="vitals" className="space-y-6">
                                {/* New Observation Entry Form */}
                                <Card className="border-rose-200 dark:border-rose-900/50">
                                    <CardHeader className="bg-rose-50/50 dark:bg-rose-950/20 pb-3">
                                        <CardTitle className="text-base flex items-center gap-2 text-rose-900 dark:text-rose-200">
                                            <Activity className="h-5 w-5 text-rose-600" />
                                            Record New Maternal Vital Sign & Bedside Observation
                                        </CardTitle>
                                        <CardDescription>
                                            Supports repeated observations during this visit without overwriting previous historical measurements.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="pt-4">
                                        <form onSubmit={handleVitalSubmit} className="space-y-4">
                                            <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-3">
                                                <div className="space-y-1.5">
                                                    <Label htmlFor="systolic">Systolic (mmHg)</Label>
                                                    <Input
                                                        id="systolic"
                                                        type="number"
                                                        placeholder="e.g. 120"
                                                        value={vitalForm.data.systolic_mmhg}
                                                        onChange={(e) => vitalForm.setData('systolic_mmhg', e.target.value)}
                                                    />
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="diastolic">Diastolic (mmHg)</Label>
                                                    <Input
                                                        id="diastolic"
                                                        type="number"
                                                        placeholder="e.g. 80"
                                                        value={vitalForm.data.diastolic_mmhg}
                                                        onChange={(e) => vitalForm.setData('diastolic_mmhg', e.target.value)}
                                                    />
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="pulse">Pulse Rate (bpm)</Label>
                                                    <Input
                                                        id="pulse"
                                                        type="number"
                                                        placeholder="e.g. 78"
                                                        value={vitalForm.data.pulse_bpm}
                                                        onChange={(e) => vitalForm.setData('pulse_bpm', e.target.value)}
                                                    />
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="temp">Temp (Â°C)</Label>
                                                    <Input
                                                        id="temp"
                                                        type="number"
                                                        step="0.1"
                                                        placeholder="e.g. 36.8"
                                                        value={vitalForm.data.temperature_c}
                                                        onChange={(e) => vitalForm.setData('temperature_c', e.target.value)}
                                                    />
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="resp">Resp Rate (bpm)</Label>
                                                    <Input
                                                        id="resp"
                                                        type="number"
                                                        placeholder="e.g. 18"
                                                        value={vitalForm.data.respiratory_rate_bpm}
                                                        onChange={(e) => vitalForm.setData('respiratory_rate_bpm', e.target.value)}
                                                    />
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="spo2">SpO2 (%)</Label>
                                                    <Input
                                                        id="spo2"
                                                        type="number"
                                                        placeholder="e.g. 98"
                                                        value={vitalForm.data.oxygen_saturation_pct}
                                                        onChange={(e) => vitalForm.setData('oxygen_saturation_pct', e.target.value)}
                                                    />
                                                </div>
                                            </div>

                                            {/* Physical measurements & Biochemical */}
                                            <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-3 pt-2 border-t">
                                                <div className="space-y-1.5">
                                                    <Label htmlFor="weight">Weight (kg)</Label>
                                                    <Input
                                                        id="weight"
                                                        type="number"
                                                        step="0.1"
                                                        placeholder="e.g. 68.5"
                                                        value={vitalForm.data.weight_kg}
                                                        onChange={(e) => vitalForm.setData('weight_kg', e.target.value)}
                                                    />
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="height">Height (cm)</Label>
                                                    <Input
                                                        id="height"
                                                        type="number"
                                                        step="0.5"
                                                        placeholder="e.g. 162"
                                                        value={vitalForm.data.height_cm}
                                                        onChange={(e) => vitalForm.setData('height_cm', e.target.value)}
                                                    />
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="bmi_preview">Calculated BMI</Label>
                                                    <div className="h-10 px-3 py-2 rounded-md border bg-muted/40 text-sm font-semibold flex items-center">
                                                        {computedBmi ? `${computedBmi} kg/mÂ²` : 'â€”'}
                                                    </div>
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="urine_protein">Urine Protein</Label>
                                                    <Select
                                                        value={vitalForm.data.urine_protein}
                                                        onValueChange={(val) => vitalForm.setData('urine_protein', val)}
                                                    >
                                                        <SelectTrigger id="urine_protein">
                                                            <SelectValue placeholder="Protein" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {Object.entries(urineOptions).map(([key, label]) => (
                                                                <SelectItem key={key} value={key}>
                                                                    {label}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="urine_glucose">Urine Glucose</Label>
                                                    <Select
                                                        value={vitalForm.data.urine_glucose}
                                                        onValueChange={(val) => vitalForm.setData('urine_glucose', val)}
                                                    >
                                                        <SelectTrigger id="urine_glucose">
                                                            <SelectValue placeholder="Glucose" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {Object.entries(urineOptions).map(([key, label]) => (
                                                                <SelectItem key={key} value={key}>
                                                                    {label}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="blood_glucose">Blood Glucose (mmol/L)</Label>
                                                    <Input
                                                        id="blood_glucose"
                                                        type="number"
                                                        step="0.1"
                                                        placeholder="e.g. 5.4"
                                                        value={vitalForm.data.blood_glucose_mmol}
                                                        onChange={(e) => vitalForm.setData('blood_glucose_mmol', e.target.value)}
                                                    />
                                                </div>
                                            </div>

                                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-2">
                                                <div className="space-y-1.5">
                                                    <Label htmlFor="measured_at">Observation Timestamp *</Label>
                                                    <Input
                                                        id="measured_at"
                                                        type="datetime-local"
                                                        value={vitalForm.data.measured_at}
                                                        onChange={(e) => vitalForm.setData('measured_at', e.target.value)}
                                                    />
                                                </div>

                                                <div className="sm:col-span-2 space-y-1.5">
                                                    <Label htmlFor="obs_notes">Observation Remarks</Label>
                                                    <Input
                                                        id="obs_notes"
                                                        placeholder="e.g. Resting BP, asymptomatic, slight trace proteinuria noted"
                                                        value={vitalForm.data.observation_notes}
                                                        onChange={(e) => vitalForm.setData('observation_notes', e.target.value)}
                                                    />
                                                </div>
                                            </div>

                                            <div className="flex justify-end pt-2">
                                                <Button
                                                    type="submit"
                                                    disabled={vitalForm.processing}
                                                    className="bg-rose-600 hover:bg-rose-700 text-white gap-2"
                                                >
                                                    <Plus className="h-4 w-4" />
                                                    Record Observation
                                                </Button>
                                            </div>
                                        </form>
                                    </CardContent>
                                </Card>

                                {/* Chronological Observations History Table */}
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base flex items-center justify-between">
                                            <span className="flex items-center gap-2">
                                                <History className="h-5 w-5 text-pink-600" />
                                                Maternal Observation History (Chronological)
                                            </span>
                                            <Badge variant="outline">
                                                {vitalSigns.length} Observation(s)
                                            </Badge>
                                        </CardTitle>
                                        <CardDescription>
                                            Immutable historical observation log taken during this encounter.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        {vitalSigns.length === 0 ? (
                                            <div className="text-center py-8 text-muted-foreground text-sm">
                                                <HeartPulse className="h-10 w-10 text-muted-foreground/30 mx-auto mb-2" />
                                                No maternal observations recorded yet for this visit. Use the form above to capture initial vitals.
                                            </div>
                                        ) : (
                                            <div className="overflow-x-auto">
                                                <Table>
                                                    <TableHeader>
                                                        <TableRow>
                                                            <TableHead>Date & Time</TableHead>
                                                            <TableHead>Recorded By</TableHead>
                                                            <TableHead>Encounter</TableHead>
                                                            <TableHead>BP (mmHg)</TableHead>
                                                            <TableHead>Pulse</TableHead>
                                                            <TableHead>Temp</TableHead>
                                                            <TableHead>Resp</TableHead>
                                                            <TableHead>SpO2</TableHead>
                                                            <TableHead>Weight / BMI</TableHead>
                                                            <TableHead>Urine Prot/Glu</TableHead>
                                                            <TableHead>B-Glucose</TableHead>
                                                            <TableHead>Notes</TableHead>
                                                        </TableRow>
                                                    </TableHeader>
                                                    <TableBody>
                                                        {vitalSigns.map((v) => {
                                                            const isHighBp =
                                                                (v.systolic_mmhg !== null && v.systolic_mmhg >= 140) ||
                                                                (v.diastolic_mmhg !== null && v.diastolic_mmhg >= 90);
                                                            const isHighProtein =
                                                                v.urine_protein && ['2+', '3+', '4+'].includes(v.urine_protein);

                                                            return (
                                                                <TableRow key={v.id}>
                                                                    <TableCell className="font-medium whitespace-nowrap text-xs">
                                                                        {formatDateTime(v.measured_at || v.recorded_at)}
                                                                    </TableCell>
                                                                    <TableCell className="text-xs">
                                                                        {v.recorded_by?.UserName ?? 'Clinician'}
                                                                    </TableCell>
                                                                    <TableCell className="text-xs font-mono">
                                                                        #{v.clinical_encounter_id ?? encounter.id}
                                                                    </TableCell>
                                                                    <TableCell className="whitespace-nowrap">
                                                                        {v.systolic_mmhg && v.diastolic_mmhg ? (
                                                                            <Badge
                                                                                variant="outline"
                                                                                className={
                                                                                    isHighBp
                                                                                        ? 'border-red-400 bg-red-50 text-red-700 font-bold dark:bg-red-950/40 dark:text-red-300'
                                                                                        : 'border-green-300 bg-green-50 text-green-700 dark:bg-green-950/40 dark:text-green-300'
                                                                                }
                                                                            >
                                                                                {v.systolic_mmhg}/{v.diastolic_mmhg}
                                                                            </Badge>
                                                                        ) : 'â€”'}
                                                                    </TableCell>
                                                                    <TableCell className="text-xs">
                                                                        {v.pulse_bpm ? `${v.pulse_bpm} bpm` : 'â€”'}
                                                                    </TableCell>
                                                                    <TableCell className="text-xs">
                                                                        {v.temperature_c ? `${v.temperature_c} Â°C` : 'â€”'}
                                                                    </TableCell>
                                                                    <TableCell className="text-xs">
                                                                        {v.respiratory_rate_bpm ? `${v.respiratory_rate_bpm}` : 'â€”'}
                                                                    </TableCell>
                                                                    <TableCell className="text-xs">
                                                                        {v.oxygen_saturation_pct ? `${v.oxygen_saturation_pct}%` : 'â€”'}
                                                                    </TableCell>
                                                                    <TableCell className="text-xs whitespace-nowrap">
                                                                        {v.weight_kg ? `${v.weight_kg} kg` : 'â€”'}
                                                                        {v.bmi ? <span className="text-muted-foreground ml-1">({v.bmi})</span> : null}
                                                                    </TableCell>
                                                                    <TableCell className="text-xs whitespace-nowrap">
                                                                        {v.urine_protein ? (
                                                                            <span className={isHighProtein ? 'text-red-600 font-bold' : ''}>
                                                                                P: {v.urine_protein}
                                                                            </span>
                                                                        ) : null}
                                                                        {v.urine_glucose ? (
                                                                            <span className="ml-1 text-muted-foreground">
                                                                                G: {v.urine_glucose}
                                                                            </span>
                                                                        ) : null}
                                                                        {!v.urine_protein && !v.urine_glucose && 'â€”'}
                                                                    </TableCell>
                                                                    <TableCell className="text-xs">
                                                                        {v.blood_glucose_mmol ? `${v.blood_glucose_mmol} mmol/L` : 'â€”'}
                                                                    </TableCell>
                                                                    <TableCell className="text-xs max-w-xs truncate" title={v.observation_notes ?? ''}>
                                                                        {v.observation_notes || 'â€”'}
                                                                    </TableCell>
                                                                </TableRow>
                                                            );
                                                        })}
                                                    </TableBody>
                                                </Table>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            </TabsContent>

                            {/* â•â•â•â•â•â•â•â•â• TAB: LABOUR MONITORING & PARTOGRAPH â•â•â•â•â•â•â•â•â• */}
                            <TabsContent value="labour" className="space-y-6">
                                {/* Action Line or Emergency Alert Banner */}
                                {(partographEntries ?? []).some((p) => p.action_line_crossed || p.is_emergency) && (
                                    <div className="rounded-lg border-2 border-red-500 bg-red-50 p-4 text-red-900 shadow-sm dark:bg-red-950/40 dark:text-red-200">
                                        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                            <div className="flex items-start gap-3">
                                                <AlertTriangle className="h-6 w-6 text-red-600 mt-0.5 shrink-0" />
                                                <div>
                                                    <h3 className="font-bold text-base">Labour Alert: Action Line Crossed / Emergency Flagged</h3>
                                                    <p className="text-sm text-red-700 dark:text-red-300">
                                                        Cervical dilation or clinical parameters have reached or surpassed the WHO action line thresholds. Immediate senior midwife or obstetrician review is required. Admission to inpatient labour ward is strongly recommended.
                                                    </p>
                                                </div>
                                            </div>
                                            <div className="shrink-0">
                                                <Button
                                                    size="sm"
                                                    className="bg-red-600 hover:bg-red-700 text-white font-medium"
                                                    asChild
                                                >
                                                    <Link
                                                        href={route('admissions.create', {
                                                            patient_id: visit.patient_id,
                                                            department_id: visit.department_id,
                                                            reception_visit_id: visit.id,
                                                            clinical_encounter_id: encounter.id,
                                                            preset: 'maternity',
                                                            reason: 'Prolonged labour / Action line crossed during outpatient monitoring',
                                                        })}
                                                    >
                                                        <Bed className="mr-1.5 h-4 w-4" />
                                                        Admit to Labour Ward
                                                    </Link>
                                                </Button>
                                            </div>
                                        </div>
                                    </div>
                                )}

                                <div className="grid grid-cols-1 xl:grid-cols-3 gap-6 [&>*]:min-w-0">
                                    {/* Column 1: Labour Onset & Staging */}
                                    <Card className="xl:col-span-1">
                                        <CardHeader>
                                            <CardTitle className="text-base flex items-center gap-2">
                                                <Activity className="h-5 w-5 text-amber-600" />
                                                Labour Onset & Staging
                                            </CardTitle>
                                            <CardDescription>
                                                Document onset details, active labour stage, and membrane status.
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent>
                                            <form onSubmit={handleLabourStatusSubmit} className="space-y-4">
                                                <div className="space-y-1.5">
                                                    <Label htmlFor="labour_stage">Labour Stage *</Label>
                                                    <Select
                                                        value={labourStatusForm.data.labour_stage}
                                                        onValueChange={(val) => labourStatusForm.setData('labour_stage', val)}
                                                    >
                                                        <SelectTrigger id="labour_stage">
                                                            <SelectValue placeholder="Select Stage" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {Object.entries(labourStages).map(([k, label]) => (
                                                                <SelectItem key={k} value={k}>
                                                                    {label}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="labour_onset_type">Onset Type *</Label>
                                                    <Select
                                                        value={labourStatusForm.data.labour_onset_type}
                                                        onValueChange={(val) => labourStatusForm.setData('labour_onset_type', val)}
                                                    >
                                                        <SelectTrigger id="labour_onset_type">
                                                            <SelectValue placeholder="Select Onset Type" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {Object.entries(labourOnsetTypes).map(([k, label]) => (
                                                                <SelectItem key={k} value={k}>
                                                                    {label}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="labour_onset_at">Onset Date & Time</Label>
                                                    <Input
                                                        id="labour_onset_at"
                                                        type="datetime-local"
                                                        value={labourStatusForm.data.labour_onset_at}
                                                        onChange={(e) => labourStatusForm.setData('labour_onset_at', e.target.value)}
                                                    />
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="cervix_effacement_pct">Cervical Effacement (%)</Label>
                                                    <Input
                                                        id="cervix_effacement_pct"
                                                        type="number"
                                                        min="0"
                                                        max="100"
                                                        placeholder="0 - 100%"
                                                        value={labourStatusForm.data.cervix_effacement_pct}
                                                        onChange={(e) => labourStatusForm.setData('cervix_effacement_pct', e.target.value)}
                                                    />
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="membrane_status">Membranes *</Label>
                                                    <Select
                                                        value={labourStatusForm.data.membrane_status}
                                                        onValueChange={(val) => labourStatusForm.setData('membrane_status', val)}
                                                    >
                                                        <SelectTrigger id="membrane_status">
                                                            <SelectValue placeholder="Membrane Status" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="intact">Intact</SelectItem>
                                                            <SelectItem value="ruptured">Ruptured (Spontaneous - SROM)</SelectItem>
                                                            <SelectItem value="artificial_rupture">Ruptured (Artificial - ARM)</SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                </div>

                                                {labourStatusForm.data.membrane_status !== 'intact' && (
                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="membrane_ruptured_at">Rupture Date & Time</Label>
                                                        <Input
                                                            id="membrane_ruptured_at"
                                                            type="datetime-local"
                                                            value={labourStatusForm.data.membrane_ruptured_at}
                                                            onChange={(e) => labourStatusForm.setData('membrane_ruptured_at', e.target.value)}
                                                        />
                                                    </div>
                                                )}

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="labour_notes">Progress Remarks</Label>
                                                    <Textarea
                                                        id="labour_notes"
                                                        rows={2}
                                                        placeholder="e.g. Regular contractions noted, maternal distress absent"
                                                        value={labourStatusForm.data.notes}
                                                        onChange={(e) => labourStatusForm.setData('notes', e.target.value)}
                                                    />
                                                </div>

                                                <Button
                                                    type="submit"
                                                    disabled={labourStatusForm.processing}
                                                    className="w-full bg-amber-600 hover:bg-amber-700 text-white"
                                                >
                                                    <Save className="mr-1.5 h-4 w-4" />
                                                    Update Labour Status
                                                </Button>
                                            </form>
                                        </CardContent>
                                    </Card>

                                    {/* Column 2: New Partograph Entry Form */}
                                    <Card className="xl:col-span-2">
                                        <CardHeader>
                                            <CardTitle className="text-base flex items-center gap-2">
                                                <Plus className="h-5 w-5 text-amber-600" />
                                                Record Partograph Observation
                                            </CardTitle>
                                            <CardDescription>
                                                Log serial cervical dilatation, contractions, fetal heart rate, and maternal vitals on the WHO partograph timeline.
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent>
                                            <form onSubmit={handlePartographSubmit} className="space-y-4">
                                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="p_recorded_at">Observation Time *</Label>
                                                        <Input
                                                            id="p_recorded_at"
                                                            type="datetime-local"
                                                            required
                                                            value={partographForm.data.recorded_at}
                                                            onChange={(e) => partographForm.setData('recorded_at', e.target.value)}
                                                        />
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="p_dilation">Cervical Dilation (cm)</Label>
                                                        <Input
                                                            id="p_dilation"
                                                            type="number"
                                                            step="0.5"
                                                            min="0"
                                                            max="10"
                                                            placeholder="0 - 10 cm"
                                                            value={partographForm.data.cervical_dilation_cm}
                                                            onChange={(e) => partographForm.setData('cervical_dilation_cm', e.target.value)}
                                                        />
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="p_descent">Descent of Head</Label>
                                                        <Select
                                                            value={partographForm.data.descent_of_head}
                                                            onValueChange={(val) => partographForm.setData('descent_of_head', val)}
                                                        >
                                                            <SelectTrigger id="p_descent">
                                                                <SelectValue placeholder="Fifths above brim" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="5">5/5 (Completely above brim)</SelectItem>
                                                                <SelectItem value="4">4/5</SelectItem>
                                                                <SelectItem value="3">3/5</SelectItem>
                                                                <SelectItem value="2">2/5 (Engaged)</SelectItem>
                                                                <SelectItem value="1">1/5</SelectItem>
                                                                <SelectItem value="0">0/5 (Deeply engaged)</SelectItem>
                                                            </SelectContent>
                                                        </Select>
                                                    </div>
                                                </div>

                                                <div className="grid grid-cols-1 sm:grid-cols-4 gap-3">
                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="p_fhr">Fetal Heart Rate (bpm)</Label>
                                                        <Input
                                                            id="p_fhr"
                                                            type="number"
                                                            min="60"
                                                            max="220"
                                                            placeholder="110-160 normal"
                                                            value={partographForm.data.fetal_heart_rate}
                                                            onChange={(e) => partographForm.setData('fetal_heart_rate', e.target.value)}
                                                        />
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="p_fluid">Amniotic Fluid</Label>
                                                        <Select
                                                            value={partographForm.data.amniotic_fluid}
                                                            onValueChange={(val) => partographForm.setData('amniotic_fluid', val)}
                                                        >
                                                            <SelectTrigger id="p_fluid">
                                                                <SelectValue placeholder="Fluid state" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                {Object.entries(amnioticFluidStates).map(([k, label]) => (
                                                                    <SelectItem key={k} value={k}>
                                                                        {label}
                                                                    </SelectItem>
                                                                ))}
                                                            </SelectContent>
                                                        </Select>
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="p_moulding">Moulding</Label>
                                                        <Select
                                                            value={partographForm.data.moulding}
                                                            onValueChange={(val) => partographForm.setData('moulding', val)}
                                                        >
                                                            <SelectTrigger id="p_moulding">
                                                                <SelectValue placeholder="Moulding grade" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                {Object.entries(mouldingGrades).map(([k, label]) => (
                                                                    <SelectItem key={k} value={k}>
                                                                        {label}
                                                                    </SelectItem>
                                                                ))}
                                                            </SelectContent>
                                                        </Select>
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="p_contractions">Contractions / 10 min</Label>
                                                        <Input
                                                            id="p_contractions"
                                                            type="number"
                                                            min="0"
                                                            max="10"
                                                            placeholder="e.g. 3"
                                                            value={partographForm.data.contractions_per_10min}
                                                            onChange={(e) => partographForm.setData('contractions_per_10min', e.target.value)}
                                                        />
                                                    </div>
                                                </div>

                                                <div className="grid grid-cols-1 sm:grid-cols-4 gap-3">
                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="p_duration">Contraction Duration</Label>
                                                        <Select
                                                            value={partographForm.data.contraction_duration}
                                                            onValueChange={(val) => partographForm.setData('contraction_duration', val)}
                                                        >
                                                            <SelectTrigger id="p_duration">
                                                                <SelectValue placeholder="Duration" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                {Object.entries(contractionDurations).map(([k, label]) => (
                                                                    <SelectItem key={k} value={k}>
                                                                        {label}
                                                                    </SelectItem>
                                                                ))}
                                                            </SelectContent>
                                                        </Select>
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="p_sys">Systolic BP</Label>
                                                        <Input
                                                            id="p_sys"
                                                            type="number"
                                                            min="50"
                                                            max="260"
                                                            placeholder="mmHg"
                                                            value={partographForm.data.systolic_bp}
                                                            onChange={(e) => partographForm.setData('systolic_bp', e.target.value)}
                                                        />
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="p_dia">Diastolic BP</Label>
                                                        <Input
                                                            id="p_dia"
                                                            type="number"
                                                            min="30"
                                                            max="180"
                                                            placeholder="mmHg"
                                                            value={partographForm.data.diastolic_bp}
                                                            onChange={(e) => partographForm.setData('diastolic_bp', e.target.value)}
                                                        />
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="p_pulse">Pulse Rate (bpm)</Label>
                                                        <Input
                                                            id="p_pulse"
                                                            type="number"
                                                            min="40"
                                                            max="200"
                                                            placeholder="bpm"
                                                            value={partographForm.data.pulse_bpm}
                                                            onChange={(e) => partographForm.setData('pulse_bpm', e.target.value)}
                                                        />
                                                    </div>
                                                </div>

                                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="p_oxytocin">Oxytocin (drops/min)</Label>
                                                        <Input
                                                            id="p_oxytocin"
                                                            type="number"
                                                            min="0"
                                                            max="120"
                                                            placeholder="e.g. 15"
                                                            value={partographForm.data.oxytocin_drops_per_min}
                                                            onChange={(e) => partographForm.setData('oxytocin_drops_per_min', e.target.value)}
                                                        />
                                                    </div>

                                                    <div className="space-y-1.5 sm:col-span-2">
                                                        <Label htmlFor="p_drugs">Drugs & IV Fluids Given</Label>
                                                        <Input
                                                            id="p_drugs"
                                                            placeholder="e.g. Ringer Lactate 500ml IV, Paracetamol 1g PO"
                                                            value={partographForm.data.drugs_iv_fluids}
                                                            onChange={(e) => partographForm.setData('drugs_iv_fluids', e.target.value)}
                                                        />
                                                    </div>
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="p_notes">Observation Findings & Summary</Label>
                                                    <Textarea
                                                        id="p_notes"
                                                        rows={2}
                                                        placeholder="Document findings, cervix consistency, maternal tolerance..."
                                                        value={partographForm.data.notes}
                                                        onChange={(e) => partographForm.setData('notes', e.target.value)}
                                                    />
                                                </div>

                                                <div className="flex justify-end">
                                                    <Button
                                                        type="submit"
                                                        disabled={partographForm.processing}
                                                        className="bg-amber-600 hover:bg-amber-700 text-white"
                                                    >
                                                        <Plus className="mr-1.5 h-4 w-4" />
                                                        Log Partograph Observation
                                                    </Button>
                                                </div>
                                            </form>
                                        </CardContent>
                                    </Card>
                                </div>

                                {/* Chronological Partograph Observations Table */}
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base flex items-center gap-2">
                                            <FileSpreadsheet className="h-5 w-5 text-amber-600" />
                                            Labour Progress Log & WHO Partograph Entries ({partographEntries?.length ?? 0})
                                        </CardTitle>
                                        <CardDescription>
                                            Serial labour progression records. WHO alert and action lines assess dilation progress over time.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        {(!partographEntries || partographEntries.length === 0) ? (
                                            <div className="py-8 text-center text-sm text-muted-foreground">
                                                No partograph observations logged yet for this maternity visit.
                                            </div>
                                        ) : (
                                            <div className="overflow-x-auto">
                                                <Table>
                                                    <TableHeader>
                                                        <TableRow className="bg-muted/40">
                                                            <TableHead className="w-[140px]">Date & Time</TableHead>
                                                            <TableHead>Dilation</TableHead>
                                                            <TableHead>Head Descent</TableHead>
                                                            <TableHead>FHR (bpm)</TableHead>
                                                            <TableHead>Contractions</TableHead>
                                                            <TableHead>Amniotic / Moulding</TableHead>
                                                            <TableHead>Maternal Vitals</TableHead>
                                                            <TableHead>Partograph Status</TableHead>
                                                            <TableHead>Recorded By</TableHead>
                                                        </TableRow>
                                                    </TableHeader>
                                                    <TableBody>
                                                        {partographEntries.map((pe) => (
                                                            <TableRow
                                                                key={pe.id}
                                                                className={pe.action_line_crossed || pe.is_emergency ? 'bg-red-50/60 dark:bg-red-950/20' : ''}
                                                            >
                                                                <TableCell className="font-mono text-xs">
                                                                    {formatDateTime(pe.recorded_at)}
                                                                </TableCell>
                                                                <TableCell className="font-bold text-foreground">
                                                                    {pe.cervical_dilation_cm !== null ? `${pe.cervical_dilation_cm} cm` : 'â€”'}
                                                                </TableCell>
                                                                <TableCell className="text-xs">
                                                                    {pe.descent_of_head ?? 'â€”'}
                                                                </TableCell>
                                                                <TableCell className="text-xs font-semibold">
                                                                    {pe.fetal_heart_rate ? (
                                                                        <span className={pe.fetal_heart_rate < 110 || pe.fetal_heart_rate > 160 ? 'text-red-600 dark:text-red-400 font-bold' : ''}>
                                                                            {pe.fetal_heart_rate} bpm
                                                                        </span>
                                                                    ) : 'â€”'}
                                                                </TableCell>
                                                                <TableCell className="text-xs">
                                                                    {pe.contractions_per_10min !== null ? (
                                                                        <span>{pe.contractions_per_10min}/10min ({pe.contraction_duration ?? 'mod'})</span>
                                                                    ) : 'â€”'}
                                                                </TableCell>
                                                                <TableCell className="text-xs">
                                                                    <span>Fluid: {pe.amniotic_fluid ?? 'â€”'}</span>
                                                                    <span className="text-muted-foreground ml-1">| M: {pe.moulding ?? '0'}</span>
                                                                </TableCell>
                                                                <TableCell className="text-xs">
                                                                    {pe.systolic_bp && pe.diastolic_bp ? (
                                                                        <span>{pe.systolic_bp}/{pe.diastolic_bp} mmHg</span>
                                                                    ) : pe.pulse_bpm ? (
                                                                        <span>{pe.pulse_bpm} bpm</span>
                                                                    ) : 'â€”'}
                                                                </TableCell>
                                                                <TableCell>
                                                                    {pe.action_line_crossed ? (
                                                                        <Badge variant="destructive" className="text-[10px]">
                                                                            Action Line Crossed
                                                                        </Badge>
                                                                    ) : pe.alert_line_dilation_cm !== null ? (
                                                                        <Badge variant="outline" className="text-[10px] text-amber-700 border-amber-300">
                                                                            Alert Line: {pe.alert_line_dilation_cm}cm
                                                                        </Badge>
                                                                    ) : (
                                                                        <Badge variant="secondary" className="text-[10px]">
                                                                            Normal
                                                                        </Badge>
                                                                    )}
                                                                </TableCell>
                                                                <TableCell className="text-xs text-muted-foreground">
                                                                    {pe.recorded_by?.UserName ?? 'Clinician'}
                                                                </TableCell>
                                                            </TableRow>
                                                        ))}
                                                    </TableBody>
                                                </Table>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            </TabsContent>

                            {/* â•â•â•â•â•â•â•â•â• TAB: DELIVERY & NEWBORN RECORD (PHASE 7) â•â•â•â•â•â•â•â•â• */}
                            <TabsContent value="delivery" className="space-y-6">
                                <div className="grid grid-cols-1 xl:grid-cols-3 gap-6 [&>*]:min-w-0">
                                    {/* Column 1 & 2: Delivery Documentation Form */}
                                    <Card className="xl:col-span-2">
                                        <CardHeader>
                                            <CardTitle className="text-base flex items-center gap-2">
                                                <Sparkles className="h-5 w-5 text-emerald-600" />
                                                Document Delivery Outcome
                                            </CardTitle>
                                            <CardDescription>
                                                Record maternal delivery summary and create an independent linked clinical Patient record for the newborn.
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent>
                                            <form onSubmit={handleDeliverySubmit} className="space-y-5">
                                                {/* Baby Demographics & Outcome */}
                                                <div className="rounded-lg border p-3.5 bg-muted/20 space-y-3">
                                                    <h4 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground flex items-center gap-1.5">
                                                        <Baby className="h-4 w-4 text-emerald-600" />
                                                        Newborn Identity & Outcome
                                                    </h4>

                                                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                                        <div className="space-y-1.5">
                                                            <Label htmlFor="del_delivered_at">Delivery Date & Time *</Label>
                                                            <Input
                                                                id="del_delivered_at"
                                                                type="datetime-local"
                                                                required
                                                                value={deliveryForm.data.delivered_at}
                                                                onChange={(e) => deliveryForm.setData('delivered_at', e.target.value)}
                                                            />
                                                        </div>

                                                        <div className="space-y-1.5">
                                                            <Label htmlFor="del_baby_outcome">Birth Outcome *</Label>
                                                            <Select
                                                                value={deliveryForm.data.baby_outcome}
                                                                onValueChange={(val) => deliveryForm.setData('baby_outcome', val)}
                                                            >
                                                                <SelectTrigger id="del_baby_outcome">
                                                                    <SelectValue placeholder="Outcome" />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    {Object.entries(babyOutcomes).map(([k, label]) => (
                                                                        <SelectItem key={k} value={k}>
                                                                            {label}
                                                                        </SelectItem>
                                                                    ))}
                                                                </SelectContent>
                                                            </Select>
                                                        </div>

                                                        <div className="space-y-1.5">
                                                            <Label htmlFor="del_baby_gender">Newborn Sex *</Label>
                                                            <Select
                                                                value={deliveryForm.data.baby_gender}
                                                                onValueChange={(val) => deliveryForm.setData('baby_gender', val)}
                                                            >
                                                                <SelectTrigger id="del_baby_gender">
                                                                    <SelectValue placeholder="Sex" />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    <SelectItem value="female">Female</SelectItem>
                                                                    <SelectItem value="male">Male</SelectItem>
                                                                    <SelectItem value="ambiguous">Ambiguous</SelectItem>
                                                                </SelectContent>
                                                            </Select>
                                                        </div>
                                                    </div>

                                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                        <div className="space-y-1.5">
                                                            <Label htmlFor="del_baby_name">Newborn Name / Identifier</Label>
                                                            <Input
                                                                id="del_baby_name"
                                                                placeholder={`e.g. Baby of ${patientName}`}
                                                                value={deliveryForm.data.baby_name}
                                                                onChange={(e) => deliveryForm.setData('baby_name', e.target.value)}
                                                            />
                                                        </div>

                                                        <div className="space-y-1.5">
                                                            <Label htmlFor="del_birth_order">Birth Order (Order of Birth)</Label>
                                                            <Input
                                                                id="del_birth_order"
                                                                type="number"
                                                                min="1"
                                                                max="10"
                                                                placeholder="1 for singleton / first twin"
                                                                value={deliveryForm.data.birth_order}
                                                                onChange={(e) => deliveryForm.setData('birth_order', parseInt(e.target.value) || 1)}
                                                            />
                                                        </div>
                                                    </div>
                                                </div>

                                                {/* Physical Measurements & APGAR */}
                                                <div className="rounded-lg border p-3.5 bg-muted/20 space-y-3">
                                                    <h4 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground flex items-center gap-1.5">
                                                        <Scale className="h-4 w-4 text-emerald-600" />
                                                        Birth Anthropometry & APGAR Scores
                                                    </h4>

                                                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                                        <div className="space-y-1.5">
                                                            <Label htmlFor="del_weight">Birth Weight (grams) *</Label>
                                                            <Input
                                                                id="del_weight"
                                                                type="number"
                                                                placeholder="e.g. 3200 g"
                                                                value={deliveryForm.data.birth_weight_grams}
                                                                onChange={(e) => deliveryForm.setData('birth_weight_grams', e.target.value)}
                                                            />
                                                        </div>

                                                        <div className="space-y-1.5">
                                                            <Label htmlFor="del_length">Birth Length (cm)</Label>
                                                            <Input
                                                                id="del_length"
                                                                type="number"
                                                                step="0.1"
                                                                placeholder="e.g. 50.0 cm"
                                                                value={deliveryForm.data.birth_length_cm}
                                                                onChange={(e) => deliveryForm.setData('birth_length_cm', e.target.value)}
                                                            />
                                                        </div>

                                                        <div className="space-y-1.5">
                                                            <Label htmlFor="del_head">Head Circumference (cm)</Label>
                                                            <Input
                                                                id="del_head"
                                                                type="number"
                                                                step="0.1"
                                                                placeholder="e.g. 34.5 cm"
                                                                value={deliveryForm.data.head_circumference_cm}
                                                                onChange={(e) => deliveryForm.setData('head_circumference_cm', e.target.value)}
                                                            />
                                                        </div>
                                                    </div>

                                                    <div className="grid grid-cols-3 gap-3">
                                                        <div className="space-y-1.5">
                                                            <Label htmlFor="del_apgar1">APGAR 1 min (/10)</Label>
                                                            <Input
                                                                id="del_apgar1"
                                                                type="number"
                                                                min="0"
                                                                max="10"
                                                                placeholder="0 - 10"
                                                                value={deliveryForm.data.apgar_1min}
                                                                onChange={(e) => deliveryForm.setData('apgar_1min', e.target.value)}
                                                            />
                                                        </div>

                                                        <div className="space-y-1.5">
                                                            <Label htmlFor="del_apgar5">APGAR 5 min (/10)</Label>
                                                            <Input
                                                                id="del_apgar5"
                                                                type="number"
                                                                min="0"
                                                                max="10"
                                                                placeholder="0 - 10"
                                                                value={deliveryForm.data.apgar_5min}
                                                                onChange={(e) => deliveryForm.setData('apgar_5min', e.target.value)}
                                                            />
                                                        </div>

                                                        <div className="space-y-1.5">
                                                            <Label htmlFor="del_apgar10">APGAR 10 min (/10)</Label>
                                                            <Input
                                                                id="del_apgar10"
                                                                type="number"
                                                                min="0"
                                                                max="10"
                                                                placeholder="0 - 10"
                                                                value={deliveryForm.data.apgar_10min}
                                                                onChange={(e) => deliveryForm.setData('apgar_10min', e.target.value)}
                                                            />
                                                        </div>
                                                    </div>

                                                    {/* Immediate Newborn Prophylaxis */}
                                                    <div className="pt-2 border-t space-y-1.5">
                                                        <Label className="text-xs">Immediate Prophylaxis & Care</Label>
                                                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                                                            <label className="flex items-center gap-2 cursor-pointer">
                                                                <input
                                                                    type="checkbox"
                                                                    checked={deliveryForm.data.vitamin_k_given}
                                                                    onChange={(e) => deliveryForm.setData('vitamin_k_given', e.target.checked)}
                                                                    className="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                                                                />
                                                                <span>Vitamin K1 (1mg IM)</span>
                                                            </label>

                                                            <label className="flex items-center gap-2 cursor-pointer">
                                                                <input
                                                                    type="checkbox"
                                                                    checked={deliveryForm.data.eye_ointment_given}
                                                                    onChange={(e) => deliveryForm.setData('eye_ointment_given', e.target.checked)}
                                                                    className="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                                                                />
                                                                <span>Tetracycline Eye Oint.</span>
                                                            </label>

                                                            <label className="flex items-center gap-2 cursor-pointer">
                                                                <input
                                                                    type="checkbox"
                                                                    checked={deliveryForm.data.bcg_given}
                                                                    onChange={(e) => deliveryForm.setData('bcg_given', e.target.checked)}
                                                                    className="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                                                                />
                                                                <span>BCG Vaccine</span>
                                                            </label>

                                                            <label className="flex items-center gap-2 cursor-pointer">
                                                                <input
                                                                    type="checkbox"
                                                                    checked={deliveryForm.data.polio_0_given}
                                                                    onChange={(e) => deliveryForm.setData('polio_0_given', e.target.checked)}
                                                                    className="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                                                                />
                                                                <span>Polio 0 (Birth dose)</span>
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>

                                                {/* Maternal Delivery Information */}
                                                <div className="rounded-lg border p-3.5 bg-muted/20 space-y-3">
                                                    <h4 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground flex items-center gap-1.5">
                                                        <HeartPulse className="h-4 w-4 text-rose-600" />
                                                        Maternal Delivery & Third Stage
                                                    </h4>

                                                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                                        <div className="space-y-1.5">
                                                            <Label htmlFor="del_mode">Delivery Mode *</Label>
                                                            <Select
                                                                value={deliveryForm.data.delivery_mode}
                                                                onValueChange={(val) => deliveryForm.setData('delivery_mode', val)}
                                                            >
                                                                <SelectTrigger id="del_mode">
                                                                    <SelectValue placeholder="Mode" />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    {Object.entries(deliveryModes).map(([k, label]) => (
                                                                        <SelectItem key={k} value={k}>
                                                                            {label}
                                                                        </SelectItem>
                                                                    ))}
                                                                </SelectContent>
                                                            </Select>
                                                        </div>

                                                        <div className="space-y-1.5">
                                                            <Label htmlFor="del_perineal">Perineal Status</Label>
                                                            <Select
                                                                value={deliveryForm.data.perineal_status}
                                                                onValueChange={(val) => deliveryForm.setData('perineal_status', val)}
                                                            >
                                                                <SelectTrigger id="del_perineal">
                                                                    <SelectValue placeholder="Perineum" />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    {Object.entries(perinealStatuses).map(([k, label]) => (
                                                                        <SelectItem key={k} value={k}>
                                                                            {label}
                                                                        </SelectItem>
                                                                    ))}
                                                                </SelectContent>
                                                            </Select>
                                                        </div>

                                                        <div className="space-y-1.5">
                                                            <Label htmlFor="del_blood_loss">Estimated Blood Loss (ml)</Label>
                                                            <Input
                                                                id="del_blood_loss"
                                                                type="number"
                                                                placeholder="e.g. 200 ml"
                                                                value={deliveryForm.data.blood_loss_ml}
                                                                onChange={(e) => deliveryForm.setData('blood_loss_ml', e.target.value)}
                                                            />
                                                        </div>
                                                    </div>

                                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                        <div className="space-y-1.5">
                                                            <Label htmlFor="del_placenta_mode">Placenta Delivery</Label>
                                                            <Input
                                                                id="del_placenta_mode"
                                                                placeholder="e.g. Controlled cord traction (CCT) / Spontaneous"
                                                                value={deliveryForm.data.placenta_mode}
                                                                onChange={(e) => deliveryForm.setData('placenta_mode', e.target.value)}
                                                            />
                                                        </div>

                                                        <div className="space-y-1.5">
                                                            <Label htmlFor="del_oxytocic">Uterotonic Given</Label>
                                                            <Input
                                                                id="del_oxytocic"
                                                                placeholder="e.g. Oxytocin 10 IU IM"
                                                                value={deliveryForm.data.oxytocic_given}
                                                                onChange={(e) => deliveryForm.setData('oxytocic_given', e.target.value)}
                                                            />
                                                        </div>
                                                    </div>
                                                </div>

                                                <div className="space-y-1.5">
                                                    <Label htmlFor="del_notes">Clinical Delivery Notes & Remarks</Label>
                                                    <Textarea
                                                        id="del_notes"
                                                        rows={2}
                                                        placeholder="Document delivery details, maternal tolerance, early bonding, newborn latching..."
                                                        value={deliveryForm.data.notes}
                                                        onChange={(e) => deliveryForm.setData('notes', e.target.value)}
                                                    />
                                                </div>

                                                <div className="flex justify-end">
                                                    <Button
                                                        type="submit"
                                                        disabled={deliveryForm.processing}
                                                        className="bg-emerald-600 hover:bg-emerald-700 text-white font-medium"
                                                    >
                                                        <Sparkles className="mr-1.5 h-4 w-4" />
                                                        Document Delivery & Create Newborn Record
                                                    </Button>
                                                </div>
                                            </form>
                                        </CardContent>
                                    </Card>

                                    {/* Column 3: Delivered Babies List */}
                                    <div className="space-y-4">
                                        <Card>
                                            <CardHeader>
                                                <CardTitle className="text-base flex items-center gap-2">
                                                    <Baby className="h-5 w-5 text-emerald-600" />
                                                    Delivered Newborns ({(deliveryRecords ?? []).length})
                                                </CardTitle>
                                                <CardDescription>
                                                    Independent clinical patient records created for each newborn from this delivery.
                                                </CardDescription>
                                            </CardHeader>
                                            <CardContent className="space-y-3">
                                                {(deliveryRecords ?? []).length === 0 ? (
                                                    <div className="py-8 text-center text-xs text-muted-foreground">
                                                        No delivery documented for this maternity visit yet.
                                                    </div>
                                                ) : (
                                                    (deliveryRecords ?? []).map((dr) => (
                                                        <div key={dr.id} className="rounded-lg border p-3 bg-muted/20 space-y-2">
                                                            <div className="flex items-center justify-between">
                                                                <span className="font-bold text-sm text-foreground">
                                                                    {dr.baby_name || `Baby #${dr.birth_order}`}
                                                                </span>
                                                                <Badge variant="outline" className="text-xs capitalize">
                                                                    {dr.baby_outcome?.replace('_', ' ')}
                                                                </Badge>
                                                            </div>

                                                            <div className="grid grid-cols-2 gap-1 text-xs text-muted-foreground">
                                                                <div>Patient #: <strong className="text-foreground">{dr.baby_patient?.patient_number || 'â€”'}</strong></div>
                                                                <div>Sex: <strong className="text-foreground capitalize">{dr.baby_gender}</strong></div>
                                                                <div>Birth Weight: <strong className="text-foreground">{dr.birth_weight_grams ? `${dr.birth_weight_grams} g` : 'â€”'}</strong></div>
                                                                <div>APGAR: <strong className="text-foreground">{dr.apgar_1min ?? 'â€”'}/10 (1m)</strong></div>
                                                            </div>

                                                            {dr.baby_patient_id && (
                                                                <div className="pt-2 border-t flex justify-end">
                                                                    <Button
                                                                        size="sm"
                                                                        variant="outline"
                                                                        className="text-xs h-7 gap-1 border-cyan-300 text-cyan-700 hover:bg-cyan-50 dark:border-cyan-800 dark:text-cyan-300"
                                                                        onClick={() => setSelectedBabyId(dr.baby_patient_id ?? null)}
                                                                    >
                                                                        <LineChart className="h-3 w-3" />
                                                                        View Growth Charts
                                                                    </Button>
                                                                </div>
                                                            )}
                                                        </div>
                                                    ))
                                                )}
                                            </CardContent>
                                        </Card>
                                    </div>
                                </div>
                            </TabsContent>

                            {/* â•â•â•â•â•â•â•â•â• TAB: NEWBORN GROWTH & DEVELOPMENT MONITORING (PHASE 8) â•â•â•â•â•â•â•â•â• */}
                            <TabsContent value="newborn" className="space-y-6">
                                {liveBabies.length === 0 ? (
                                    <Card>
                                        <CardContent className="py-12 text-center space-y-3">
                                            <Baby className="h-12 w-12 text-muted-foreground/40 mx-auto" />
                                            <h3 className="font-bold text-base">No Newborn Record Active</h3>
                                            <p className="text-sm text-muted-foreground max-w-md mx-auto">
                                                Newborn growth and developmental monitoring is available once a live delivery is documented in the "Delivery Record" tab.
                                            </p>
                                        </CardContent>
                                    </Card>
                                ) : (
                                    <div className="space-y-6">
                                        {/* Baby Selection if multiple births */}
                                        {liveBabies.length > 1 && (
                                            <div className="flex items-center gap-3 bg-muted/40 p-3 rounded-lg border">
                                                <span className="text-xs font-semibold text-muted-foreground uppercase">Select Child:</span>
                                                <div className="flex gap-2">
                                                    {liveBabies.map((b) => (
                                                        <Button
                                                            key={b.id}
                                                            size="sm"
                                                            variant={selectedBabyId === b.baby_patient_id ? 'default' : 'outline'}
                                                            className="text-xs h-8"
                                                            onClick={() => setSelectedBabyId(b.baby_patient_id ?? null)}
                                                        >
                                                            {b.baby_name || `Baby #${b.birth_order}`} ({b.baby_patient?.patient_number})
                                                        </Button>
                                                    ))}
                                                </div>
                                            </div>
                                        )}

                                        {(() => {
                                            const activeDelivery = liveBabies.find((b) => b.baby_patient_id === selectedBabyId) || liveBabies[0];
                                            const growthList = activeDelivery?.baby_patient?.newborn_growth_records ?? [];

                                            return (
                                                <div className="grid grid-cols-1 xl:grid-cols-3 gap-6 [&>*]:min-w-0">
                                                    {/* Column 1: Record Growth Entry Form */}
                                                    <Card className="xl:col-span-1">
                                                        <CardHeader>
                                                            <CardTitle className="text-base flex items-center gap-2">
                                                                <Plus className="h-5 w-5 text-cyan-600" />
                                                                Record Growth Observation
                                                            </CardTitle>
                                                            <CardDescription>
                                                                Log serial weight, length, head circumference, feeding, and milestones.
                                                            </CardDescription>
                                                        </CardHeader>
                                                        <CardContent>
                                                            <form onSubmit={handleGrowthSubmit} className="space-y-4">
                                                                <div className="space-y-1.5">
                                                                    <Label htmlFor="g_recorded_at">Measurement Time *</Label>
                                                                    <Input
                                                                        id="g_recorded_at"
                                                                        type="datetime-local"
                                                                        required
                                                                        value={growthForm.data.recorded_at}
                                                                        onChange={(e) => growthForm.setData('recorded_at', e.target.value)}
                                                                    />
                                                                </div>

                                                                <div className="grid grid-cols-2 gap-3">
                                                                    <div className="space-y-1.5">
                                                                        <Label htmlFor="g_weight">Weight (kg) *</Label>
                                                                        <Input
                                                                            id="g_weight"
                                                                            type="number"
                                                                            step="0.01"
                                                                            placeholder="e.g. 4.2 kg"
                                                                            required
                                                                            value={growthForm.data.weight_kg}
                                                                            onChange={(e) => growthForm.setData('weight_kg', e.target.value)}
                                                                        />
                                                                    </div>

                                                                    <div className="space-y-1.5">
                                                                        <Label htmlFor="g_length">Length / Height (cm) *</Label>
                                                                        <Input
                                                                            id="g_length"
                                                                            type="number"
                                                                            step="0.1"
                                                                            placeholder="e.g. 52.0 cm"
                                                                            required
                                                                            value={growthForm.data.length_height_cm}
                                                                            onChange={(e) => growthForm.setData('length_height_cm', e.target.value)}
                                                                        />
                                                                    </div>
                                                                </div>

                                                                <div className="grid grid-cols-2 gap-3">
                                                                    <div className="space-y-1.5">
                                                                        <Label htmlFor="g_head">Head Circumf. (cm) *</Label>
                                                                        <Input
                                                                            id="g_head"
                                                                            type="number"
                                                                            step="0.1"
                                                                            placeholder="e.g. 36.0 cm"
                                                                            required
                                                                            value={growthForm.data.head_circumference_cm}
                                                                            onChange={(e) => growthForm.setData('head_circumference_cm', e.target.value)}
                                                                        />
                                                                    </div>

                                                                    <div className="space-y-1.5">
                                                                        <Label htmlFor="g_temp">Temperature (Â°C)</Label>
                                                                        <Input
                                                                            id="g_temp"
                                                                            type="number"
                                                                            step="0.1"
                                                                            placeholder="36.5 Â°C"
                                                                            value={growthForm.data.temperature_c}
                                                                            onChange={(e) => growthForm.setData('temperature_c', e.target.value)}
                                                                        />
                                                                    </div>
                                                                </div>

                                                                <div className="space-y-1.5">
                                                                    <Label htmlFor="g_feeding">Feeding Method *</Label>
                                                                    <Select
                                                                        value={growthForm.data.feeding_method}
                                                                        onValueChange={(val) => growthForm.setData('feeding_method', val)}
                                                                    >
                                                                        <SelectTrigger id="g_feeding">
                                                                            <SelectValue placeholder="Feeding Method" />
                                                                        </SelectTrigger>
                                                                        <SelectContent>
                                                                            {Object.entries(feedingMethods).map(([k, label]) => (
                                                                                <SelectItem key={k} value={k}>
                                                                                    {label}
                                                                                </SelectItem>
                                                                            ))}
                                                                        </SelectContent>
                                                                    </Select>
                                                                </div>

                                                                <div className="space-y-1.5">
                                                                    <Label htmlFor="g_milestones">Developmental Observations & Milestones</Label>
                                                                    <Textarea
                                                                        id="g_milestones"
                                                                        rows={2}
                                                                        placeholder="e.g. Social smile present, tracks objects, responds to voice"
                                                                        value={growthForm.data.developmental_milestones}
                                                                        onChange={(e) => growthForm.setData('developmental_milestones', e.target.value)}
                                                                    />
                                                                </div>

                                                                <div className="space-y-1.5">
                                                                    <Label htmlFor="g_notes">Clinical Notes</Label>
                                                                    <Textarea
                                                                        id="g_notes"
                                                                        rows={2}
                                                                        placeholder="Feeding tolerance, cord status, maternal observations..."
                                                                        value={growthForm.data.clinical_notes}
                                                                        onChange={(e) => growthForm.setData('clinical_notes', e.target.value)}
                                                                    />
                                                                </div>

                                                                <div className="text-[11px] text-muted-foreground bg-cyan-50 dark:bg-cyan-950/30 p-2 rounded border border-cyan-200 dark:border-cyan-900/50">
                                                                    Reference Standard: <strong>WHO Child Growth Standards 2006</strong>
                                                                </div>

                                                                <Button
                                                                    type="submit"
                                                                    disabled={growthForm.processing || !selectedBabyId}
                                                                    className="w-full bg-cyan-600 hover:bg-cyan-700 text-white font-medium"
                                                                >
                                                                    <Save className="mr-1.5 h-4 w-4" />
                                                                    Log Growth Measurement
                                                                </Button>
                                                            </form>
                                                        </CardContent>
                                                    </Card>

                                                    {/* Column 2 & 3: Growth History Table & Charts */}
                                                    <Card className="xl:col-span-2">
                                                        <CardHeader>
                                                            <CardTitle className="text-base flex items-center justify-between">
                                                                <span className="flex items-center gap-2">
                                                                    <LineChart className="h-5 w-5 text-cyan-600" />
                                                                    Growth & Milestone Progression ({growthList.length})
                                                                </span>
                                                                <Badge variant="outline" className="border-cyan-300 text-cyan-700">
                                                                    Child: {activeDelivery?.baby_name || `Baby #${activeDelivery?.birth_order}`} ({activeDelivery?.baby_patient?.patient_number})
                                                                </Badge>
                                                            </CardTitle>
                                                            <CardDescription>
                                                                WHO Child Growth Standards 2006 serial tracking of weight, length, and head circumference.
                                                            </CardDescription>
                                                        </CardHeader>
                                                        <CardContent>
                                                            {growthList.length === 0 ? (
                                                                <div className="py-8 text-center text-xs text-muted-foreground">
                                                                    No growth records logged yet for this infant.
                                                                </div>
                                                            ) : (
                                                                <div className="overflow-x-auto">
                                                                    <Table>
                                                                        <TableHeader>
                                                                            <TableRow className="bg-muted/40">
                                                                                <TableHead className="w-[120px]">Date</TableHead>
                                                                                <TableHead>Age</TableHead>
                                                                                <TableHead>Weight</TableHead>
                                                                                <TableHead>Length</TableHead>
                                                                                <TableHead>Head Circ.</TableHead>
                                                                                <TableHead>Feeding</TableHead>
                                                                                <TableHead>Milestones</TableHead>
                                                                                <TableHead>Recorded By</TableHead>
                                                                            </TableRow>
                                                                        </TableHeader>
                                                                        <TableBody>
                                                                            {growthList.map((gr) => (
                                                                                <TableRow key={gr.id}>
                                                                                    <TableCell className="font-mono text-xs">
                                                                                        {formatDateTime(gr.recorded_at)}
                                                                                    </TableCell>
                                                                                    <TableCell className="font-bold text-xs">
                                                                                        {gr.age_days === 0 ? 'Birth' : `${gr.age_days} days (${(gr.age_days / 7).toFixed(1)} wks)`}
                                                                                    </TableCell>
                                                                                    <TableCell className="font-semibold text-xs text-emerald-700 dark:text-emerald-400">
                                                                                        {gr.weight_kg} kg
                                                                                    </TableCell>
                                                                                    <TableCell className="text-xs">
                                                                                        {gr.length_height_cm} cm
                                                                                    </TableCell>
                                                                                    <TableCell className="text-xs">
                                                                                        {gr.head_circumference_cm} cm
                                                                                    </TableCell>
                                                                                    <TableCell className="text-xs capitalize">
                                                                                        {gr.feeding_method?.replace(/_/g, ' ')}
                                                                                    </TableCell>
                                                                                    <TableCell className="text-xs max-w-xs truncate" title={gr.developmental_milestones ?? ''}>
                                                                                        {gr.developmental_milestones || 'â€”'}
                                                                                    </TableCell>
                                                                                    <TableCell className="text-xs text-muted-foreground">
                                                                                        {gr.recorded_by?.UserName ?? 'Clinician'}
                                                                                    </TableCell>
                                                                                </TableRow>
                                                                            ))}
                                                                        </TableBody>
                                                                    </Table>
                                                                </div>
                                                            )}
                                                        </CardContent>
                                                    </Card>
                                                </div>
                                            );
                                        })()}
                                    </div>
                                )}
                            </TabsContent>

                            {/* â•â•â•â•â•â•â•â•â• TAB 3: PAST MATERNITY & OBSTETRIC HISTORY â•â•â•â•â•â•â•â•â• */}
                            <TabsContent value="history" className="space-y-4">
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base flex items-center gap-2">
                                            <History className="h-5 w-5 text-purple-600" />
                                            Prior Hospital Maternity Episodes ({previousProfiles.length})
                                        </CardTitle>
                                        <CardDescription>
                                            Records of previous deliveries or maternity visits documented for this mother in this hospital.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        {previousProfiles.length === 0 ? (
                                            <div className="text-center py-8 text-muted-foreground text-sm">
                                                No prior maternity episodes recorded in the system for this patient.
                                            </div>
                                        ) : (
                                            <div className="space-y-4">
                                                {previousProfiles.map((prev) => (
                                                    <div
                                                        key={prev.id}
                                                        className="border rounded-lg p-4 bg-muted/20 space-y-2 text-sm"
                                                    >
                                                        <div className="flex flex-wrap items-center justify-between gap-2 border-b pb-2">
                                                            <span className="font-semibold text-foreground">
                                                                Episode #{prev.episode_number || `MAT-${prev.id}`}
                                                            </span>
                                                            <span className="text-xs text-muted-foreground">
                                                                Recorded: {formatDateTime(prev.created_at)} by {prev.recorded_by?.UserName ?? 'Clinician'}
                                                            </span>
                                                        </div>

                                                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                                                            <div>Parity: <strong>G{prev.gravida} P{prev.para}</strong></div>
                                                            <div>Gestation: <strong>{prev.gestational_age_weeks ?? 'â€”'} weeks</strong></div>
                                                            <div>Delivery Mode: <strong>{prev.delivery_record ? 'Delivered' : 'ANC Only'}</strong></div>
                                                            <div>Risk: <strong className="capitalize">{prev.risk_classification ?? 'Low'}</strong></div>
                                                        </div>

                                                        {prev.notes && (
                                                            <p className="text-xs text-muted-foreground pt-1 italic">
                                                                "{prev.notes}"
                                                            </p>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            </TabsContent>

                            {/* â•â•â•â•â•â•â•â•â• TAB: MATERNITY FOLLOW-UP & DISCHARGE PLAN (PHASE 9) â•â•â•â•â•â•â•â•â• */}
                            <TabsContent value="follow-up" className="space-y-6">
                                <div className="grid grid-cols-1 xl:grid-cols-3 gap-6 [&>*]:min-w-0">
                                    <div className="xl:col-span-2 space-y-6">
                                        {profile.follow_up_date && (
                                            <div className="p-4 rounded-xl border border-emerald-300 bg-emerald-50/80 dark:border-emerald-800 dark:bg-emerald-950/30 flex items-start justify-between gap-4">
                                                <div className="flex items-start gap-3">
                                                    <Calendar className="h-6 w-6 text-emerald-600 mt-0.5 shrink-0" />
                                                    <div>
                                                        <h4 className="font-semibold text-emerald-900 dark:text-emerald-200">
                                                            Scheduled Follow-up: {new Date(profile.follow_up_date).toLocaleDateString(undefined, { dateStyle: 'full' })}
                                                        </h4>
                                                        <p className="text-sm text-emerald-800 dark:text-emerald-300 mt-1 capitalize">
                                                            Purpose: <span className="font-medium">{profile.follow_up_purpose?.replace(/_/g, ' ') || 'Routine Follow-up'}</span>
                                                        </p>
                                                        {profile.follow_up_notes && (
                                                            <p className="text-xs text-emerald-700 dark:text-emerald-400 mt-1 italic">
                                                                "{profile.follow_up_notes}"
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>
                                                <Badge className="bg-emerald-600 text-white shrink-0">Scheduled</Badge>
                                            </div>
                                        )}

                                        <Card>
                                            <CardHeader>
                                                <CardTitle className="text-base flex items-center gap-2">
                                                    <Calendar className="h-5 w-5 text-emerald-600" />
                                                    Maternity Outpatient Follow-up & Discharge Plan
                                                </CardTitle>
                                                <CardDescription>
                                                    Schedule the next visit date and clinical instructions for the mother/infant, and finalize the outpatient visit status.
                                                </CardDescription>
                                            </CardHeader>
                                            <CardContent>
                                                <form onSubmit={handleFollowUpSubmit} className="space-y-4">
                                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                        <div className="space-y-2">
                                                            <Label htmlFor="fu_date">Follow-up Appointment Date *</Label>
                                                            <Input
                                                                id="fu_date"
                                                                type="date"
                                                                value={followUpForm.data.follow_up_date}
                                                                onChange={(e) => followUpForm.setData('follow_up_date', e.target.value)}
                                                                min={new Date().toISOString().slice(0, 10)}
                                                            />
                                                            {followUpForm.errors.follow_up_date && (
                                                                <p className="text-xs text-red-500">{followUpForm.errors.follow_up_date}</p>
                                                            )}
                                                        </div>

                                                        <div className="space-y-2">
                                                            <Label htmlFor="fu_purpose">Follow-up Purpose</Label>
                                                            <Select
                                                                value={followUpForm.data.follow_up_purpose}
                                                                onValueChange={(val) => followUpForm.setData('follow_up_purpose', val)}
                                                            >
                                                                <SelectTrigger id="fu_purpose">
                                                                    <SelectValue placeholder="Select purpose" />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    <SelectItem value="anc_routine">Routine Antenatal Care (ANC)</SelectItem>
                                                                    <SelectItem value="pnc_3_day">Postnatal Care - Day 3 Check</SelectItem>
                                                                    <SelectItem value="pnc_7_day">Postnatal Care - Day 7 Check</SelectItem>
                                                                    <SelectItem value="pnc_6_week">Postnatal Care - 6 Week Comprehensive</SelectItem>
                                                                    <SelectItem value="high_risk_review">High-Risk Obstetric Review</SelectItem>
                                                                    <SelectItem value="lab_ultrasound_review">Lab / Ultrasound Result Review</SelectItem>
                                                                    <SelectItem value="growth_monitoring">Child Growth & Immunization Review</SelectItem>
                                                                    <SelectItem value="other">Other Clinical Follow-up</SelectItem>
                                                                </SelectContent>
                                                            </Select>
                                                            {followUpForm.errors.follow_up_purpose && (
                                                                <p className="text-xs text-red-500">{followUpForm.errors.follow_up_purpose}</p>
                                                            )}
                                                        </div>
                                                    </div>

                                                    <div className="space-y-2">
                                                        <Label htmlFor="fu_status">Visit Queue Status Upon Saving</Label>
                                                        <Select
                                                            value={followUpForm.data.maternity_status}
                                                            onValueChange={(val) => followUpForm.setData('maternity_status', val)}
                                                        >
                                                            <SelectTrigger id="fu_status">
                                                                <SelectValue placeholder="Select status" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                {Object.entries(maternityStatuses).map(([key, label]) => (
                                                                    <SelectItem key={key} value={key}>
                                                                        {label}
                                                                    </SelectItem>
                                                                ))}
                                                            </SelectContent>
                                                        </Select>
                                                        <p className="text-xs text-muted-foreground">
                                                            Selecting 'Follow-up Required' keeps the episode active. Selecting 'Completed' discharges this outpatient visit.
                                                        </p>
                                                    </div>

                                                    <div className="space-y-2">
                                                        <Label htmlFor="fu_notes">Clinical Instructions & Patient Advice</Label>
                                                        <Textarea
                                                            id="fu_notes"
                                                            rows={4}
                                                            placeholder="e.g. Danger signs counseling provided (headache, bleeding, blurred vision, reduced fetal movement). Prescribed Iron/Folate. Return immediately if water breaks or contractions start."
                                                            value={followUpForm.data.follow_up_notes}
                                                            onChange={(e) => followUpForm.setData('follow_up_notes', e.target.value)}
                                                        />
                                                        {followUpForm.errors.follow_up_notes && (
                                                            <p className="text-xs text-red-500">{followUpForm.errors.follow_up_notes}</p>
                                                        )}
                                                    </div>

                                                    <div className="flex justify-end pt-2">
                                                        <Button
                                                            type="submit"
                                                            disabled={followUpForm.processing}
                                                            className="bg-emerald-600 hover:bg-emerald-700 text-white gap-2"
                                                        >
                                                            <Save className="h-4 w-4" />
                                                            {followUpForm.processing ? 'Saving Plan...' : 'Save Follow-up Plan'}
                                                        </Button>
                                                    </div>
                                                </form>
                                            </CardContent>
                                        </Card>
                                    </div>

                                    {/* Guidelines Column */}
                                    <div className="space-y-4">
                                        <Card className="border-pink-200 dark:border-pink-900/40">
                                            <CardHeader className="pb-3">
                                                <CardTitle className="text-sm font-semibold flex items-center gap-2">
                                                    <Clock className="h-4 w-4 text-pink-600" />
                                                    WHO Contact Schedule
                                                </CardTitle>
                                            </CardHeader>
                                            <CardContent className="space-y-3 text-xs text-muted-foreground">
                                                <div className="border-b pb-2">
                                                    <p className="font-semibold text-foreground">Antenatal Care (8 Contacts)</p>
                                                    <p className="text-[11px] mt-0.5">Contact 1: &lt;12 weeks</p>
                                                    <p className="text-[11px]">Contact 2: 20 weeks</p>
                                                    <p className="text-[11px]">Contact 3: 26 weeks</p>
                                                    <p className="text-[11px]">Contact 4: 30 weeks</p>
                                                    <p className="text-[11px]">Contact 5: 34 weeks</p>
                                                    <p className="text-[11px]">Contact 6: 36 weeks</p>
                                                    <p className="text-[11px]">Contact 7: 38 weeks</p>
                                                    <p className="text-[11px]">Contact 8: 40 weeks</p>
                                                </div>

                                                <div>
                                                    <p className="font-semibold text-foreground">Postnatal Care (4 Contacts)</p>
                                                    <p className="text-[11px] mt-0.5">Contact 1: First 24 hours</p>
                                                    <p className="text-[11px]">Contact 2: Day 3 (48â€“72 hours)</p>
                                                    <p className="text-[11px]">Contact 3: Between Day 7â€“14</p>
                                                    <p className="text-[11px]">Contact 4: 6 weeks postpartum</p>
                                                </div>
                                            </CardContent>
                                        </Card>

                                        <Card>
                                            <CardHeader className="pb-3">
                                                <CardTitle className="text-sm font-semibold flex items-center gap-2">
                                                    <ShieldAlert className="h-4 w-4 text-amber-600" />
                                                    Danger Signs Advice
                                                </CardTitle>
                                            </CardHeader>
                                            <CardContent className="text-xs text-muted-foreground space-y-1.5">
                                                <p className="font-medium text-foreground">Advise mother to return immediately if:</p>
                                                <ul className="list-disc pl-4 space-y-1 text-[11px]">
                                                    <li>Vaginal bleeding or fluid leaking</li>
                                                    <li>Severe headache or blurred vision</li>
                                                    <li>Severe abdominal pain or epigastric pain</li>
                                                    <li>High fever or chills</li>
                                                    <li>Decreased or absent fetal movements</li>
                                                    <li>Swelling of face, hands, or legs</li>
                                                </ul>
                                            </CardContent>
                                        </Card>
                                    </div>
                                </div>
                            </TabsContent>

                            {/* ═════════ TAB 9: MATERNITY CLINICAL ORDERS & PRESCRIPTIONS ═════════ */}
                            <TabsContent value="orders" className="space-y-6">
                                {!patientInsurance ? (
                                    <Alert className="border-emerald-500/40 bg-emerald-500/10 text-emerald-950 dark:border-emerald-500/30 dark:bg-emerald-950/20 dark:text-emerald-100">
                                        <Banknote className="h-5 w-5 text-emerald-600 dark:text-emerald-400 mt-0.5" />
                                        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 w-full">
                                            <div>
                                                <AlertTitle className="text-sm font-semibold flex items-center gap-2">
                                                    Cash / Self-Pay Patient Mode
                                                    <Badge variant="outline" className="border-emerald-600 text-emerald-700 dark:text-emerald-300 text-[10px] font-normal">
                                                        Direct Maternity Billing
                                                    </Badge>
                                                </AlertTitle>
                                                <AlertDescription className="text-xs text-muted-foreground mt-0.5">
                                                    Write maternity medications to pharmacy, order antenatal or urgent laboratory tests, and request obstetric ultrasound scans directly from this assessment.
                                                </AlertDescription>
                                            </div>
                                            <div className="flex flex-wrap items-center gap-2 shrink-0">
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
                                            </div>
                                        </div>
                                    </Alert>
                                ) : (
                                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/20 p-3">
                                        <div>
                                            <h3 className="text-sm font-semibold flex items-center gap-2">
                                                Maternity Clinical Orders & Prescriptions
                                                <Badge variant="outline" className="text-[10px]">
                                                    NHIF Insured ({patientInsurance.membership_number})
                                                </Badge>
                                            </h3>
                                            <p className="text-xs text-muted-foreground">
                                                Write maternity medications to pharmacy, order diagnostic lab investigations, and request obstetric ultrasound scans.
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-2">
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
                                        </div>
                                    </div>
                                )}

                                {/* 1. Pharmacy Medication Orders Card */}
                                <Card>
                                    <CardHeader className="pb-3">
                                        <div className="flex items-center justify-between">
                                            <CardTitle className="text-base flex items-center gap-2">
                                                <Pill className="h-4 w-4 text-purple-600" />
                                                Maternity Prescriptions Written to Pharmacy ({prescriptionOrders.length})
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
                                        <CardDescription>
                                            Medications prescribed for this mother (iron/folate, calcium, antihypertensives, uterotonics, antibiotics, analgesics)
                                        </CardDescription>
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
                                                                    {new Date(rx.created_at).toLocaleDateString([], { month: 'short', day: 'numeric' })} ·{' '}
                                                                    {new Date(rx.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
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
                                                                {(rx.prescriber as any)?.UserName ?? rx.prescriber?.name ?? 'Nurse / Prescriber'}
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
                                                <p className="font-medium text-neutral-700 dark:text-neutral-300">
                                                    No medications prescribed for this maternity visit yet.
                                                </p>
                                                <p className="text-[11px] mt-0.5">
                                                    Click "Write Medication" above to prescribe antenatal supplements, antihypertensives, antibiotics, or uterotonics to pharmacy.
                                                </p>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>

                                {/* 2. Laboratory Orders Card */}
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
                                        <CardDescription>
                                            Antenatal screening, CBC, blood group & Rhesus, urinalysis, VDRL, HIV, and diagnostic investigations
                                        </CardDescription>
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
                                                                    {new Date(order.ordered_at).toLocaleDateString([], { month: 'short', day: 'numeric' })} ·{' '}
                                                                    {new Date(order.ordered_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
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
                                                                {(order.ordered_by as any)?.UserName ?? order.ordered_by?.name ?? 'Nurse / Clinician'}
                                                            </TableCell>
                                                            <TableCell>
                                                                <Badge variant="outline" className="capitalize text-[10px]">
                                                                    {order.status.replace('_', ' ')}
                                                                </Badge>
                                                            </TableCell>
                                                            <TableCell>
                                                                <div className="flex flex-col gap-0.5">
                                                                    {order.items?.map((item) =>
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
                                                                    )}
                                                                </div>
                                                            </TableCell>
                                                        </TableRow>
                                                    ))}
                                                </TableBody>
                                            </Table>
                                        ) : (
                                            <div className="p-6 text-center text-xs text-muted-foreground">
                                                <FlaskConical className="h-7 w-7 text-muted-foreground/40 mx-auto mb-2" />
                                                <p className="font-medium text-neutral-700 dark:text-neutral-300">
                                                    No laboratory investigations ordered yet.
                                                </p>
                                                <p className="text-[11px] mt-0.5">
                                                    Click "Order Lab Tests" above to order routine antenatal screening, CBC, blood grouping, urinalysis, or urgent diagnostic tests.
                                                </p>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>

                                {/* 3. Maternity Ultrasound & Radiology Orders Card */}
                                <Card>
                                    <CardHeader className="pb-3">
                                        <div className="flex items-center justify-between">
                                            <CardTitle className="text-base flex items-center gap-2">
                                                <Scan className="h-4 w-4 text-blue-600" />
                                                Maternity Ultrasound & Radiology Scans ({radiologyOrders.length})
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
                                        <CardDescription>
                                            Obstetric ultrasound, fetal anomaly scans, biophysical profile, and diagnostic imaging requested for this visit
                                        </CardDescription>
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
                                                                    {new Date(order.ordered_at).toLocaleDateString([], { month: 'short', day: 'numeric' })} ·{' '}
                                                                    {new Date(order.ordered_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
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
                                                                {(order.ordered_by as any)?.UserName ?? order.ordered_by?.name ?? 'Nurse / Clinician'}
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
                                                <p className="font-medium text-neutral-700 dark:text-neutral-300">
                                                    No ultrasound or radiology scans ordered yet.
                                                </p>
                                                <p className="text-[11px] mt-0.5">
                                                    Click "New Ultrasound Order" above to request obstetric dating, fetal anomaly, growth, or biophysical profile scans.
                                                </p>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            </TabsContent>
                        </div>
                    </div>
                </Tabs>

                {/* ── MODAL: ORDER LABORATORY TESTS DIALOG ── */}
                <Dialog open={labOrderDialogOpen} onOpenChange={setLabOrderDialogOpen}>
                    <DialogContent className="max-w-xl max-h-[90vh] overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2 text-base">
                                <FlaskConical className="h-5 w-5 text-emerald-600" />
                                Order Laboratory Tests — Maternity Assessment
                            </DialogTitle>
                            <DialogDescription>
                                Select diagnostic laboratory investigations for {patientName}.
                                <span className="font-semibold block mt-1 text-xs text-neutral-700 dark:text-neutral-300">
                                    Payment Mode: {patientInsurance ? `NHIF (${patientInsurance.membership_number})` : 'Self-Pay / Cash'}
                                </span>
                            </DialogDescription>
                        </DialogHeader>

                        <form onSubmit={submitLabOrder} className="flex flex-col gap-4">
                            <div>
                                <Label className="text-xs font-semibold">Select Tests *</Label>
                                <p className="text-[11px] text-muted-foreground mb-1.5">
                                    Search or pick from the maternity quick-picks below to include tests in this order.
                                </p>
                                <LaboratoryOrderTestSelector
                                    selected={selectedTests}
                                    onChange={setSelectedTests}
                                    clinicalEncounterId={encounter.id}
                                />
                                {labOrderForm.errors.test_ids && (
                                    <p className="text-xs text-destructive mt-1">{labOrderForm.errors.test_ids}</p>
                                )}
                            </div>

                            <div>
                                <Label htmlFor="maternity_lab_priority" className="text-xs font-semibold">Priority</Label>
                                <Select
                                    value={labOrderForm.data.priority}
                                    onValueChange={(v) => labOrderForm.setData('priority', v as LaboratoryOrderPriority)}
                                >
                                    <SelectTrigger id="maternity_lab_priority" className="mt-1">
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
                                <Label htmlFor="maternity_lab_indication" className="text-xs font-semibold">Clinical Indication</Label>
                                <div className="flex flex-wrap gap-1.5 my-1.5">
                                    {[
                                        'Routine Antenatal Profile (ANC)',
                                        'Pre-Eclampsia Workup',
                                        'Severe Anemia Assessment',
                                        'Gestational Diabetes Screening',
                                        'Suspected UTI / Chorioamnionitis',
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
                                    id="maternity_lab_indication"
                                    value={labOrderForm.data.clinical_indication}
                                    onChange={(e) => labOrderForm.setData('clinical_indication', e.target.value)}
                                    placeholder="e.g. Routine first antenatal visit screening, elevated BP..."
                                />
                            </div>

                            <div>
                                <Label htmlFor="maternity_lab_notes" className="text-xs font-semibold">Special Instructions / Notes</Label>
                                <Textarea
                                    id="maternity_lab_notes"
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

                {/* ── MODAL: WRITE MEDICATION ORDER TO PHARMACY DIALOG ── */}
                <Dialog open={prescriptionDialogOpen} onOpenChange={setPrescriptionDialogOpen}>
                    <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2 text-base">
                                <Pill className="h-5 w-5 text-purple-600" />
                                Write Medication to Pharmacy — Maternity Assessment
                            </DialogTitle>
                            <DialogDescription>
                                Prescribe maternity medications for {patientName}.
                                <span className="font-semibold block mt-1 text-xs text-neutral-700 dark:text-neutral-300">
                                    Payment Mode: {patientInsurance ? `NHIF (${patientInsurance.membership_number})` : 'Self-Pay / Cash'}
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
                                                    clinicalEncounterId={encounter.id}
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
                                                    placeholder="e.g. 1, 200, 500"
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
                                                    placeholder="e.g. tab, mg, IU, ml"
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
                                                        <SelectItem value="hours">Hours</SelectItem>
                                                        <SelectItem value="doses">Doses</SelectItem>
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
                                            <Label className="text-[11px]">Administration / Patient Instructions</Label>
                                            <Input
                                                value={item.instructions}
                                                onChange={(e) => {
                                                    const updated = [...prescriptionItems];
                                                    updated[index].instructions = e.target.value;
                                                    setPrescriptionItems(updated);
                                                }}
                                                placeholder="e.g. Take after meals with water, or administer 10 IU IM after delivery"
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
                                <Label htmlFor="maternity_rx_clinical_notes" className="text-xs font-semibold">
                                    Clinical Notes / Indication
                                </Label>
                                <div className="flex flex-wrap gap-1.5 my-1.5">
                                    {[
                                        'Routine Antenatal Supplementation (Iron / Folate)',
                                        'IPT-SP Malaria Prophylaxis',
                                        'Gestational Hypertension Management',
                                        'UTI / Maternal Infection Treatment',
                                        'Active Management of Third Stage of Labour',
                                    ].map((preset) => (
                                        <Button
                                            key={preset}
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="h-6 text-[10px] px-2"
                                            onClick={() => prescriptionForm.setData('clinical_notes', preset)}
                                        >
                                            {preset}
                                        </Button>
                                    ))}
                                </div>
                                <Textarea
                                    id="maternity_rx_clinical_notes"
                                    value={prescriptionForm.data.clinical_notes}
                                    onChange={(e) => prescriptionForm.setData('clinical_notes', e.target.value)}
                                    placeholder="Document clinical indication for maternity prescription..."
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

                {/* ── MODAL: ORDER ULTRASOUND & RADIOLOGY DIALOG ── */}
                <Dialog open={radiologyDialogOpen} onOpenChange={setRadiologyDialogOpen}>
                    <DialogContent className="max-w-xl max-h-[90vh] overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2 text-base">
                                <Scan className="h-5 w-5 text-blue-600" />
                                Order Ultrasound & Radiology — Maternity Assessment
                            </DialogTitle>
                            <DialogDescription>
                                Request obstetric ultrasound or diagnostic imaging for {patientName}.
                                <span className="font-semibold block mt-1 text-xs text-neutral-700 dark:text-neutral-300">
                                    Payment Mode: {patientInsurance ? `NHIF (${patientInsurance.membership_number})` : 'Self-Pay / Cash'}
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
                                <div className="flex items-center justify-between mb-1.5">
                                    <div>
                                        <Label className="text-xs font-semibold">Select Ultrasound / Imaging Procedures *</Label>
                                        <p className="text-[11px] text-muted-foreground">Select from active hospital imaging procedures:</p>
                                    </div>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant={ultrasoundOnlyFilter ? 'default' : 'outline'}
                                        className="h-6 text-[10px] px-2"
                                        onClick={() => setUltrasoundOnlyFilter(!ultrasoundOnlyFilter)}
                                    >
                                        {ultrasoundOnlyFilter ? 'Showing Ultrasound Only' : 'Filter Ultrasound Only'}
                                    </Button>
                                </div>

                                <div className="max-h-48 overflow-y-auto rounded-lg border p-2 space-y-1.5 bg-muted/20">
                                    {filteredRadiologyProcedures.map((proc) => {
                                        const isSelected = radiologyForm.data.procedure_ids.includes(proc.id);
                                        const procId = `maternity_rad_proc_${proc.id}`;
                                        return (
                                            <div
                                                key={proc.id}
                                                className={`flex items-center justify-between p-2 rounded text-xs transition-colors ${isSelected
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
                                                            {proc.modality ?? 'Imaging'}
                                                        </Badge>
                                                    </Label>
                                                </div>
                                            </div>
                                        );
                                    })}
                                    {filteredRadiologyProcedures.length === 0 && (
                                        <p className="text-xs text-muted-foreground p-3 text-center">
                                            No matching imaging procedures found.
                                        </p>
                                    )}
                                </div>
                                {radiologyForm.errors.procedure_ids && (
                                    <p className="text-xs text-destructive mt-1">{radiologyForm.errors.procedure_ids}</p>
                                )}
                            </div>

                            {/* Priority */}
                            <div>
                                <Label htmlFor="maternity_rad_priority" className="text-xs font-semibold">Priority</Label>
                                <Select
                                    value={radiologyForm.data.priority}
                                    onValueChange={(v) => radiologyForm.setData('priority', v as RadiologyOrderPriority)}
                                >
                                    <SelectTrigger id="maternity_rad_priority" className="mt-1">
                                        <SelectValue placeholder="Select priority" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="routine">Routine</SelectItem>
                                        <SelectItem value="urgent">Urgent</SelectItem>
                                        <SelectItem value="stat">Stat (Immediate / Emergency)</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            {/* Clinical Indication with Obstetric Presets */}
                            <div>
                                <Label htmlFor="maternity_rad_indication" className="text-xs font-semibold">
                                    Clinical Indication *
                                </Label>
                                <div className="flex flex-wrap gap-1.5 my-1.5">
                                    {[
                                        'Routine Obstetric Dating & Viability Scan',
                                        'Fetal Anomaly Scan (18-22 Weeks)',
                                        'Third Trimester Growth & Biophysical Profile',
                                        'Placental Localization & Amniotic Fluid Index',
                                        'Suspected Malpresentation / Breech Evaluation',
                                        'Reduced Fetal Movements / Fetal Wellbeing',
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
                                    id="maternity_rad_indication"
                                    value={radiologyForm.data.clinical_indication}
                                    onChange={(e) => radiologyForm.setData('clinical_indication', e.target.value)}
                                    placeholder="e.g. Confirm gestational age, fetal lie, placenta position..."
                                    required
                                />
                            </div>

                            {/* Notes */}
                            <div>
                                <Label htmlFor="maternity_rad_notes" className="text-xs font-semibold">Additional Clinical Notes</Label>
                                <Textarea
                                    id="maternity_rad_notes"
                                    value={radiologyForm.data.notes}
                                    onChange={(e) => radiologyForm.setData('notes', e.target.value)}
                                    placeholder="Optional notes for the sonographer / radiologist..."
                                    rows={2}
                                />
                            </div>

                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setRadiologyDialogOpen(false)}>
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={radiologyForm.processing || radiologyForm.data.procedure_ids.length === 0}
                                    className="bg-blue-600 hover:bg-blue-700 text-white"
                                >
                                    Place Ultrasound Order ({radiologyForm.data.procedure_ids.length})
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>

                <ViewRadiologyReportDialog
                    open={Boolean(selectedReport)}
                    onOpenChange={(open) => {
                        if (!open) {
                            setSelectedReport(null);
                            setSelectedReportProcedure(null);
                        }
                    }}
                    report={selectedReport}
                    procedure={selectedReportProcedure}
                />

                {/* ── Status Update Dialog ── */}
                <Dialog open={statusDialogOpen} onOpenChange={setStatusDialogOpen}>
                    <DialogContent className="sm:max-w-md">
                        <form onSubmit={handleStatusSubmit}>
                            <DialogHeader>
                                <DialogTitle className="flex items-center gap-2">
                                    <HeartPulse className="h-5 w-5 text-pink-600" />
                                    Update Maternity Queue Status
                                </DialogTitle>
                                <DialogDescription>
                                    Update queue stage for Visit #{visit.visit_number} ({patientName}).
                                </DialogDescription>
                            </DialogHeader>

                            <div className="space-y-4 py-4">
                                <div className="space-y-2">
                                    <Label htmlFor="m_status">Target Queue Status *</Label>
                                    <Select
                                        value={statusForm.data.maternity_status}
                                        onValueChange={(val) => statusForm.setData('maternity_status', val)}
                                    >
                                        <SelectTrigger id="m_status">
                                            <SelectValue placeholder="Choose status" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(maternityStatuses).map(([key, label]) => (
                                                <SelectItem key={key} value={key}>
                                                    {label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="status_notes">Clinical Handover Remarks</Label>
                                    <Textarea
                                        id="status_notes"
                                        placeholder="e.g. Initial mother assessment and vitals documented, awaiting lab / doctor review"
                                        rows={3}
                                        value={statusForm.data.notes}
                                        onChange={(e) => statusForm.setData('notes', e.target.value)}
                                    />
                                </div>
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setStatusDialogOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={statusForm.processing}
                                    className="bg-pink-600 hover:bg-pink-700 text-white"
                                >
                                    Update Status
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
