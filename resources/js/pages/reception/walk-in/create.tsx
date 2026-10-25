import InputError from '@/components/input-error';
import { PatientSelector } from '@/components/patients/patient-selector';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type AppointmentDepartment } from '@/types/appointment';
import { type PatientSearchResult } from '@/types/patient';
import {
    type DepartmentCatalogService,
    type NhifConsultationService,
    type NhifInsuranceSummary,
    type ReceptionPractitioner,
    type WalkInFormData,
} from '@/types/reception';
import { Head, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    Building2,
    Check,
    CheckCircle2,
    CreditCard,
    DollarSign,
    ExternalLink,
    FileText,
    Info,
    LoaderCircle,
    ShieldCheck,
    Stethoscope,
    User,
    UserCheck,
} from 'lucide-react';
import { type FormEventHandler, useEffect, useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reception', href: '/reception' },
    { title: 'Register Walk-in', href: '/reception/walk-in/create' },
];

const VISIT_TYPE_LABELS: Record<string, string> = {
    walk_in: 'Walk-in (General)',
    appointment: 'Appointment Walk-in',
    follow_up: 'Follow-up Visit',
    other: 'Other / Triage Intake',
};

interface WalkInCreateProps {
    departments: AppointmentDepartment[];
    visitTypes: string[];
    departmentServices: DepartmentCatalogService[];
    practitioners: ReceptionPractitioner[];
}

export default function WalkInCreate({
    departments,
    visitTypes,
    departmentServices = [],
    practitioners = [],
}: WalkInCreateProps) {
    const [selectedPatient, setSelectedPatient] = useState<PatientSearchResult | null>(null);
    const [paymentMode, setPaymentMode] = useState<'cash' | 'nhif'>('cash');
    const [serviceSearch, setServiceSearch] = useState('');

    const [nhifConsultationServices, setNhifConsultationServices] = useState<NhifConsultationService[]>([]);
    const [nhifInsurance, setNhifInsurance] = useState<NhifInsuranceSummary | null>(null);
    const [nhifAuthExpiredInfo, setNhifAuthExpiredInfo] = useState<{
        auth_expired: boolean;
        previous_auth_no?: string;
        verified_at?: string;
        expires_at?: string;
        patient_insurance_id?: number;
    } | null>(null);
    const [nhifServicesLoading, setNhifServicesLoading] = useState(false);
    const [nhifFetchError, setNhifFetchError] = useState<string | null>(null);

    const [consultationPrice, setConsultationPrice] = useState<{
        price: number | null;
        currency: string;
        nhif_unit_price: number | null;
    } | null>(null);

    const abortRef = useRef<AbortController | null>(null);
    const nhifAbortRef = useRef<AbortController | null>(null);

    const { data, setData, post, processing, errors } = useForm<WalkInFormData>({
        patient_id: '',
        department_id: '',
        visit_type: 'walk_in',
        practitioner_id: '',
        service_id: '',
        nhif_item_code: '',
        clinical_notes: '',
        practitioner_remarks: '',
    });

    const isNhifVerified = nhifInsurance !== null;
    const isNhifActive = paymentMode === 'nhif' && isNhifVerified;

    // Selected department model
    const selectedDepartment = useMemo(() => {
        return departments.find((d) => String(d.id) === data.department_id) ?? null;
    }, [departments, data.department_id]);

    const deptCode = (selectedDepartment?.code ?? '').toUpperCase();
    const deptName = (selectedDepartment?.name ?? '').toLowerCase();

    const isMaternity = deptCode === 'MATERNITY' || deptName.includes('maternity');
    const isDental = deptCode === 'DENTAL' || deptName.includes('dental');
    const isEmergency = deptCode === 'EMERGENCY' || deptName.includes('emergency');
    const isInpatient = deptCode === 'INPATIENT' || deptName.includes('inpatient');
    const isOpd = deptCode === 'OPD' || deptName.includes('outpatient');

    // ────────────────────────────────────────────────────────────────────────
    // Step 3: Filtered Services for Department
    // ────────────────────────────────────────────────────────────────────────

    // Cash Services filtered by chosen Department
    const availableCashServices = useMemo(() => {
        if (!selectedDepartment) return [];

        let filtered = departmentServices.filter((s) => {
            const cat = s.category.toLowerCase();
            const name = s.name.toLowerCase();
            const code = s.code.toUpperCase();

            if (isDental) {
                return (
                    cat === 'dental' ||
                    code === 'CONSULT-DENTAL' ||
                    name.includes('dental') ||
                    name.includes('tooth') ||
                    name.includes('teeth') ||
                    name.includes('denture') ||
                    name.includes('orthodontic')
                );
            }
            if (isMaternity) {
                return (
                    cat === 'maternity' ||
                    name.includes('maternity') ||
                    name.includes('antenatal') ||
                    name.includes('delivery') ||
                    name.includes('obstetric') ||
                    name.includes('postnatal') ||
                    cat === 'consultation'
                );
            }
            if (isEmergency) {
                return (
                    cat === 'emergency' ||
                    name.includes('emergency') ||
                    name.includes('resuscitation') ||
                    name.includes('triage') ||
                    cat === 'consultation'
                );
            }
            if (isInpatient) {
                return (
                    cat === 'inpatient' ||
                    code.startsWith('WARD-') ||
                    name.includes('ward') ||
                    name.includes('admission') ||
                    name.includes('icu') ||
                    name.includes('hdu')
                );
            }
            if (isOpd) {
                return (
                    cat === 'consultation' ||
                    code.startsWith('CONSULT-') ||
                    name.includes('consultation') ||
                    name.includes('suturing')
                );
            }

            return cat === 'consultation' || cat === 'procedure';
        });

        if (filtered.length === 0) {
            filtered = departmentServices.filter((s) => s.category === 'consultation' || s.category === 'procedure');
        }

        if (serviceSearch.trim()) {
            const q = serviceSearch.toLowerCase().trim();
            filtered = filtered.filter((s) => s.name.toLowerCase().includes(q) || s.code.toLowerCase().includes(q));
        }

        return filtered;
    }, [selectedDepartment, departmentServices, isDental, isMaternity, isEmergency, isInpatient, isOpd, serviceSearch]);

    // NHIF Services filtered by chosen Department
    const availableNhifServices = useMemo(() => {
        if (!isNhifActive || nhifConsultationServices.length === 0) return [];
        if (!selectedDepartment) return nhifConsultationServices;

        let filtered = nhifConsultationServices.filter((s) => {
            const name = s.item_name.toLowerCase();
            const type = (s.type_name ?? '').toLowerCase();

            if (isDental) {
                return name.includes('dental') || type.includes('dental') || name.includes('tooth') || name.includes('dds');
            }
            if (isMaternity) {
                return name.includes('maternity') || name.includes('obstetric') || name.includes('delivery') || type.includes('maternity') || name.includes('consultation');
            }
            if (isEmergency) {
                return name.includes('emergency') || name.includes('resuscitation') || name.includes('consultation');
            }
            if (isInpatient) {
                return name.includes('admission') || name.includes('ward') || name.includes('inpatient');
            }
            return true;
        });

        // Fallback: If department filter leaves 0, show all available NHIF services so user is never blocked
        return filtered.length > 0 ? filtered : nhifConsultationServices;
    }, [isNhifActive, nhifConsultationServices, selectedDepartment, isDental, isMaternity, isEmergency, isInpatient]);

    // Selected Cash Service object
    const selectedCashService = useMemo(() => {
        return departmentServices.find((s) => String(s.id) === data.service_id) ?? null;
    }, [departmentServices, data.service_id]);

    // Selected NHIF Service object
    const selectedNhifService = useMemo(() => {
        return nhifConsultationServices.find((s) => String(s.item_code) === data.nhif_item_code) ?? null;
    }, [nhifConsultationServices, data.nhif_item_code]);

    // ────────────────────────────────────────────────────────────────────────
    // Step 4: Qualified Practitioners for Department & Service
    // ────────────────────────────────────────────────────────────────────────

    const qualifiedPractitioners = useMemo(() => {
        if (!selectedDepartment) return [];

        let pool = practitioners;

        // In NHIF mode, practitioner MUST have registered NHIF number
        if (isNhifActive) {
            pool = pool.filter((p) => Boolean(p.nhif_practitioner_no));

            if (selectedNhifService) {
                const svcId = selectedNhifService.service_id;
                const svcCode = selectedNhifService.item_code;
                const matchesService = pool.filter((p) => {
                    const matchesId = svcId && p.consultation_service_id === svcId;
                    const matchesCode = p.consultation_service?.code === svcCode;
                    return Boolean(matchesId || matchesCode);
                });
                if (matchesService.length > 0) {
                    return matchesService;
                }
            }
        }

        // Department-specific filtering
        if (isDental) {
            const dentists = pool.filter(
                (p) => p.staff?.department_id === selectedDepartment.id || p.practitioner_type === 'dentist'
            );
            return dentists.length > 0 ? dentists : pool;
        }

        if (isMaternity) {
            // For Maternity: include staff in maternity, nurses/midwives, and doctors/obstetricians
            return pool.filter(
                (p) =>
                    p.staff?.department_id === selectedDepartment.id ||
                    p.practitioner_type === 'nurse' ||
                    p.practitioner_type === 'doctor' ||
                    p.practitioner_type === 'specialist' ||
                    p.practitioner_type === 'clinical-officer'
            );
        }

        if (isInpatient) {
            const inpt = pool.filter(
                (p) =>
                    p.staff?.department_id === selectedDepartment.id ||
                    p.practitioner_type === 'doctor' ||
                    p.practitioner_type === 'nurse'
            );
            return inpt.length > 0 ? inpt : pool;
        }

        if (isOpd || isEmergency) {
            const opdStaff = pool.filter(
                (p) =>
                    p.staff?.department_id === selectedDepartment.id ||
                    p.practitioner_type === 'doctor' ||
                    p.practitioner_type === 'clinical-officer' ||
                    p.practitioner_type === 'specialist'
            );
            return opdStaff.length > 0 ? opdStaff : pool;
        }

        return pool;
    }, [practitioners, selectedDepartment, isNhifActive, selectedNhifService, isDental, isMaternity, isInpatient, isOpd, isEmergency]);

    // Grouping practitioners for Maternity (Nurse/Midwife vs Doctor)
    const maternityGrouped = useMemo(() => {
        if (!isMaternity) return null;

        const nurses = qualifiedPractitioners.filter(
            (p) =>
                p.practitioner_type === 'nurse' ||
                p.staff?.job_title?.toLowerCase().includes('nurse') ||
                p.staff?.job_title?.toLowerCase().includes('midwife')
        );
        const doctors = qualifiedPractitioners.filter(
            (p) =>
                p.practitioner_type === 'doctor' ||
                p.practitioner_type === 'specialist' ||
                p.practitioner_type === 'clinical-officer' ||
                (!nurses.includes(p) && p.nhif_practitioner_no)
        );

        return { nurses, doctors };
    }, [isMaternity, qualifiedPractitioners]);

    const selectedPractitioner = useMemo(() => {
        return practitioners.find((p) => String(p.id) === data.practitioner_id) ?? null;
    }, [practitioners, data.practitioner_id]);

    const practitionerName = selectedPractitioner?.staff
        ? `${selectedPractitioner.staff.first_name} ${selectedPractitioner.staff.last_name}`
        : (selectedPractitioner?.practitioner_number ?? '');

    // ────────────────────────────────────────────────────────────────────────
    // Effects: Patient selection & NHIF verification fetch
    // ────────────────────────────────────────────────────────────────────────

    useEffect(() => {
        if (nhifAbortRef.current) nhifAbortRef.current.abort();

        if (!data.patient_id) {
            setNhifConsultationServices([]);
            setNhifInsurance(null);
            setNhifAuthExpiredInfo(null);
            setPaymentMode('cash');
            setData('nhif_item_code', '');
            setData('service_id', '');
            return;
        }

        const ctrl = new AbortController();
        nhifAbortRef.current = ctrl;
        setNhifServicesLoading(true);
        setNhifFetchError(null);
        setNhifAuthExpiredInfo(null);

        fetch(route('reception.nhif-consultation-services') + '?patient_id=' + data.patient_id, {
            signal: ctrl.signal,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((r) => {
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                return r.json();
            })
            .then((json: {
                services?: NhifConsultationService[];
                insurance?: NhifInsuranceSummary | null;
                auth_expired?: boolean;
                previous_auth_no?: string;
                verified_at?: string;
                expires_at?: string;
                patient_insurance_id?: number;
            }) => {
                if (ctrl.signal.aborted) return;
                const svcs = Array.isArray(json.services) ? json.services : [];
                setNhifConsultationServices(svcs);
                setNhifInsurance(json.insurance ?? null);

                if (json.insurance) {
                    setPaymentMode('nhif');
                } else {
                    setPaymentMode('cash');
                }

                if (json.auth_expired) {
                    setNhifAuthExpiredInfo({
                        auth_expired: true,
                        previous_auth_no: json.previous_auth_no,
                        verified_at: json.verified_at,
                        expires_at: json.expires_at,
                        patient_insurance_id: json.patient_insurance_id,
                    });
                } else {
                    setNhifAuthExpiredInfo(null);
                }
                setNhifServicesLoading(false);
            })
            .catch((err) => {
                if (!ctrl.signal.aborted) {
                    console.error('NHIF fetch error:', err);
                    setNhifConsultationServices([]);
                    setNhifInsurance(null);
                    setNhifAuthExpiredInfo(null);
                    setPaymentMode('cash');
                    setNhifServicesLoading(false);
                    setNhifFetchError('Failed to check NHIF coverage. Please reload the page.');
                }
            });
    }, [data.patient_id]);

    // Auto-select practitioner if single eligible for NHIF
    useEffect(() => {
        if (!isNhifActive) return;

        if (!data.nhif_item_code) {
            if (data.practitioner_id) setData('practitioner_id', '');
            return;
        }

        if (qualifiedPractitioners.length === 1) {
            const singleId = String(qualifiedPractitioners[0].id);
            if (data.practitioner_id !== singleId) {
                setData('practitioner_id', singleId);
            }
        } else if (
            data.practitioner_id &&
            !qualifiedPractitioners.some((p) => String(p.id) === data.practitioner_id)
        ) {
            setData('practitioner_id', '');
        }
    }, [data.nhif_item_code, isNhifActive, qualifiedPractitioners, data.practitioner_id, setData]);

    // Live Consultation Price calculation via endpoint
    useEffect(() => {
        if (abortRef.current) abortRef.current.abort();

        const shouldFetch =
            data.department_id &&
            (data.practitioner_id || (isNhifActive && data.nhif_item_code));

        if (!shouldFetch) {
            setConsultationPrice(null);
            return;
        }

        const ctrl = new AbortController();
        abortRef.current = ctrl;

        const params = new URLSearchParams({ department_id: data.department_id });
        if (data.practitioner_id) params.set('practitioner_id', data.practitioner_id);

        if (isNhifActive && data.nhif_item_code) {
            params.set('nhif_item_code', data.nhif_item_code);
            params.set('patient_id', data.patient_id);
        }

        fetch(route('reception.consultation-price') + '?' + params.toString(), {
            signal: ctrl.signal,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((r) => r.json())
            .then((json: { price: number | null; currency?: string; nhif_unit_price?: number | null }) => {
                if (!ctrl.signal.aborted) {
                    setConsultationPrice({
                        price: json.price,
                        currency: json.currency ?? 'TZS',
                        nhif_unit_price: json.nhif_unit_price ?? null,
                    });
                }
            })
            .catch(() => {
                if (!ctrl.signal.aborted) setConsultationPrice(null);
            });
    }, [data.department_id, data.practitioner_id, data.nhif_item_code, isNhifActive]);

    // Handler when changing department: reset service and practitioner
    const handleDepartmentChange = (deptId: string) => {
        setData((prev) => ({
            ...prev,
            department_id: deptId,
            service_id: '',
            nhif_item_code: '',
            practitioner_id: '',
        }));
        setConsultationPrice(null);
    };

    // Handler when toggling payment mode
    const handlePaymentModeToggle = (mode: 'cash' | 'nhif') => {
        setPaymentMode(mode);
        if (mode === 'cash') {
            setData((prev) => ({
                ...prev,
                nhif_item_code: '',
            }));
        } else {
            setData((prev) => ({
                ...prev,
                service_id: '',
            }));
        }
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('reception.walk-in.store'));
    };

    // Computed display price
    const cashDisplayPrice = selectedCashService?.price ?? consultationPrice?.price ?? null;
    const nhifPrice =
        consultationPrice?.nhif_unit_price ??
        (selectedNhifService?.unit_price ? parseFloat(selectedNhifService.unit_price) : null) ??
        consultationPrice?.price ??
        null;
    const currency = consultationPrice?.currency ?? selectedCashService?.currency ?? 'TZS';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Register Walk-in Patient" />

            <div className="p-4 sm:p-6 lg:p-8">
                <form onSubmit={submit} className="mx-auto max-w-4xl space-y-6">

                    {/* Page Header */}
                    <div className="flex flex-col gap-1 md:flex-row md:items-center md:justify-between border-b pb-4">
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight text-foreground">
                                Walk-in Patient Registration
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                Guided reception intake: payment scheme, department, service, practitioner, and queue assignment.
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            <Badge variant="outline" className="text-xs px-2.5 py-1 font-mono">
                                Step-by-Step Flow
                            </Badge>
                        </div>
                    </div>

                    {/* ══════════════════════════════════════════════════════════
                        STEP 1: PATIENT & PAYMENT METHOD
                    ══════════════════════════════════════════════════════════ */}
                    <Card className="border-l-4 border-l-blue-500 shadow-sm">
                        <CardHeader className="pb-3">
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-base flex items-center gap-2">
                                    <span className="flex h-6 w-6 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">
                                        1
                                    </span>
                                    <span>Patient & Payment Scheme</span>
                                </CardTitle>
                                {selectedPatient && (
                                    <Badge variant="secondary" className="font-mono text-xs">
                                        {selectedPatient.patient_number}
                                    </Badge>
                                )}
                            </div>
                            <CardDescription>
                                Search existing patient or register new, and confirm payment method.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {/* Patient Search Component */}
                            <div className="space-y-2">
                                <Label htmlFor="patient_selector">Select Patient *</Label>
                                <PatientSelector
                                    value={data.patient_id}
                                    selected={selectedPatient}
                                    onSelect={(patient) => {
                                        setSelectedPatient(patient);
                                        setData('patient_id', String(patient.id));
                                    }}
                                />
                                <InputError message={errors.patient_id} />
                                <p className="text-xs text-muted-foreground flex items-center justify-between pt-1">
                                    <span>Can't find patient? Register first and return here.</span>
                                    <a
                                        href={route('patients.create')}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-primary inline-flex items-center gap-1 font-medium hover:underline"
                                    >
                                        Register New Patient <ExternalLink className="h-3 w-3" />
                                    </a>
                                </p>
                            </div>

                            {/* Loading State */}
                            {nhifServicesLoading && (
                                <div className="flex items-center gap-2 rounded-lg border bg-muted/40 p-3 text-sm text-muted-foreground animate-pulse">
                                    <LoaderCircle className="h-4 w-4 animate-spin text-primary" />
                                    <span>Verifying patient insurance & NHIF electronic pre-authorization eligibility…</span>
                                </div>
                            )}

                            {/* Error Fetching NHIF */}
                            {nhifFetchError && !nhifServicesLoading && (
                                <div className="flex items-start gap-2.5 rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">
                                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                    <span>{nhifFetchError}</span>
                                </div>
                            )}

                            {/* Expired NHIF Authorization Warning */}
                            {!nhifServicesLoading && selectedPatient && nhifAuthExpiredInfo?.auth_expired && (
                                <div className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-900 dark:border-amber-700/50 dark:bg-amber-950/40 dark:text-amber-200">
                                    <div className="flex items-start gap-3">
                                        <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
                                        <div className="flex-1 space-y-1">
                                            <p className="font-semibold text-sm">NHIF 24-Hour Authorization Expired</p>
                                            <p className="text-xs text-amber-800 dark:text-amber-300">
                                                The patient's authorization number ({nhifAuthExpiredInfo.previous_auth_no}) was verified on{' '}
                                                {nhifAuthExpiredInfo.verified_at ?? 'more than 24h ago'} and has expired. Re-verification is required for NHIF billing.
                                            </p>
                                            {nhifAuthExpiredInfo.patient_insurance_id && (
                                                <div className="pt-2">
                                                    <a
                                                        href={`/patients/${data.patient_id}/insurance/${nhifAuthExpiredInfo.patient_insurance_id}`}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="inline-flex items-center gap-1.5 rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-amber-700"
                                                    >
                                                        Re-verify NHIF Card on Patient File
                                                        <ExternalLink className="h-3.5 w-3.5" />
                                                    </a>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            )}

                            {/* Payment Method Switcher (When Patient is Selected) */}
                            {selectedPatient && !nhifServicesLoading && (
                                <div className="rounded-xl border bg-card p-4 shadow-sm space-y-3">
                                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                        <Label className="text-sm font-semibold flex items-center gap-2">
                                            <CreditCard className="h-4 w-4 text-primary" />
                                            Select Payment Scheme
                                        </Label>
                                        <div className="inline-flex rounded-lg border bg-muted/60 p-1">
                                            <button
                                                type="button"
                                                onClick={() => handlePaymentModeToggle('cash')}
                                                className={`flex items-center gap-1.5 rounded-md px-3.5 py-1.5 text-xs font-semibold transition-all ${
                                                    paymentMode === 'cash'
                                                        ? 'bg-background text-foreground shadow-sm'
                                                        : 'text-muted-foreground hover:text-foreground'
                                                }`}
                                            >
                                                <DollarSign className="h-3.5 w-3.5 text-amber-600" />
                                                Cash / Private
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => handlePaymentModeToggle('nhif')}
                                                disabled={!isNhifVerified}
                                                className={`flex items-center gap-1.5 rounded-md px-3.5 py-1.5 text-xs font-semibold transition-all ${
                                                    paymentMode === 'nhif'
                                                        ? 'bg-emerald-600 text-white shadow-sm dark:bg-emerald-700'
                                                        : isNhifVerified
                                                        ? 'text-muted-foreground hover:text-foreground'
                                                        : 'cursor-not-allowed opacity-50 text-muted-foreground'
                                                }`}
                                            >
                                                <ShieldCheck className="h-3.5 w-3.5" />
                                                NHIF Insurance
                                            </button>
                                        </div>
                                    </div>

                                    {/* Active NHIF Insurance Badge/Details */}
                                    {isNhifVerified && (
                                        <div className={`rounded-lg border p-3 transition-colors ${
                                            paymentMode === 'nhif'
                                                ? 'border-emerald-200 bg-emerald-50/70 dark:border-emerald-800 dark:bg-emerald-950/30'
                                                : 'border-muted bg-muted/30 opacity-70'
                                        }`}>
                                            <div className="flex items-center justify-between mb-2">
                                                <span className="flex items-center gap-1.5 text-xs font-bold text-emerald-800 dark:text-emerald-300">
                                                    <ShieldCheck className="h-4 w-4 text-emerald-600" />
                                                    Verified NHIF Member: {nhifInsurance.scheme_name ?? `Scheme #${nhifInsurance.scheme_id}`}
                                                </span>
                                                {nhifInsurance.hours_remaining !== undefined && nhifInsurance.hours_remaining !== null && (
                                                    <Badge variant="outline" className="border-emerald-300 text-[11px] text-emerald-800 dark:text-emerald-300">
                                                        ~{nhifInsurance.hours_remaining}h authorization valid
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="grid grid-cols-2 sm:grid-cols-3 gap-2 text-xs">
                                                <div>
                                                    <span className="text-muted-foreground">Auth No: </span>
                                                    <span className="font-mono font-medium">{nhifInsurance.nhif_authorization_no}</span>
                                                </div>
                                                {nhifInsurance.card_number && (
                                                    <div>
                                                        <span className="text-muted-foreground">Card: </span>
                                                        <span className="font-mono font-medium">{nhifInsurance.card_number}</span>
                                                    </div>
                                                )}
                                                <div>
                                                    <span className="text-muted-foreground">File/PF No: </span>
                                                    <span className="font-mono font-medium">{nhifInsurance.nhif_pf_number ?? selectedPatient.patient_number}</span>
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* Uninsured or Cash Mode Notice */}
                                    {!isNhifVerified && !nhifAuthExpiredInfo?.auth_expired && (
                                        <div className="flex items-center gap-2 rounded-lg border bg-amber-50/70 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/20 dark:text-amber-300">
                                            <Info className="h-4 w-4 shrink-0 text-amber-600" />
                                            <span>
                                                Patient has no active verified NHIF coverage card on file. Registration will proceed with <strong>CASH billing</strong>.
                                            </span>
                                        </div>
                                    )}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* ══════════════════════════════════════════════════════════
                        STEP 2: DESTINATION DEPARTMENT
                    ══════════════════════════════════════════════════════════ */}
                    {selectedPatient && (
                        <Card className="border-l-4 border-l-indigo-500 shadow-sm">
                            <CardHeader className="pb-3">
                                <div className="flex items-center justify-between">
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <span className="flex h-6 w-6 items-center justify-center rounded-full bg-indigo-100 text-xs font-bold text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                                            2
                                        </span>
                                        <span>Destination Department</span>
                                    </CardTitle>
                                    {selectedDepartment && (
                                        <Badge variant="secondary" className="text-xs">
                                            {selectedDepartment.name}
                                        </Badge>
                                    )}
                                </div>
                                <CardDescription>
                                    Select where the patient will be received. Services and practitioners will automatically adapt.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="department_select">Clinical Department *</Label>
                                    <Select
                                        value={data.department_id}
                                        onValueChange={handleDepartmentChange}
                                    >
                                        <SelectTrigger id="department_select" className="h-11">
                                            <SelectValue placeholder="Choose Department (OPD, Maternity, Dental, Emergency, Inpatient...)" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {departments.map((dept) => (
                                                <SelectItem key={dept.id} value={String(dept.id)}>
                                                    <div className="flex items-center gap-2">
                                                        <Building2 className="h-4 w-4 text-muted-foreground" />
                                                        <span className="font-medium">{dept.name}</span>
                                                        {dept.code && (
                                                            <span className="text-xs text-muted-foreground">({dept.code})</span>
                                                        )}
                                                    </div>
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.department_id} />
                                </div>

                                {/* Department highlight badge/guidance */}
                                {selectedDepartment && (
                                    <div className="rounded-lg border bg-muted/40 p-3 text-xs flex items-center gap-2">
                                        <Info className="h-4 w-4 text-primary shrink-0" />
                                        <span>
                                            {isMaternity && 'Maternity Department: Routine nursing intake & midwife care available for cash, or authorized medical doctors for NHIF electronic claims.'}
                                            {isDental && 'Dental Department: Dental consultation, surgery, fillings, dentures, and qualified dentists.'}
                                            {isEmergency && 'Emergency Department: Resuscitation, urgent triage, suturing, and emergency care.'}
                                            {isInpatient && 'Inpatient Department: Ward admission, ICU/HDU bed booking, and nursing supervisor assignment.'}
                                            {isOpd && 'Outpatient Department: General practice, specialty clinics, eye consultations, and outpatient procedures.'}
                                            {!isMaternity && !isDental && !isEmergency && !isInpatient && !isOpd && `${selectedDepartment.name}: Clinical consultation and services.`}
                                        </span>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {/* ══════════════════════════════════════════════════════════
                        STEP 3: DEPARTMENT SERVICES
                    ══════════════════════════════════════════════════════════ */}
                    {selectedPatient && selectedDepartment && (
                        <Card className="border-l-4 border-l-emerald-500 shadow-sm">
                            <CardHeader className="pb-3">
                                <div className="flex items-center justify-between">
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <span className="flex h-6 w-6 items-center justify-center rounded-full bg-emerald-100 text-xs font-bold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                                            3
                                        </span>
                                        <span>Department Services ({selectedDepartment.name})</span>
                                    </CardTitle>
                                    <Badge
                                        variant={isNhifActive ? 'default' : 'secondary'}
                                        className={isNhifActive ? 'bg-emerald-600' : 'text-xs'}
                                    >
                                        {isNhifActive ? 'NHIF Catalog' : 'Hospital Cash Catalog'}
                                    </Badge>
                                </div>
                                <CardDescription>
                                    Choose the specific service or consultation tier for {selectedDepartment.name}.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {isNhifActive ? (
                                    /* NHIF SERVICE SELECTOR */
                                    <div className="space-y-3">
                                        <div className="grid gap-2">
                                            <Label htmlFor="nhif_service_select">NHIF Consultation / Procedure Service *</Label>
                                            {availableNhifServices.length > 0 ? (
                                                <Select
                                                    value={data.nhif_item_code || '__none__'}
                                                    onValueChange={(val) => setData('nhif_item_code', val === '__none__' ? '' : val)}
                                                >
                                                    <SelectTrigger id="nhif_service_select" className="h-11">
                                                        <SelectValue placeholder="Select NHIF covered service…" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="__none__">Select an NHIF service…</SelectItem>
                                                        {availableNhifServices.map((svc) => (
                                                            <SelectItem key={svc.id} value={String(svc.item_code)}>
                                                                <div className="flex items-center justify-between gap-4">
                                                                    <span>{svc.item_name}</span>
                                                                    {svc.unit_price && (
                                                                        <span className="text-xs font-mono font-semibold text-emerald-600 dark:text-emerald-400">
                                                                            TZS {parseFloat(svc.unit_price).toLocaleString()}
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            ) : (
                                                <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-950/20 dark:text-amber-300">
                                                    No NHIF services mapped for this scheme and department. Please verify NHIF service catalog.
                                                </div>
                                            )}
                                            <InputError message={errors.nhif_item_code} />
                                        </div>

                                        {selectedNhifService && (
                                            <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-emerald-50/60 p-3 text-xs border border-emerald-200 dark:bg-emerald-950/20 dark:border-emerald-800">
                                                <div>
                                                    <span className="text-muted-foreground">NHIF Code: </span>
                                                    <span className="font-mono font-bold text-foreground">{selectedNhifService.item_code}</span>
                                                    <span className="mx-2 text-muted-foreground">•</span>
                                                    <span className="text-muted-foreground">Type: </span>
                                                    <span>{selectedNhifService.type_name ?? 'Consultation'}</span>
                                                </div>
                                                {nhifPrice !== null && (
                                                    <div className="font-semibold text-emerald-700 dark:text-emerald-400">
                                                        Covered Rate: TZS {nhifPrice.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                ) : (
                                    /* CASH SERVICE SELECTOR */
                                    <div className="space-y-3">
                                        <div className="flex flex-col sm:flex-row gap-2">
                                            <div className="flex-1">
                                                <Label htmlFor="cash_service_select">Select Service / Consultation Tier *</Label>
                                                <Select
                                                    value={data.service_id || '__none__'}
                                                    onValueChange={(val) => setData('service_id', val === '__none__' ? '' : val)}
                                                >
                                                    <SelectTrigger id="cash_service_select" className="h-11 mt-1.5">
                                                        <SelectValue placeholder={`Select ${selectedDepartment.name} service…`} />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="__none__">Select a service…</SelectItem>
                                                        {availableCashServices.map((svc) => (
                                                            <SelectItem key={svc.id} value={String(svc.id)}>
                                                                <div className="flex items-center justify-between gap-4">
                                                                    <span>{svc.name}</span>
                                                                    {svc.price !== null && (
                                                                        <span className="text-xs font-mono font-semibold text-amber-700 dark:text-amber-400">
                                                                            {svc.currency} {svc.price.toLocaleString()}
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div className="w-full sm:w-48">
                                                <Label htmlFor="service_filter" className="text-xs text-muted-foreground">
                                                    Filter Services
                                                </Label>
                                                <Input
                                                    id="service_filter"
                                                    placeholder="Search service…"
                                                    className="h-11 mt-1.5 text-xs"
                                                    value={serviceSearch}
                                                    onChange={(e) => setServiceSearch(e.target.value)}
                                                />
                                            </div>
                                        </div>
                                        <InputError message={errors.service_id} />

                                        {selectedCashService && (
                                            <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-amber-50/60 p-3 text-xs border border-amber-200 dark:bg-amber-950/20 dark:border-amber-800">
                                                <div>
                                                    <span className="text-muted-foreground">Service Code: </span>
                                                    <span className="font-mono font-bold text-foreground">{selectedCashService.code}</span>
                                                    <span className="mx-2 text-muted-foreground">•</span>
                                                    <span className="text-muted-foreground">Category: </span>
                                                    <span className="capitalize">{selectedCashService.category}</span>
                                                </div>
                                                {selectedCashService.price !== null && (
                                                    <div className="font-bold text-amber-700 dark:text-amber-400 text-sm">
                                                        Fee: {selectedCashService.currency} {selectedCashService.price.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {/* ══════════════════════════════════════════════════════════
                        STEP 4: QUALIFIED PRACTITIONER
                    ══════════════════════════════════════════════════════════ */}
                    {selectedPatient && selectedDepartment && (
                        <Card className="border-l-4 border-l-purple-500 shadow-sm">
                            <CardHeader className="pb-3">
                                <div className="flex items-center justify-between">
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <span className="flex h-6 w-6 items-center justify-center rounded-full bg-purple-100 text-xs font-bold text-purple-700 dark:bg-purple-900/40 dark:text-purple-300">
                                            4
                                        </span>
                                        <span>Qualified Practitioner</span>
                                    </CardTitle>
                                    {selectedPractitioner && (
                                        <Badge variant="outline" className="text-xs">
                                            {selectedPractitioner.practitioner_type ?? 'Practitioner'}
                                        </Badge>
                                    )}
                                </div>
                                <CardDescription>
                                    Assign a clinician or nurse qualified for {selectedDepartment.name}.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {/* Maternity Special Guidance */}
                                {isMaternity && (
                                    <div className="rounded-lg border border-purple-200 bg-purple-50/50 p-3.5 text-xs text-purple-900 dark:border-purple-800 dark:bg-purple-950/20 dark:text-purple-300 space-y-1">
                                        <div className="font-semibold flex items-center gap-1.5 text-sm">
                                            <Stethoscope className="h-4 w-4 text-purple-600" />
                                            Maternity Care Roles:
                                        </div>
                                        <p>
                                            • <strong>Routine Care & Nursing Intake:</strong> Midwives and nurses perform initial examinations, partograph tracking, and inpatient ward care.
                                        </p>
                                        <p>
                                            • <strong>NHIF & Medical Consultation:</strong> Electronic NHIF claims strictly require an authorized medical doctor or obstetrician with an active NHIF practitioner number.
                                        </p>
                                    </div>
                                )}

                                <div className="grid gap-2">
                                    <Label htmlFor="practitioner_select">
                                        {isNhifActive ? 'Practitioner (NHIF Authorized Doctor) *' : 'Attending Practitioner *'}
                                    </Label>
                                    <Select
                                        value={data.practitioner_id || '__none__'}
                                        onValueChange={(val) => setData('practitioner_id', val === '__none__' ? '' : val)}
                                        disabled={isNhifActive && (!data.nhif_item_code || qualifiedPractitioners.length === 0)}
                                    >
                                        <SelectTrigger id="practitioner_select" className="h-11">
                                            <SelectValue
                                                placeholder={
                                                    isNhifActive && !data.nhif_item_code
                                                        ? 'Select NHIF consultation service first'
                                                        : qualifiedPractitioners.length === 0
                                                        ? 'No qualified practitioner found for this selection'
                                                        : 'Select attending practitioner…'
                                                }
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">Select a practitioner…</SelectItem>

                                            {/* In Maternity: Render grouped sections if available */}
                                            {isMaternity && maternityGrouped && !isNhifActive ? (
                                                <>
                                                    {maternityGrouped.nurses.length > 0 && (
                                                        <div className="px-2 py-1.5 text-xs font-bold text-muted-foreground uppercase tracking-wider">
                                                            Midwives & Nursing Staff (Routine Care & Admission)
                                                        </div>
                                                    )}
                                                    {maternityGrouped.nurses.map((p) => (
                                                        <SelectItem key={p.id} value={String(p.id)}>
                                                            <div className="flex items-center gap-2">
                                                                <User className="h-3.5 w-3.5 text-blue-500" />
                                                                <span>
                                                                    {p.staff ? `${p.staff.first_name} ${p.staff.last_name}` : p.practitioner_number}
                                                                </span>
                                                                <span className="text-[11px] text-muted-foreground">
                                                                    ({p.staff?.job_title ?? 'Nurse/Midwife'})
                                                                </span>
                                                            </div>
                                                        </SelectItem>
                                                    ))}

                                                    {maternityGrouped.doctors.length > 0 && (
                                                        <div className="px-2 py-1.5 text-xs font-bold text-muted-foreground uppercase tracking-wider mt-2 border-t pt-2">
                                                            Doctors & Obstetricians (Clinical / NHIF)
                                                        </div>
                                                    )}
                                                    {maternityGrouped.doctors.map((p) => (
                                                        <SelectItem key={p.id} value={String(p.id)}>
                                                            <div className="flex items-center gap-2">
                                                                <Stethoscope className="h-3.5 w-3.5 text-purple-600" />
                                                                <span>
                                                                    {p.staff ? `${p.staff.first_name} ${p.staff.last_name}` : p.practitioner_number}
                                                                </span>
                                                                <span className="text-[11px] text-muted-foreground">
                                                                    ({p.practitioner_type ?? 'Doctor'})
                                                                </span>
                                                                {p.nhif_practitioner_no && (
                                                                    <Badge variant="outline" className="text-[10px] py-0 px-1 text-emerald-700">
                                                                        NHIF: {p.nhif_practitioner_no}
                                                                    </Badge>
                                                                )}
                                                            </div>
                                                        </SelectItem>
                                                    ))}
                                                </>
                                            ) : (
                                                /* Standard List for other departments or NHIF mode */
                                                qualifiedPractitioners.map((p) => (
                                                    <SelectItem key={p.id} value={String(p.id)}>
                                                        <div className="flex items-center gap-2">
                                                            <UserCheck className="h-3.5 w-3.5 text-muted-foreground" />
                                                            <span>
                                                                {p.staff ? `${p.staff.first_name} ${p.staff.last_name}` : p.practitioner_number}
                                                            </span>
                                                            <span className="text-xs text-muted-foreground">
                                                                ({p.staff?.job_title ?? p.practitioner_type ?? 'Staff'})
                                                            </span>
                                                            {p.nhif_practitioner_no && (
                                                                <Badge variant="outline" className="text-[10px] py-0 px-1 text-emerald-700 dark:text-emerald-300">
                                                                    NHIF
                                                                </Badge>
                                                            )}
                                                        </div>
                                                    </SelectItem>
                                                ))
                                            )}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.practitioner_id} />
                                </div>

                                {/* Selected Practitioner Info Badge */}
                                {selectedPractitioner && (
                                    <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-muted/40 p-3 text-xs">
                                        <div className="flex items-center gap-2">
                                            <UserCheck className="h-4 w-4 text-primary" />
                                            <span className="font-semibold text-foreground">{practitionerName}</span>
                                            <span className="text-muted-foreground">
                                                • {selectedPractitioner.staff?.job_title ?? selectedPractitioner.practitioner_type ?? 'Practitioner'}
                                            </span>
                                        </div>
                                        {selectedPractitioner.nhif_practitioner_no ? (
                                            <span className="flex items-center gap-1 font-mono text-emerald-700 dark:text-emerald-400 font-medium">
                                                <CheckCircle2 className="h-3.5 w-3.5" />
                                                NHIF Reg: {selectedPractitioner.nhif_practitioner_no}
                                            </span>
                                        ) : isNhifActive ? (
                                            <span className="text-destructive font-medium flex items-center gap-1">
                                                <AlertCircle className="h-3.5 w-3.5" />
                                                No NHIF Number — Invalid for NHIF Preapproval
                                            </span>
                                        ) : null}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {/* ══════════════════════════════════════════════════════════
                        STEP 5: VISIT DETAILS & SUBMISSION
                    ══════════════════════════════════════════════════════════ */}
                    {selectedPatient && selectedDepartment && (
                        <Card className="border-l-4 border-l-slate-600 shadow-sm">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base flex items-center gap-2">
                                    <span className="flex h-6 w-6 items-center justify-center rounded-full bg-slate-200 text-xs font-bold text-slate-800 dark:bg-slate-800 dark:text-slate-200">
                                        5
                                    </span>
                                    <span>Visit Details & Confirmation</span>
                                </CardTitle>
                                <CardDescription>
                                    Specify visit classification and clinical remarks for reception handover.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="visit_type_select">Visit Type *</Label>
                                        <Select
                                            value={data.visit_type}
                                            onValueChange={(val) => setData('visit_type', val as WalkInFormData['visit_type'])}
                                        >
                                            <SelectTrigger id="visit_type_select">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {visitTypes.map((t) => (
                                                    <SelectItem key={t} value={t}>
                                                        {VISIT_TYPE_LABELS[t] ?? t}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={errors.visit_type} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="practitioner_remarks">Practitioner Handover Remarks</Label>
                                        <Input
                                            id="practitioner_remarks"
                                            placeholder="e.g. Priority triage, urgent consultation"
                                            value={data.practitioner_remarks}
                                            onChange={(e) => setData('practitioner_remarks', e.target.value)}
                                        />
                                        <InputError message={errors.practitioner_remarks} />
                                    </div>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="clinical_notes">Reception Clinical Notes / Chief Complaint</Label>
                                    <Textarea
                                        id="clinical_notes"
                                        rows={3}
                                        placeholder="Patient presented with fever, dental pain, routine antenatal check-up, etc."
                                        value={data.clinical_notes}
                                        onChange={(e) => setData('clinical_notes', e.target.value)}
                                    />
                                    <InputError message={errors.clinical_notes} />
                                </div>

                                {/* NHIF Error Banner if returned by pre-authorization */}
                                {errors.nhif && (
                                    <div className="rounded-lg border border-red-300 bg-red-50 p-4 text-red-900 dark:border-red-800 dark:bg-red-950/30 dark:text-red-300">
                                        <div className="flex items-start gap-2.5">
                                            <AlertTriangle className="h-5 w-5 shrink-0 text-red-600 dark:text-red-400 mt-0.5" />
                                            <div className="space-y-1">
                                                <p className="font-semibold text-sm">NHIF Pre-authorization Failed</p>
                                                <p className="text-xs text-red-700 dark:text-red-300">{errors.nhif}</p>
                                                <p className="text-xs text-muted-foreground pt-1">
                                                    You may select a different consultation service, assign another practitioner, or switch payment mode to <strong>CASH</strong>.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                )}

                                <Separator />

                                {/* Summary & Live Billing Calculation Card */}
                                <div className="rounded-xl border bg-muted/30 p-4 space-y-3">
                                    <div className="flex items-center justify-between border-b pb-2">
                                        <span className="text-xs font-bold uppercase tracking-wider text-muted-foreground">
                                            Visit Registration Summary
                                        </span>
                                        <Badge
                                            variant={isNhifActive ? 'default' : 'outline'}
                                            className={isNhifActive ? 'bg-emerald-600 text-white' : 'font-semibold'}
                                        >
                                            {isNhifActive ? 'NHIF Electronic Pre-approval' : 'Cash Registration'}
                                        </Badge>
                                    </div>

                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">Patient:</span>
                                            <span className="font-medium text-foreground">
                                                {selectedPatient.first_name} {selectedPatient.last_name}
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">Department:</span>
                                            <span className="font-medium text-foreground">{selectedDepartment.name}</span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">Service:</span>
                                            <span className="font-medium text-foreground">
                                                {isNhifActive
                                                    ? (selectedNhifService?.item_name ?? '—')
                                                    : (selectedCashService?.name ?? 'Standard Consultation')}
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">Practitioner:</span>
                                            <span className="font-medium text-foreground">
                                                {practitionerName || '—'}
                                            </span>
                                        </div>
                                    </div>

                                    <div className="rounded-lg bg-background p-3 border flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mt-2">
                                        <div>
                                            <div className="text-xs text-muted-foreground font-medium">Billing & Fee Estimate:</div>
                                            {isNhifActive ? (
                                                <div className="text-sm font-semibold text-emerald-700 dark:text-emerald-400">
                                                    {nhifPrice !== null
                                                        ? `TZS ${nhifPrice.toLocaleString(undefined, { minimumFractionDigits: 2 })} (100% NHIF Insurance Covered)`
                                                        : 'Electronic Preapproval Session'}
                                                </div>
                                            ) : (
                                                <div className="text-sm font-bold text-amber-700 dark:text-amber-400">
                                                    {cashDisplayPrice !== null
                                                        ? `${currency} ${cashDisplayPrice.toLocaleString(undefined, { minimumFractionDigits: 2 })}`
                                                        : 'Fee configured per hospital rate'}
                                                </div>
                                            )}
                                        </div>

                                        <div className="text-xs text-muted-foreground">
                                            {isNhifActive
                                                ? 'Pre-approval submitted synchronously to NHIF'
                                                : 'Charge captured upfront for cashier payment gate'}
                                        </div>
                                    </div>
                                </div>

                                {/* Submit Button */}
                                <div className="flex flex-col sm:flex-row items-center justify-end gap-3 pt-2">
                                    <Button
                                        type="submit"
                                        size="lg"
                                        disabled={
                                            processing ||
                                            !data.patient_id ||
                                            !data.department_id ||
                                            (isNhifActive && (!data.nhif_item_code || !data.practitioner_id || !selectedPractitioner?.nhif_practitioner_no))
                                        }
                                        className={
                                            isNhifActive
                                                ? 'bg-emerald-700 hover:bg-emerald-800 text-white w-full sm:w-auto'
                                                : 'w-full sm:w-auto'
                                        }
                                    >
                                        {processing && <LoaderCircle className="h-4 w-4 animate-spin mr-2" />}
                                        {isNhifActive
                                            ? 'Submit NHIF Preapproval & Register'
                                            : 'Register Walk-in & Generate Bill'}
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                </form>
            </div>
        </AppLayout>
    );
}
