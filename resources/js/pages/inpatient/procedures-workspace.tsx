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
import { Head, Link, useForm } from '@inertiajs/react';
import {
    Activity,
    AlertCircle,
    AlertTriangle,
    Baby,
    Calendar,
    CheckCircle2,
    Clock,
    FileCheck,
    FileSpreadsheet,
    FileText,
    HeartPulse,
    HelpCircle,
    Info,
    Pill,
    Plus,
    Scissors,
    ShieldAlert,
    ShieldCheck,
    Stethoscope,
    User,
    Users,
} from 'lucide-react';
import { useState } from 'react';

interface ProcedureOrderData {
    id: number;
    order_number: string;
    admission_id: number;
    patient_id: number;
    procedure_name: string;
    procedure_type: string;
    urgency: string;
    clinical_indication: string;
    status: string;
    scheduled_at: string | null;
    notes: string | null;
    created_at: string;
    prescribed_by?: { id: number; UserName: string };
    service?: { id: number; code: string; name: string; price?: number };
    surgical_case?: SurgicalCaseData | null;
    bedside_events?: BedsideEventData[];
}

interface SurgicalCaseData {
    id: number;
    case_number: string;
    procedure_order_id: number;
    theatre_room: string;
    anesthesia_type: string;
    status: string;
    identity_confirmed: boolean;
    site_marked: boolean;
    consent_confirmed: boolean;
    anesthesia_safety_check_completed: boolean;
    pulse_oximeter_functioning: boolean;
    allergy_screened: boolean;
    difficult_airway_risk_assessed: boolean;
    blood_loss_risk_assessed: boolean;
    fasting_npo_hours_confirmed: boolean;
    started_at: string | null;
    ended_at: string | null;
    pre_operative_diagnosis: string | null;
    post_operative_diagnosis: string | null;
    surgical_technique: string | null;
    intraoperative_findings: string | null;
    implants_used: string | null;
    drains_placed: string | null;
    swab_instrument_count_correct: boolean;
    specimens_sent_to_lab: string | null;
    estimated_blood_loss_ml: number;
    complications: string | null;
    recovery_started_at: string | null;
    recovery_ended_at: string | null;
    aldrete_score: number | null;
    post_operative_orders: string | null;
    transferred_to_ward_at: string | null;
    lead_surgeon?: { id: number; UserName: string };
    assistant_surgeon?: { id: number; UserName: string };
    anesthetist?: { id: number; UserName: string };
    scrub_nurse?: { id: number; UserName: string };
    circulating_nurse?: { id: number; UserName: string };
}

interface BedsideEventData {
    id: number;
    performed_at: string;
    procedure_notes: string;
    patient_tolerance: string;
    consumables_used: string | null;
    complications: string | null;
    performed_by?: { id: number; UserName: string };
}

interface PractitionerOption {
    id: number;
    staff?: { id: number; first_name: string; last_name: string; job_title: string };
}

interface ServiceOption {
    id: number;
    code: string;
    name: string;
    category: string;
    price: number | null;
}

interface ProceduresWorkspaceProps {
    admission: Admission;
    orders: ProcedureOrderData[];
    practitioners: PractitionerOption[];
    procedureServices: ServiceOption[];
    canAccessMaternity?: boolean;
    constants: {
        procedure_types: Record<string, string>;
        urgencies: Record<string, string>;
        anesthesia_types: Record<string, string>;
    };
}

export default function ProceduresWorkspace({
    admission,
    orders,
    practitioners,
    procedureServices,
    canAccessMaternity = false,
    constants,
}: ProceduresWorkspaceProps) {
    const [activeTab, setActiveTab] = useState<'orders' | 'cases' | 'bedside'>('orders');

    // Dialogs state
    const [orderDialogOpen, setOrderDialogOpen] = useState(false);
    const [scheduleDialogOpen, setScheduleDialogOpen] = useState(false);
    const [selectedOrderForSchedule, setSelectedOrderForSchedule] = useState<ProcedureOrderData | null>(null);

    const [checklistDialogOpen, setChecklistDialogOpen] = useState(false);
    const [selectedCaseForChecklist, setSelectedCaseForChecklist] = useState<SurgicalCaseData | null>(null);

    const [intraOpDialogOpen, setIntraOpDialogOpen] = useState(false);
    const [selectedCaseForIntraOp, setSelectedCaseForIntraOp] = useState<SurgicalCaseData | null>(null);

    const [pacuDialogOpen, setPacuDialogOpen] = useState(false);
    const [selectedCaseForPacu, setSelectedCaseForPacu] = useState<SurgicalCaseData | null>(null);

    const [bedsideDialogOpen, setBedsideDialogOpen] = useState(false);
    const [selectedOrderForBedside, setSelectedOrderForBedside] = useState<ProcedureOrderData | null>(null);

    // 1. Order Form
    const orderForm = useForm({
        procedure_name: '',
        service_id: '',
        procedure_type: 'minor_theatre',
        urgency: 'elective',
        clinical_indication: '',
        scheduled_at: '',
        notes: '',
    });

    const submitOrder = (e: React.FormEvent) => {
        e.preventDefault();
        orderForm.post(route('inpatient.procedures.store', admission.id), {
            preserveScroll: true,
            onSuccess: () => {
                orderForm.reset();
                setOrderDialogOpen(false);
            },
        });
    };

    // 2. Schedule Surgical Case Form
    const scheduleForm = useForm({
        theatre_room: 'Main Operating Theatre 1',
        lead_surgeon_id: '',
        assistant_surgeon_id: '',
        anesthetist_id: '',
        scrub_nurse_id: '',
        circulating_nurse_id: '',
        anesthesia_type: 'general',
        started_at: '',
    });

    const openScheduleModal = (order: ProcedureOrderData) => {
        setSelectedOrderForSchedule(order);
        scheduleForm.setData({
            theatre_room: order.procedure_type === 'minor_theatre' ? 'Minor Procedure Room' : 'Main Operating Theatre 1',
            lead_surgeon_id: '',
            assistant_surgeon_id: '',
            anesthetist_id: '',
            scrub_nurse_id: '',
            circulating_nurse_id: '',
            anesthesia_type: 'general',
            started_at: order.scheduled_at ? order.scheduled_at.slice(0, 16) : '',
        });
        setScheduleDialogOpen(true);
    };

    const submitSchedule = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedOrderForSchedule) return;
        scheduleForm.post(route('inpatient.procedures.schedule', [admission.id, selectedOrderForSchedule.id]), {
            preserveScroll: true,
            onSuccess: () => setScheduleDialogOpen(false),
        });
    };

    // 3. WHO Surgical Checklist Form
    const checklistForm = useForm({
        identity_confirmed: false as boolean,
        site_marked: false as boolean,
        consent_confirmed: false as boolean,
        anesthesia_safety_check_completed: false as boolean,
        pulse_oximeter_functioning: false as boolean,
        allergy_screened: false as boolean,
        difficult_airway_risk_assessed: false as boolean,
        blood_loss_risk_assessed: false as boolean,
        fasting_npo_hours_confirmed: false as boolean,
    });

    const openChecklistModal = (surgCase: SurgicalCaseData) => {
        setSelectedCaseForChecklist(surgCase);
        checklistForm.setData({
            identity_confirmed: surgCase.identity_confirmed,
            site_marked: surgCase.site_marked,
            consent_confirmed: surgCase.consent_confirmed,
            anesthesia_safety_check_completed: surgCase.anesthesia_safety_check_completed,
            pulse_oximeter_functioning: surgCase.pulse_oximeter_functioning,
            allergy_screened: surgCase.allergy_screened,
            difficult_airway_risk_assessed: surgCase.difficult_airway_risk_assessed,
            blood_loss_risk_assessed: surgCase.blood_loss_risk_assessed,
            fasting_npo_hours_confirmed: surgCase.fasting_npo_hours_confirmed,
        });
        setChecklistDialogOpen(true);
    };

    const submitChecklist = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedCaseForChecklist) return;
        checklistForm.post(route('inpatient.procedures.cases.checklist', [admission.id, selectedCaseForChecklist.id]), {
            preserveScroll: true,
            onSuccess: () => setChecklistDialogOpen(false),
        });
    };

    // 4. Intra-Operative Form
    const intraOpForm = useForm({
        started_at: '',
        ended_at: new Date().toISOString().slice(0, 16),
        pre_operative_diagnosis: '',
        post_operative_diagnosis: '',
        surgical_technique: '',
        intraoperative_findings: '',
        implants_used: '',
        drains_placed: '',
        swab_instrument_count_correct: true as boolean,
        specimens_sent_to_lab: '',
        estimated_blood_loss_ml: '100',
        complications: '',
    });

    const openIntraOpModal = (surgCase: SurgicalCaseData) => {
        setSelectedCaseForIntraOp(surgCase);
        intraOpForm.setData({
            started_at: surgCase.started_at ? surgCase.started_at.slice(0, 16) : new Date().toISOString().slice(0, 16),
            ended_at: surgCase.ended_at ? surgCase.ended_at.slice(0, 16) : new Date().toISOString().slice(0, 16),
            pre_operative_diagnosis: surgCase.pre_operative_diagnosis ?? '',
            post_operative_diagnosis: surgCase.post_operative_diagnosis ?? '',
            surgical_technique: surgCase.surgical_technique ?? '',
            intraoperative_findings: surgCase.intraoperative_findings ?? '',
            implants_used: surgCase.implants_used ?? '',
            drains_placed: surgCase.drains_placed ?? '',
            swab_instrument_count_correct: surgCase.swab_instrument_count_correct ?? true,
            specimens_sent_to_lab: surgCase.specimens_sent_to_lab ?? '',
            estimated_blood_loss_ml: String(surgCase.estimated_blood_loss_ml || '100'),
            complications: surgCase.complications ?? '',
        });
        setIntraOpDialogOpen(true);
    };

    const submitIntraOp = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedCaseForIntraOp) return;
        intraOpForm.post(route('inpatient.procedures.cases.intra-op', [admission.id, selectedCaseForIntraOp.id]), {
            preserveScroll: true,
            onSuccess: () => setIntraOpDialogOpen(false),
        });
    };

    // 5. PACU Form
    const pacuForm = useForm({
        recovery_started_at: new Date().toISOString().slice(0, 16),
        recovery_ended_at: new Date().toISOString().slice(0, 16),
        aldrete_score: '9',
        post_operative_orders: 'Bed rest for 6 hours, monitor vitals q15m x 1h then q30m x 2h, IV fluids 1L normal saline over 8h.',
        transferred_to_ward_at: new Date().toISOString().slice(0, 16),
    });

    const openPacuModal = (surgCase: SurgicalCaseData) => {
        setSelectedCaseForPacu(surgCase);
        pacuForm.setData({
            recovery_started_at: surgCase.recovery_started_at ? surgCase.recovery_started_at.slice(0, 16) : new Date().toISOString().slice(0, 16),
            recovery_ended_at: surgCase.recovery_ended_at ? surgCase.recovery_ended_at.slice(0, 16) : new Date().toISOString().slice(0, 16),
            aldrete_score: String(surgCase.aldrete_score ?? '9'),
            post_operative_orders: surgCase.post_operative_orders ?? 'Monitor vitals q30m, IV analgesics as prescribed.',
            transferred_to_ward_at: surgCase.transferred_to_ward_at ? surgCase.transferred_to_ward_at.slice(0, 16) : new Date().toISOString().slice(0, 16),
        });
        setPacuDialogOpen(true);
    };

    const submitPacu = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedCaseForPacu) return;
        pacuForm.post(route('inpatient.procedures.cases.pacu', [admission.id, selectedCaseForPacu.id]), {
            preserveScroll: true,
            onSuccess: () => setPacuDialogOpen(false),
        });
    };

    // 6. Bedside Procedure Form
    const bedsideForm = useForm({
        performed_at: new Date().toISOString().slice(0, 16),
        procedure_notes: '',
        patient_tolerance: 'tolerated_well',
        consumables_used: '',
        complications: '',
    });

    const openBedsideModal = (order: ProcedureOrderData) => {
        setSelectedOrderForBedside(order);
        bedsideForm.setData({
            performed_at: new Date().toISOString().slice(0, 16),
            procedure_notes: `Performed ${order.procedure_name} under aseptic technique.`,
            patient_tolerance: 'tolerated_well',
            consumables_used: '',
            complications: '',
        });
        setBedsideDialogOpen(true);
    };

    const submitBedside = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedOrderForBedside) return;
        bedsideForm.post(route('inpatient.procedures.bedside', [admission.id, selectedOrderForBedside.id]), {
            preserveScroll: true,
            onSuccess: () => setBedsideDialogOpen(false),
        });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Inpatient Ward', href: '/inpatient' },
        { title: 'Nursing Station', href: '/inpatient/nursing' },
        { title: `Patient #${admission.patient?.patient_number ?? admission.patient_id}`, href: route('inpatient.nursing.workspace', admission.id) },
        { title: 'Procedures & Surgery', href: '#' },
    ];

    const surgicalCasesList = orders.map((o) => o.surgical_case).filter(Boolean) as SurgicalCaseData[];
    const allBedsideEvents = orders.flatMap((o) => o.bedside_events || []);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Procedures & Surgery — ${admission.patient?.first_name} ${admission.patient?.last_name}`} />

            <div className="flex flex-col gap-6 p-4">
                {/* Header Banner */}
                <div className="flex flex-col gap-4 rounded-xl border border-sky-200 bg-gradient-to-r from-sky-50 via-indigo-50 to-white p-5 shadow-sm dark:border-sky-900/40 dark:from-sky-950/20 dark:via-indigo-950/10 dark:to-neutral-900">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex items-start gap-3">
                            <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-md">
                                <Scissors className="h-6 w-6" />
                            </div>
                            <div>
                                <div className="flex items-center gap-2">
                                    <h1 className="text-2xl font-bold tracking-tight text-neutral-900 dark:text-neutral-50">
                                        {admission.patient?.first_name} {admission.patient?.last_name}
                                    </h1>
                                    <Badge variant="outline" className="border-indigo-400 bg-indigo-100/60 text-indigo-800 dark:bg-indigo-950/40 dark:text-indigo-300">
                                        Procedures & Surgical Theatre
                                    </Badge>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    Hospital #: <span className="font-medium text-foreground">{admission.patient?.patient_number}</span> • Ward:{' '}
                                    <span className="font-medium text-foreground">{admission.department?.name ?? 'General'}</span> • Adm No:{' '}
                                    <span className="font-medium text-foreground">{admission.admission_number}</span> • Bed:{' '}
                                    <span className="font-medium text-foreground">{admission.ward_type ?? 'Ward Bed'}</span>
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
                            {canAccessMaternity && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={route('inpatient.maternity.show', admission.id)}>
                                        <Baby className="mr-1.5 h-4 w-4 text-pink-600" />
                                        Maternity
                                    </Link>
                                </Button>
                            )}
                            <Button className="bg-indigo-600 hover:bg-indigo-700" onClick={() => setOrderDialogOpen(true)}>
                                <Plus className="mr-1.5 h-4 w-4" />
                                Order Procedure
                            </Button>
                        </div>
                    </div>
                </div>

                {/* Tabs */}
                <Tabs value={activeTab} onValueChange={(v) => setActiveTab(v as any)} className="w-full">
                    <TabsList className="grid w-full grid-cols-3 max-w-md">
                        <TabsTrigger value="orders" className="flex items-center gap-1.5">
                            <FileText className="h-4 w-4" />
                            Procedure Orders ({orders.length})
                        </TabsTrigger>
                        <TabsTrigger value="cases" className="flex items-center gap-1.5">
                            <Scissors className="h-4 w-4" />
                            Theatre Cases ({surgicalCasesList.length})
                        </TabsTrigger>
                        <TabsTrigger value="bedside" className="flex items-center gap-1.5">
                            <Stethoscope className="h-4 w-4" />
                            Bedside Events ({allBedsideEvents.length})
                        </TabsTrigger>
                    </TabsList>

                    {/* TAB 1: PROCEDURE ORDERS */}
                    <TabsContent value="orders" className="mt-4 flex flex-col gap-4">
                        {orders.length === 0 ? (
                            <EmptyState
                                icon={Scissors}
                                title="No Procedures Ordered"
                                description="Doctor-ordered procedures (bedside or surgical operations) will appear here."
                                action={
                                    <Button onClick={() => setOrderDialogOpen(true)}>
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        Order Procedure
                                    </Button>
                                }
                            />
                        ) : (
                            <Card>
                                <CardContent className="p-0">
                                    <Table>
                                        <TableHeader className="bg-muted/50 text-xs">
                                            <TableRow>
                                                <TableHead>Order #</TableHead>
                                                <TableHead>Procedure</TableHead>
                                                <TableHead>Type</TableHead>
                                                <TableHead>Urgency</TableHead>
                                                <TableHead>Indication</TableHead>
                                                <TableHead>Status</TableHead>
                                                <TableHead>Prescribed By</TableHead>
                                                <TableHead className="text-right">Actions</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody className="text-xs">
                                            {orders.map((order) => {
                                                const isTheatre = ['minor_theatre', 'major_theatre', 'endoscopy'].includes(order.procedure_type);
                                                return (
                                                    <TableRow key={order.id}>
                                                        <TableCell className="font-mono font-medium">{order.order_number}</TableCell>
                                                        <TableCell className="font-semibold text-sm">
                                                            {order.procedure_name}
                                                            {order.service && (
                                                                <span className="block text-[11px] font-normal text-muted-foreground">
                                                                    Code: {order.service.code} • {order.service.price ? `TZS ${order.service.price.toLocaleString()}` : ''}
                                                                </span>
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="capitalize">{constants.procedure_types[order.procedure_type] ?? order.procedure_type}</TableCell>
                                                        <TableCell>
                                                            <Badge
                                                                variant={order.urgency === 'emergency' ? 'destructive' : order.urgency === 'urgent' ? 'default' : 'secondary'}
                                                                className="uppercase font-semibold text-[10px]"
                                                            >
                                                                {order.urgency}
                                                            </Badge>
                                                        </TableCell>
                                                        <TableCell className="max-w-[200px] truncate">{order.clinical_indication}</TableCell>
                                                        <TableCell>
                                                            <Badge variant="outline" className="capitalize">
                                                                {order.status.replace('_', ' ')}
                                                            </Badge>
                                                        </TableCell>
                                                        <TableCell className="text-muted-foreground">{order.prescribed_by?.UserName ?? 'Doctor'}</TableCell>
                                                        <TableCell className="text-right">
                                                            <div className="flex justify-end gap-1.5">
                                                                {isTheatre && !order.surgical_case && order.status !== 'completed' && order.status !== 'cancelled' && (
                                                                    <Button size="sm" variant="default" className="bg-indigo-600 hover:bg-indigo-700" onClick={() => openScheduleModal(order)}>
                                                                        Schedule OT
                                                                    </Button>
                                                                )}
                                                                {isTheatre && order.surgical_case && (
                                                                    <Button size="sm" variant="outline" onClick={() => setActiveTab('cases')}>
                                                                        View OT Case
                                                                    </Button>
                                                                )}
                                                                {!isTheatre && order.status !== 'completed' && order.status !== 'cancelled' && (
                                                                    <Button size="sm" variant="default" className="bg-emerald-600 hover:bg-emerald-700" onClick={() => openBedsideModal(order)}>
                                                                        Perform
                                                                    </Button>
                                                                )}
                                                            </div>
                                                        </TableCell>
                                                    </TableRow>
                                                );
                                            })}
                                        </TableBody>
                                    </Table>
                                </CardContent>
                            </Card>
                        )}
                    </TabsContent>

                    {/* TAB 2: SURGICAL THEATRE CASES */}
                    <TabsContent value="cases" className="mt-4 flex flex-col gap-6">
                        {surgicalCasesList.length === 0 ? (
                            <EmptyState
                                icon={Scissors}
                                title="No Surgical Cases Scheduled"
                                description="Operating theatre cases booked from procedure orders will appear here with safety checklists and intra-op logs."
                            />
                        ) : (
                            surgicalCasesList.map((surgCase) => {
                                const checklistTotal = [
                                    surgCase.identity_confirmed,
                                    surgCase.site_marked,
                                    surgCase.consent_confirmed,
                                    surgCase.anesthesia_safety_check_completed,
                                    surgCase.pulse_oximeter_functioning,
                                    surgCase.allergy_screened,
                                    surgCase.difficult_airway_risk_assessed,
                                    surgCase.blood_loss_risk_assessed,
                                    surgCase.fasting_npo_hours_confirmed,
                                ].filter(Boolean).length;

                                return (
                                    <Card key={surgCase.id} className="overflow-hidden border-indigo-200 dark:border-indigo-900/40">
                                        <CardHeader className="bg-muted/40 pb-3">
                                            <div className="flex flex-wrap items-center justify-between gap-3">
                                                <div>
                                                    <div className="flex items-center gap-2">
                                                        <CardTitle className="text-base font-bold">Case #{surgCase.case_number}</CardTitle>
                                                        <Badge className="bg-indigo-700 text-white uppercase">{surgCase.status.replace('_', ' ')}</Badge>
                                                        <Badge variant="outline">{surgCase.theatre_room}</Badge>
                                                    </div>
                                                    <CardDescription className="text-xs">
                                                        Lead Surgeon: <strong>{surgCase.lead_surgeon?.UserName ?? 'Unassigned'}</strong> • Anesthetist:{' '}
                                                        <strong>{surgCase.anesthetist?.UserName ?? 'Unassigned'}</strong> • Anesthesia:{' '}
                                                        <strong>{constants.anesthesia_types[surgCase.anesthesia_type] ?? surgCase.anesthesia_type}</strong>
                                                    </CardDescription>
                                                </div>

                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Button size="sm" variant="outline" className="border-emerald-300 text-emerald-800" onClick={() => openChecklistModal(surgCase)}>
                                                        <ShieldCheck className="mr-1.5 h-4 w-4 text-emerald-600" />
                                                        Safety Checklist ({checklistTotal}/9)
                                                    </Button>
                                                    <Button size="sm" variant="default" className="bg-indigo-600 hover:bg-indigo-700" onClick={() => openIntraOpModal(surgCase)}>
                                                        <FileSpreadsheet className="mr-1.5 h-4 w-4" />
                                                        Intra-Op Record
                                                    </Button>
                                                    {surgCase.status === 'recovery' || surgCase.status === 'completed' ? (
                                                        <Button size="sm" variant="outline" onClick={() => openPacuModal(surgCase)}>
                                                            <HeartPulse className="mr-1.5 h-4 w-4 text-pink-600" />
                                                            PACU Recovery
                                                        </Button>
                                                    ) : null}
                                                </div>
                                            </div>
                                        </CardHeader>
                                        <CardContent className="pt-4 flex flex-col gap-4 text-xs">
                                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                                <div className="rounded-lg border p-3">
                                                    <span className="font-semibold text-muted-foreground uppercase text-[10px]">Pre-Op Safety Status</span>
                                                    <div className="mt-1 flex items-center gap-2">
                                                        <CheckCircle2 className={`h-4 w-4 ${checklistTotal === 9 ? 'text-emerald-600' : 'text-amber-500'}`} />
                                                        <span className="font-medium">
                                                            {checklistTotal === 9 ? 'All 9 WHO Safety Checks Verified' : `${checklistTotal}/9 Safety Checks Completed`}
                                                        </span>
                                                    </div>
                                                </div>

                                                <div className="rounded-lg border p-3">
                                                    <span className="font-semibold text-muted-foreground uppercase text-[10px]">Intra-Operative Blood Loss</span>
                                                    <div className="mt-1 text-sm font-bold">
                                                        {surgCase.estimated_blood_loss_ml} ml{' '}
                                                        {surgCase.estimated_blood_loss_ml >= 1000 && <span className="text-red-600">(Major Hemorrhage ⚠️)</span>}
                                                    </div>
                                                </div>

                                                <div className="rounded-lg border p-3">
                                                    <span className="font-semibold text-muted-foreground uppercase text-[10px]">Swab & Instrument Count</span>
                                                    <div className="mt-1 flex items-center gap-1.5">
                                                        {surgCase.swab_instrument_count_correct ? (
                                                            <span className="text-emerald-600 font-semibold">✓ Correct & Verified</span>
                                                        ) : (
                                                            <span className="text-red-600 font-bold">⚠️ Discrepancy Noted</span>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>

                                            {surgCase.surgical_technique && (
                                                <div className="rounded-lg bg-muted/30 p-3">
                                                    <span className="font-semibold text-foreground">Surgical Technique & Findings: </span>
                                                    <p className="mt-1 whitespace-pre-wrap">{surgCase.surgical_technique}</p>
                                                    {surgCase.intraoperative_findings && <p className="mt-2 text-muted-foreground">Findings: {surgCase.intraoperative_findings}</p>}
                                                </div>
                                            )}

                                            {surgCase.post_operative_orders && (
                                                <div className="rounded-lg bg-sky-50/60 p-3 dark:bg-sky-950/20">
                                                    <span className="font-semibold text-sky-900 dark:text-sky-200">Post-Operative Care Orders (PACU / Ward): </span>
                                                    <p className="mt-1 whitespace-pre-wrap text-sky-950 dark:text-sky-300">{surgCase.post_operative_orders}</p>
                                                    {surgCase.aldrete_score !== null && (
                                                        <span className="mt-1 block font-semibold text-sky-800 dark:text-sky-300">
                                                            PACU Aldrete Discharge Score: {surgCase.aldrete_score}/10
                                                        </span>
                                                    )}
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                );
                            })
                        )}
                    </TabsContent>

                    {/* TAB 3: BEDSIDE PROCEDURES */}
                    <TabsContent value="bedside" className="mt-4 flex flex-col gap-4">
                        {allBedsideEvents.length === 0 ? (
                            <EmptyState
                                icon={Stethoscope}
                                title="No Bedside Procedures Performed"
                                description="Bedside clinical interventions (e.g., chest tubes, taps, debridements, catheterizations) documented at the patient's bedside will appear here."
                            />
                        ) : (
                            <Card>
                                <CardContent className="p-0">
                                    <Table>
                                        <TableHeader className="bg-muted/50 text-xs">
                                            <TableRow>
                                                <TableHead>Date / Time</TableHead>
                                                <TableHead>Clinician</TableHead>
                                                <TableHead>Procedure Notes</TableHead>
                                                <TableHead>Patient Tolerance</TableHead>
                                                <TableHead>Consumables Used</TableHead>
                                                <TableHead>Complications</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody className="text-xs">
                                            {allBedsideEvents.map((evt) => (
                                                <TableRow key={evt.id}>
                                                    <TableCell className="font-medium whitespace-nowrap">
                                                        {new Date(evt.performed_at).toLocaleString()}
                                                    </TableCell>
                                                    <TableCell>{evt.performed_by?.UserName ?? 'Clinician'}</TableCell>
                                                    <TableCell className="max-w-[300px] whitespace-pre-wrap">{evt.procedure_notes}</TableCell>
                                                    <TableCell className="capitalize">{evt.patient_tolerance.replace('_', ' ')}</TableCell>
                                                    <TableCell>{evt.consumables_used || '—'}</TableCell>
                                                    <TableCell>{evt.complications || 'None'}</TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </CardContent>
                            </Card>
                        )}
                    </TabsContent>
                </Tabs>
            </div>

            {/* MODAL 1: ORDER PROCEDURE */}
            <Dialog open={orderDialogOpen} onOpenChange={setOrderDialogOpen}>
                <DialogContent className="max-w-xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Prescribe Clinical Procedure</DialogTitle>
                        <DialogDescription>
                            Order a bedside intervention, minor/major operating theatre procedure, or diagnostic endoscopy.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submitOrder} className="flex flex-col gap-4">
                        <div>
                            <Label htmlFor="service_id">Catalog Procedure Service (optional)</Label>
                            <Select
                                value={orderForm.data.service_id}
                                onValueChange={(v) => {
                                    orderForm.setData('service_id', v);
                                    const s = procedureServices.find((x) => String(x.id) === v);
                                    if (s) orderForm.setData('procedure_name', s.name);
                                }}
                            >
                                <SelectTrigger id="service_id">
                                    <SelectValue placeholder="Select from billing catalog or type below" />
                                </SelectTrigger>
                                <SelectContent>
                                    {procedureServices.map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>
                                            {s.code} — {s.name} ({s.price ? `TZS ${s.price.toLocaleString()}` : 'No price'})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label htmlFor="procedure_name">Procedure Name</Label>
                            <Input
                                id="procedure_name"
                                value={orderForm.data.procedure_name}
                                onChange={(e) => orderForm.setData('procedure_name', e.target.value)}
                                placeholder="e.g. Appendectomy, Chest Tube Insertion, Lumbar Puncture"
                                required
                            />
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="procedure_type">Procedure Type</Label>
                                <Select
                                    value={orderForm.data.procedure_type}
                                    onValueChange={(v) => orderForm.setData('procedure_type', v)}
                                >
                                    <SelectTrigger id="procedure_type">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(constants.procedure_types).map(([key, label]) => (
                                            <SelectItem key={key} value={key}>
                                                {label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label htmlFor="urgency">Urgency</Label>
                                <Select
                                    value={orderForm.data.urgency}
                                    onValueChange={(v) => orderForm.setData('urgency', v)}
                                >
                                    <SelectTrigger id="urgency">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(constants.urgencies).map(([key, label]) => (
                                            <SelectItem key={key} value={key}>
                                                {label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div>
                            <Label htmlFor="indication">Clinical Indication</Label>
                            <Textarea
                                id="indication"
                                value={orderForm.data.clinical_indication}
                                onChange={(e) => orderForm.setData('clinical_indication', e.target.value)}
                                placeholder="Diagnosis and reasons for intervention..."
                                required
                                rows={2}
                            />
                        </div>

                        <div>
                            <Label htmlFor="scheduled_at">Scheduled Time (if known)</Label>
                            <Input
                                id="scheduled_at"
                                type="datetime-local"
                                value={orderForm.data.scheduled_at}
                                onChange={(e) => orderForm.setData('scheduled_at', e.target.value)}
                            />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOrderDialogOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={orderForm.processing} className="bg-indigo-600 hover:bg-indigo-700">
                                Submit Procedure Order
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* MODAL 2: SCHEDULE THEATRE CASE */}
            <Dialog open={scheduleDialogOpen} onOpenChange={setScheduleDialogOpen}>
                <DialogContent className="max-w-xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Book Operating Theatre Case</DialogTitle>
                        <DialogDescription>
                            Assign operating room, surgical team, and anesthesia approach for {selectedOrderForSchedule?.procedure_name}.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submitSchedule} className="flex flex-col gap-4">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="theatre_room">Theatre Room</Label>
                                <Input
                                    id="theatre_room"
                                    value={scheduleForm.data.theatre_room}
                                    onChange={(e) => scheduleForm.setData('theatre_room', e.target.value)}
                                    required
                                />
                            </div>
                            <div>
                                <Label htmlFor="anesthesia_type">Anesthesia Type</Label>
                                <Select
                                    value={scheduleForm.data.anesthesia_type}
                                    onValueChange={(v) => scheduleForm.setData('anesthesia_type', v)}
                                >
                                    <SelectTrigger id="anesthesia_type">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(constants.anesthesia_types).map(([key, label]) => (
                                            <SelectItem key={key} value={key}>
                                                {label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="lead_surgeon">Lead Surgeon</Label>
                                <Select
                                    value={scheduleForm.data.lead_surgeon_id}
                                    onValueChange={(v) => scheduleForm.setData('lead_surgeon_id', v)}
                                >
                                    <SelectTrigger id="lead_surgeon">
                                        <SelectValue placeholder="Select surgeon" />
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
                                <Label htmlFor="anesthetist">Anesthetist</Label>
                                <Select
                                    value={scheduleForm.data.anesthetist_id}
                                    onValueChange={(v) => scheduleForm.setData('anesthetist_id', v)}
                                >
                                    <SelectTrigger id="anesthetist">
                                        <SelectValue placeholder="Select anesthetist" />
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
                        </div>

                        <div>
                            <Label htmlFor="sched_start">Planned Start Time</Label>
                            <Input
                                id="sched_start"
                                type="datetime-local"
                                value={scheduleForm.data.started_at}
                                onChange={(e) => scheduleForm.setData('started_at', e.target.value)}
                            />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setScheduleDialogOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={scheduleForm.processing} className="bg-indigo-600 hover:bg-indigo-700">
                                Confirm Theatre Booking
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* MODAL 3: WHO SURGICAL SAFETY CHECKLIST */}
            <Dialog open={checklistDialogOpen} onOpenChange={setChecklistDialogOpen}>
                <DialogContent className="max-w-xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <ShieldCheck className="h-5 w-5 text-emerald-600" />
                            WHO Surgical Safety Checklist
                        </DialogTitle>
                        <DialogDescription>
                            Sign In / Time Out verification before anesthesia induction and surgical incision.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submitChecklist} className="flex flex-col gap-3">
                        <div className="flex flex-col gap-2 rounded-lg border p-3 text-sm">
                            <label className="flex items-center gap-2.5 cursor-pointer">
                                <Checkbox
                                    checked={checklistForm.data.identity_confirmed}
                                    onCheckedChange={(c) => checklistForm.setData('identity_confirmed', Boolean(c))}
                                />
                                <span>1. Patient identity, site, and procedure confirmed with patient</span>
                            </label>
                            <label className="flex items-center gap-2.5 cursor-pointer">
                                <Checkbox
                                    checked={checklistForm.data.site_marked}
                                    onCheckedChange={(c) => checklistForm.setData('site_marked', Boolean(c))}
                                />
                                <span>2. Surgical site marked by operating surgeon</span>
                            </label>
                            <label className="flex items-center gap-2.5 cursor-pointer">
                                <Checkbox
                                    checked={checklistForm.data.consent_confirmed}
                                    onCheckedChange={(c) => checklistForm.setData('consent_confirmed', Boolean(c))}
                                />
                                <span>3. Written informed surgical & anesthesia consent signed</span>
                            </label>
                            <label className="flex items-center gap-2.5 cursor-pointer">
                                <Checkbox
                                    checked={checklistForm.data.anesthesia_safety_check_completed}
                                    onCheckedChange={(c) => checklistForm.setData('anesthesia_safety_check_completed', Boolean(c))}
                                />
                                <span>4. Anesthesia machine and medication safety check completed</span>
                            </label>
                            <label className="flex items-center gap-2.5 cursor-pointer">
                                <Checkbox
                                    checked={checklistForm.data.pulse_oximeter_functioning}
                                    onCheckedChange={(c) => checklistForm.setData('pulse_oximeter_functioning', Boolean(c))}
                                />
                                <span>5. Pulse oximeter on patient and functioning</span>
                            </label>
                            <label className="flex items-center gap-2.5 cursor-pointer">
                                <Checkbox
                                    checked={checklistForm.data.allergy_screened}
                                    onCheckedChange={(c) => checklistForm.setData('allergy_screened', Boolean(c))}
                                />
                                <span>6. Known patient drug allergies checked and verified</span>
                            </label>
                            <label className="flex items-center gap-2.5 cursor-pointer">
                                <Checkbox
                                    checked={checklistForm.data.difficult_airway_risk_assessed}
                                    onCheckedChange={(c) => checklistForm.setData('difficult_airway_risk_assessed', Boolean(c))}
                                />
                                <span>7. Difficult airway / aspiration risk assessed; equipment ready</span>
                            </label>
                            <label className="flex items-center gap-2.5 cursor-pointer">
                                <Checkbox
                                    checked={checklistForm.data.blood_loss_risk_assessed}
                                    onCheckedChange={(c) => checklistForm.setData('blood_loss_risk_assessed', Boolean(c))}
                                />
                                <span>8. Risk of &gt; 500ml blood loss assessed; IV access & fluids ready</span>
                            </label>
                            <label className="flex items-center gap-2.5 cursor-pointer">
                                <Checkbox
                                    checked={checklistForm.data.fasting_npo_hours_confirmed}
                                    onCheckedChange={(c) => checklistForm.setData('fasting_npo_hours_confirmed', Boolean(c))}
                                />
                                <span>9. Fasting / NPO guidelines confirmed</span>
                            </label>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setChecklistDialogOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={checklistForm.processing} className="bg-emerald-600 hover:bg-emerald-700">
                                Save Safety Checklist
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* MODAL 4: INTRA-OPERATIVE SURGICAL RECORD */}
            <Dialog open={intraOpDialogOpen} onOpenChange={setIntraOpDialogOpen}>
                <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Intra-Operative Surgical Record</DialogTitle>
                        <DialogDescription>
                            Document operative technique, findings, counts, and blood loss for Case #{selectedCaseForIntraOp?.case_number}.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submitIntraOp} className="flex flex-col gap-4">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="op_start">Operation Start</Label>
                                <Input
                                    id="op_start"
                                    type="datetime-local"
                                    value={intraOpForm.data.started_at}
                                    onChange={(e) => intraOpForm.setData('started_at', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="op_end">Operation End</Label>
                                <Input
                                    id="op_end"
                                    type="datetime-local"
                                    value={intraOpForm.data.ended_at}
                                    onChange={(e) => intraOpForm.setData('ended_at', e.target.value)}
                                    required
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="pre_dx">Pre-Operative Diagnosis</Label>
                                <Input
                                    id="pre_dx"
                                    value={intraOpForm.data.pre_operative_diagnosis}
                                    onChange={(e) => intraOpForm.setData('pre_operative_diagnosis', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="post_dx">Post-Operative Diagnosis</Label>
                                <Input
                                    id="post_dx"
                                    value={intraOpForm.data.post_operative_diagnosis}
                                    onChange={(e) => intraOpForm.setData('post_operative_diagnosis', e.target.value)}
                                    required
                                />
                            </div>
                        </div>

                        <div>
                            <Label htmlFor="technique">Operative Procedure / Technique</Label>
                            <Textarea
                                id="technique"
                                value={intraOpForm.data.surgical_technique}
                                onChange={(e) => intraOpForm.setData('surgical_technique', e.target.value)}
                                placeholder="Incision, anatomical approach, procedure steps, closure..."
                                rows={4}
                                required
                            />
                        </div>

                        <div>
                            <Label htmlFor="findings">Intra-Operative Findings</Label>
                            <Textarea
                                id="findings"
                                value={intraOpForm.data.intraoperative_findings}
                                onChange={(e) => intraOpForm.setData('intraoperative_findings', e.target.value)}
                                placeholder="Pathology found, anatomy..."
                                rows={2}
                            />
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="ebl">Estimated Blood Loss (ml)</Label>
                                <Input
                                    id="ebl"
                                    type="number"
                                    min="0"
                                    value={intraOpForm.data.estimated_blood_loss_ml}
                                    onChange={(e) => intraOpForm.setData('estimated_blood_loss_ml', e.target.value)}
                                    required
                                />
                            </div>
                            <div className="flex items-center pt-6">
                                <label className="flex items-center gap-2 cursor-pointer text-sm">
                                    <Checkbox
                                        checked={intraOpForm.data.swab_instrument_count_correct}
                                        onCheckedChange={(c) => intraOpForm.setData('swab_instrument_count_correct', Boolean(c))}
                                    />
                                    <span className="font-semibold">Swab & Instrument Counts Correct</span>
                                </label>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="implants">Implants / Prosthetics</Label>
                                <Input
                                    id="implants"
                                    placeholder="Mesh, screws, plates..."
                                    value={intraOpForm.data.implants_used}
                                    onChange={(e) => intraOpForm.setData('implants_used', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="drains">Drains Placed</Label>
                                <Input
                                    id="drains"
                                    placeholder="Jackson-Pratt, Redivac..."
                                    value={intraOpForm.data.drains_placed}
                                    onChange={(e) => intraOpForm.setData('drains_placed', e.target.value)}
                                />
                            </div>
                        </div>

                        <div>
                            <Label htmlFor="complications">Complications / Notes</Label>
                            <Input
                                id="complications"
                                placeholder="Any intraoperative complications or None..."
                                value={intraOpForm.data.complications}
                                onChange={(e) => intraOpForm.setData('complications', e.target.value)}
                            />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setIntraOpDialogOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={intraOpForm.processing} className="bg-indigo-600 hover:bg-indigo-700">
                                Save Intra-Op Record
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* MODAL 5: PACU RECOVERY ROOM */}
            <Dialog open={pacuDialogOpen} onOpenChange={setPacuDialogOpen}>
                <DialogContent className="max-w-xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>PACU Post-Anesthesia Recovery Record</DialogTitle>
                        <DialogDescription>
                            Assess recovery readiness (Aldrete score) and document post-operative nursing care orders.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submitPacu} className="flex flex-col gap-4">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="aldrete">Aldrete Score (0 - 10)</Label>
                                <Input
                                    id="aldrete"
                                    type="number"
                                    min="0"
                                    max="10"
                                    value={pacuForm.data.aldrete_score}
                                    onChange={(e) => pacuForm.setData('aldrete_score', e.target.value)}
                                    required
                                />
                                <span className="text-[11px] text-muted-foreground">Score &gt;= 8 indicates safe discharge readiness</span>
                            </div>
                            <div>
                                <Label htmlFor="trans_time">Transfer to Ward Time</Label>
                                <Input
                                    id="trans_time"
                                    type="datetime-local"
                                    value={pacuForm.data.transferred_to_ward_at}
                                    onChange={(e) => pacuForm.setData('transferred_to_ward_at', e.target.value)}
                                />
                            </div>
                        </div>

                        <div>
                            <Label htmlFor="post_orders">Post-Operative Care & Monitoring Orders</Label>
                            <Textarea
                                id="post_orders"
                                value={pacuForm.data.post_operative_orders}
                                onChange={(e) => pacuForm.setData('post_operative_orders', e.target.value)}
                                rows={3}
                                required
                            />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setPacuDialogOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={pacuForm.processing} className="bg-indigo-600 hover:bg-indigo-700">
                                Complete Recovery & Transfer
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* MODAL 6: BEDSIDE PROCEDURE EVENT */}
            <Dialog open={bedsideDialogOpen} onOpenChange={setBedsideDialogOpen}>
                <DialogContent className="max-w-xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Document Bedside Procedure</DialogTitle>
                        <DialogDescription>
                            Record ward-based procedure notes for {selectedOrderForBedside?.procedure_name}.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submitBedside} className="flex flex-col gap-4">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="perf_time">Performed At</Label>
                                <Input
                                    id="perf_time"
                                    type="datetime-local"
                                    value={bedsideForm.data.performed_at}
                                    onChange={(e) => bedsideForm.setData('performed_at', e.target.value)}
                                    required
                                />
                            </div>
                            <div>
                                <Label htmlFor="tolerance">Patient Tolerance</Label>
                                <Select
                                    value={bedsideForm.data.patient_tolerance}
                                    onValueChange={(v) => bedsideForm.setData('patient_tolerance', v)}
                                >
                                    <SelectTrigger id="tolerance">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="tolerated_well">Tolerated Well</SelectItem>
                                        <SelectItem value="moderate_discomfort">Moderate Discomfort</SelectItem>
                                        <SelectItem value="poorly_tolerated">Poorly Tolerated</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div>
                            <Label htmlFor="bedside_notes">Procedure Technique & Notes</Label>
                            <Textarea
                                id="bedside_notes"
                                value={bedsideForm.data.procedure_notes}
                                onChange={(e) => bedsideForm.setData('procedure_notes', e.target.value)}
                                placeholder="Site prep, aseptic technique, needle/catheter gauge, fluid aspirated, dressing..."
                                rows={3}
                                required
                            />
                        </div>

                        <div>
                            <Label htmlFor="consumables">Consumables & Equipment Used</Label>
                            <Input
                                id="consumables"
                                placeholder="e.g. Sterile gloves, 20G cannula, 50ml syringe, chlorhexidine"
                                value={bedsideForm.data.consumables_used}
                                onChange={(e) => bedsideForm.setData('consumables_used', e.target.value)}
                            />
                        </div>

                        <div>
                            <Label htmlFor="bed_comp">Complications</Label>
                            <Input
                                id="bed_comp"
                                placeholder="None or describe..."
                                value={bedsideForm.data.complications}
                                onChange={(e) => bedsideForm.setData('complications', e.target.value)}
                            />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setBedsideDialogOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={bedsideForm.processing} className="bg-emerald-600 hover:bg-emerald-700">
                                Save Bedside Procedure
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
