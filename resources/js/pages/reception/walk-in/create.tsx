import InputError from '@/components/input-error';
import { PatientSelector } from '@/components/patients/patient-selector';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type AppointmentDepartment } from '@/types/appointment';
import { type PatientSearchResult } from '@/types/patient';
import {
    type NhifConsultationService,
    type NhifInsuranceSummary,
    type ReceptionPractitioner,
    type WalkInFormData,
} from '@/types/reception';
import { Head, useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, ExternalLink, LoaderCircle, ShieldCheck } from 'lucide-react';
import { type FormEventHandler, useEffect, useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reception', href: '/reception' },
    { title: 'Register Walk-in', href: '/reception/walk-in/create' },
];

const VISIT_TYPE_LABELS: Record<string, string> = {
    appointment: 'Appointment',
    walk_in: 'Walk-in',
    follow_up: 'Follow-up',
    other: 'Other',
};

const SPECIALTY_OPTIONS = [
    { value: '', label: 'General Consultation' },
    { value: 'dental', label: 'Dental' },
    { value: 'eye', label: 'Eye / Ophthalmology' },
];

interface WalkInCreateProps {
    departments: AppointmentDepartment[];
    visitTypes: string[];
    practitioners: ReceptionPractitioner[];
}

export default function WalkInCreate({ departments, visitTypes, practitioners }: WalkInCreateProps) {
    const [selectedPatient, setSelectedPatient] = useState<PatientSearchResult | null>(null);
    const [consultationPrice, setConsultationPrice] = useState<{ price: number | null; currency: string; nhif_unit_price: number | null } | null>(null);
    const [specialtyFilter, setSpecialtyFilter] = useState<'' | 'dental' | 'eye'>('');
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
    const abortRef = useRef<AbortController | null>(null);
    const nhifAbortRef = useRef<AbortController | null>(null);

    const { data, setData, post, processing, errors } = useForm<WalkInFormData>({
        patient_id: '',
        department_id: '',
        visit_type: 'walk_in',
        practitioner_id: '',
        nhif_item_code: '',
        clinical_notes: '',
        practitioner_remarks: '',
    });

    const isNhifPatient = nhifInsurance !== null;
    const hasNhifServices = nhifConsultationServices.length > 0;

    const selectedService = nhifConsultationServices.find((s) => String(s.item_code) === data.nhif_item_code) ?? null;
    const selectedPractitioner = practitioners.find((p) => String(p.id) === data.practitioner_id) ?? null;

    const nhifEligiblePractitioners = useMemo(() => {
        if (!selectedService) return [];
        return practitioners.filter((p) => {
            if (!p.nhif_practitioner_no) return false;
            const matchesServiceId = selectedService.service_id && p.consultation_service_id === selectedService.service_id;
            const matchesCode = p.consultation_service?.code === selectedService.item_code;
            return Boolean(matchesServiceId || matchesCode);
        });
    }, [practitioners, selectedService]);

    useEffect(() => {
        if (!isNhifPatient) return;

        if (!data.nhif_item_code) {
            if (data.practitioner_id) {
                setData('practitioner_id', '');
            }
            return;
        }

        if (nhifEligiblePractitioners.length === 1) {
            const singlePractitionerId = String(nhifEligiblePractitioners[0].id);
            if (data.practitioner_id !== singlePractitionerId) {
                setData('practitioner_id', singlePractitionerId);
            }
        } else if (
            data.practitioner_id &&
            !nhifEligiblePractitioners.some((p) => String(p.id) === data.practitioner_id)
        ) {
            setData('practitioner_id', '');
        }
    }, [data.nhif_item_code, isNhifPatient, nhifEligiblePractitioners, data.practitioner_id, setData]);

    const filteredPractitioners = practitioners.filter((p) => {
        if (specialtyFilter === 'dental') return p.practitioner_type === 'dentist';
        if (specialtyFilter === 'eye') return p.practitioner_type === 'ophthalmologist';
        return true;
    });

    const handleSpecialtyChange = (value: string) => {
        const next = (value === 'all' ? '' : value) as '' | 'dental' | 'eye';
        setSpecialtyFilter(next);
        if (data.practitioner_id) {
            const current = practitioners.find((p) => String(p.id) === data.practitioner_id);
            const stillVisible =
                next === '' ||
                (next === 'dental' && current?.practitioner_type === 'dentist') ||
                (next === 'eye' && current?.practitioner_type === 'ophthalmologist');
            if (!stillVisible) setData('practitioner_id', '');
        }
    };

    useEffect(() => {
        if (nhifAbortRef.current) nhifAbortRef.current.abort();

        if (!data.patient_id) {
            setNhifConsultationServices([]);
            setNhifInsurance(null);
            setNhifAuthExpiredInfo(null);
            setData('nhif_item_code', '');
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
                setNhifConsultationServices(Array.isArray(json.services) ? json.services : []);
                setNhifInsurance(json.insurance ?? null);
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
                    console.error('NHIF services fetch failed:', err);
                    setNhifConsultationServices([]);
                    setNhifInsurance(null);
                    setNhifAuthExpiredInfo(null);
                    setNhifServicesLoading(false);
                    setNhifFetchError('Failed to check NHIF coverage. Please reload the page.');
                }
            });
    }, [data.patient_id]);

    useEffect(() => {
        if (abortRef.current) abortRef.current.abort();

        const shouldFetch =
            data.department_id &&
            (data.practitioner_id || specialtyFilter || (isNhifPatient && data.nhif_item_code));

        if (!shouldFetch) {
            setConsultationPrice(null);
            return;
        }

        const ctrl = new AbortController();
        abortRef.current = ctrl;

        const params = new URLSearchParams({ department_id: data.department_id });
        if (data.practitioner_id) params.set('practitioner_id', data.practitioner_id);
        if (isNhifPatient && data.nhif_item_code) {
            params.set('nhif_item_code', data.nhif_item_code);
            params.set('patient_id', data.patient_id);
        } else if (specialtyFilter) {
            params.set('specialty_type', specialtyFilter);
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
    }, [data.department_id, data.practitioner_id, data.nhif_item_code, specialtyFilter, isNhifPatient]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('reception.walk-in.store'));
    };

    const practitionerName = selectedPractitioner?.staff
        ? `${selectedPractitioner.staff.first_name} ${selectedPractitioner.staff.last_name}`
        : (selectedPractitioner?.practitioner_number ?? '');

    const nhifPrice = consultationPrice?.nhif_unit_price
        ?? (selectedService?.unit_price ? parseFloat(selectedService.unit_price) : null)
        ?? consultationPrice?.price
        ?? null;

    const currency = consultationPrice?.currency ?? 'TZS';

    /* ------------------------------------------------------------------ */

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Register Walk-in" />

            <div className="p-4">
                <form onSubmit={submit} className="mx-auto max-w-2xl space-y-6">

                    {/* ── PATIENT SEARCH ─────────────────────────────────── */}
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">Patient</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <PatientSelector
                                value={data.patient_id}
                                selected={selectedPatient}
                                onSelect={(patient) => {
                                    setSelectedPatient(patient);
                                    setData('patient_id', String(patient.id));
                                }}
                            />
                            <InputError message={errors.patient_id} />
                            <p className="text-muted-foreground text-sm">
                                Can't find the patient?{' '}
                                <a
                                    href={route('patients.create')}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-primary inline-flex items-center gap-1 underline-offset-4 hover:underline"
                                >
                                    Register New Patient <ExternalLink className="h-3.5 w-3.5" />
                                </a>{' '}
                                then search again here.
                            </p>

                            {/* Loading indicator */}
                            {nhifServicesLoading && (
                                <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                                    <LoaderCircle className="h-3.5 w-3.5 animate-spin" /> Checking NHIF coverage…
                                </p>
                            )}

                            {/* Fetch error */}
                            {nhifFetchError && !nhifServicesLoading && (
                                <div className="flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
                                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                    <span>{nhifFetchError}</span>
                                </div>
                            )}

                            {/* NHIF Auth Expired Warning */}
                            {!nhifServicesLoading && selectedPatient && nhifAuthExpiredInfo?.auth_expired && (
                                <div className="rounded-md border border-amber-300 bg-amber-50 p-3.5 text-amber-900 dark:border-amber-700/50 dark:bg-amber-950/40 dark:text-amber-200">
                                    <div className="flex items-start gap-2.5">
                                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                                        <div className="flex-1 space-y-1 text-sm">
                                            <p className="font-semibold">NHIF 24-Hour Authorization Expired</p>
                                            <p className="text-xs text-muted-foreground">
                                                The patient's authorization number ({nhifAuthExpiredInfo.previous_auth_no}) was verified on{' '}
                                                {nhifAuthExpiredInfo.verified_at ?? 'more than 24h ago'} and has expired. NHIF authorizations are strictly valid for 24 hours.
                                            </p>
                                            {nhifAuthExpiredInfo.patient_insurance_id && (
                                                <div className="pt-1.5">
                                                    <a
                                                        href={`/patients/${data.patient_id}/insurance/${nhifAuthExpiredInfo.patient_insurance_id}`}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="inline-flex items-center gap-1 text-xs font-semibold text-primary underline hover:text-primary/80"
                                                    >
                                                        Re-verify card on insurance page
                                                        <ExternalLink className="h-3 w-3" />
                                                    </a>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            )}

                            {/* NHIF Verified panel */}
                            {!nhifServicesLoading && isNhifPatient && selectedPatient && (
                                <div className="rounded-md border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-800 dark:bg-emerald-950/30">
                                    <div className="mb-2 flex items-center justify-between">
                                        <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">
                                            <ShieldCheck className="h-3.5 w-3.5" /> NHIF Verified
                                        </p>
                                        {nhifInsurance.hours_remaining !== undefined && nhifInsurance.hours_remaining !== null && (
                                            <Badge variant="outline" className="border-emerald-300 text-[11px] text-emerald-800 dark:text-emerald-300">
                                                ~{nhifInsurance.hours_remaining}h validity remaining
                                            </Badge>
                                        )}
                                    </div>
                                    <dl className="grid grid-cols-2 gap-x-6 gap-y-1.5 text-sm">
                                        <div className="flex gap-2">
                                            <dt className="shrink-0 text-muted-foreground">Scheme</dt>
                                            <dd className="font-medium">
                                                {nhifInsurance.scheme_name ?? `Scheme #${nhifInsurance.scheme_id}`}
                                            </dd>
                                        </div>
                                        {nhifInsurance.card_number && (
                                            <div className="flex gap-2">
                                                <dt className="shrink-0 text-muted-foreground">Card No</dt>
                                                <dd className="font-mono text-xs font-medium">{nhifInsurance.card_number}</dd>
                                            </div>
                                        )}
                                        <div className="flex gap-2">
                                            <dt className="shrink-0 text-muted-foreground">File No</dt>
                                            <dd className="font-mono text-xs font-medium">
                                                {nhifInsurance.nhif_pf_number ?? selectedPatient.patient_number}
                                            </dd>
                                        </div>
                                        <div className="flex gap-2">
                                            <dt className="shrink-0 text-muted-foreground">Authorization</dt>
                                            <dd className="font-mono text-xs font-medium">{nhifInsurance.nhif_authorization_no}</dd>
                                        </div>
                                    </dl>
                                </div>
                            )}

                            {/* Cash patient indicator */}
                            {!nhifServicesLoading && selectedPatient && !isNhifPatient && !nhifAuthExpiredInfo?.auth_expired && (
                                <div className="rounded-md border bg-amber-50 px-3 py-2 text-sm text-amber-700 dark:bg-amber-950/30 dark:text-amber-400">
                                    No active verified NHIF — <strong>CASH</strong> billing will apply.
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* ══════════════════════════════════════════════════════
                        NHIF MODE
                    ══════════════════════════════════════════════════════ */}
                    {selectedPatient && isNhifPatient && (
                        <>
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-base">Consultation Details</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-5">

                                    {/* Department */}
                                    <div className="grid gap-2">
                                        <Label htmlFor="nhif_department_id">Department *</Label>
                                        <Select value={data.department_id} onValueChange={(v) => setData('department_id', v)}>
                                            <SelectTrigger id="nhif_department_id">
                                                <SelectValue placeholder="Select department" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {departments.map((d) => (
                                                    <SelectItem key={d.id} value={String(d.id)}>
                                                        {d.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={errors.department_id} />
                                    </div>

                                    <Separator />

                                    {/* NHIF Consultation Service */}
                                    <div className="grid gap-2">
                                        <Label htmlFor="nhif_item_code">
                                            Consultation Service *{' '}
                                            <Badge variant="secondary" className="ml-1 bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                                                NHIF
                                            </Badge>
                                        </Label>
                                        {hasNhifServices ? (
                                            <>
                                                <Select
                                                    value={data.nhif_item_code || '__none__'}
                                                    onValueChange={(v) => setData('nhif_item_code', v === '__none__' ? '' : v)}
                                                >
                                                    <SelectTrigger id="nhif_item_code">
                                                        <SelectValue placeholder="Select consultation service…" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="__none__">Select service…</SelectItem>
                                                        {nhifConsultationServices.map((svc) => (
                                                            <SelectItem key={svc.id} value={String(svc.item_code)}>
                                                                {svc.item_name}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                                <InputError message={errors.nhif_item_code} />

                                                {/* Item Code + NHIF Price */}
                                                {selectedService && (
                                                    <div className="flex gap-6 rounded-md bg-muted/40 px-3 py-2 text-sm">
                                                        <div className="flex gap-2">
                                                            <span className="text-muted-foreground">Item Code</span>
                                                            <span className="font-mono font-medium">{selectedService.item_code}</span>
                                                        </div>
                                                        {nhifPrice !== null && (
                                                            <div className="flex gap-2">
                                                                <span className="text-muted-foreground">NHIF Price</span>
                                                                <span className="font-semibold text-emerald-700 dark:text-emerald-400">
                                                                    {currency}{' '}
                                                                    {nhifPrice.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                                </span>
                                                            </div>
                                                        )}
                                                    </div>
                                                )}
                                            </>
                                        ) : (
                                            <div className="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-400">
                                                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                                <span>
                                                    No consultation services found for this scheme. Please sync the NHIF catalog
                                                    or contact administration.
                                                </span>
                                            </div>
                                        )}
                                    </div>

                                    {/* Practitioner */}
                                    <div className="grid gap-2">
                                        <Label htmlFor="nhif_practitioner_id">Practitioner *</Label>
                                        <Select
                                            value={data.practitioner_id || '__none__'}
                                            onValueChange={(v) => setData('practitioner_id', v === '__none__' ? '' : v)}
                                            disabled={!data.nhif_item_code || nhifEligiblePractitioners.length === 0}
                                        >
                                            <SelectTrigger id="nhif_practitioner_id">
                                                <SelectValue
                                                    placeholder={
                                                        !data.nhif_item_code
                                                            ? 'Select consultation service first'
                                                            : nhifEligiblePractitioners.length === 0
                                                            ? 'No practitioner assigned to this service'
                                                            : 'Select practitioner…'
                                                    }
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">Select practitioner…</SelectItem>
                                                {nhifEligiblePractitioners.map((p) => (
                                                    <SelectItem key={p.id} value={String(p.id)}>
                                                        {p.staff
                                                            ? `${p.staff.first_name} ${p.staff.last_name}`
                                                            : p.practitioner_number}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={errors.practitioner_id} />

                                        {/* Notice when consultation service is selected but no practitioner is assigned */}
                                        {data.nhif_item_code && nhifEligiblePractitioners.length === 0 && (
                                            <div className="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
                                                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                                                <span>
                                                    No active practitioner with an NHIF practitioner number is assigned to this consultation service.
                                                    Please assign this service on the practitioner profile or choose a different consultation service.
                                                </span>
                                            </div>
                                        )}

                                        {/* NHIF Practitioner No — auto-resolved */}
                                        {selectedPractitioner && (
                                            <div className="flex items-center gap-3 rounded-md bg-muted/40 px-3 py-2 text-sm">
                                                <span className="shrink-0 text-muted-foreground">NHIF Practitioner No</span>
                                                {selectedPractitioner.nhif_practitioner_no ? (
                                                    <span className="flex items-center gap-1.5 font-mono font-medium text-emerald-700 dark:text-emerald-400">
                                                        {selectedPractitioner.nhif_practitioner_no}
                                                        <CheckCircle2 className="h-3.5 w-3.5" />
                                                        <span className="font-sans text-xs font-normal text-muted-foreground">
                                                            Automatically resolved
                                                        </span>
                                                    </span>
                                                ) : (
                                                    <span className="font-medium text-destructive">
                                                        Not registered — required for NHIF billing
                                                    </span>
                                                )}
                                            </div>
                                        )}
                                    </div>

                                    {/* Clinical Notes */}
                                    <div className="grid gap-2">
                                        <Label htmlFor="clinical_notes">Clinical Notes</Label>
                                        <Textarea
                                            id="clinical_notes"
                                            rows={3}
                                            placeholder="Patient presented for…"
                                            value={data.clinical_notes}
                                            onChange={(e) => setData('clinical_notes', e.target.value)}
                                        />
                                        <InputError message={errors.clinical_notes} />
                                    </div>

                                    {/* Practitioner Remarks */}
                                    <div className="grid gap-2">
                                        <Label htmlFor="practitioner_remarks">Practitioner Remarks</Label>
                                        <Textarea
                                            id="practitioner_remarks"
                                            rows={2}
                                            placeholder=""
                                            value={data.practitioner_remarks}
                                            onChange={(e) => setData('practitioner_remarks', e.target.value)}
                                        />
                                        <InputError message={errors.practitioner_remarks} />
                                    </div>
                                </CardContent>
                            </Card>

                            {/* NHIF Preapproval summary card */}
                            <Card className={errors.nhif ? 'border-red-400 dark:border-red-700' : 'border-emerald-200 dark:border-emerald-800'}>
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base text-emerald-700 dark:text-emerald-400">
                                        <ShieldCheck className="h-4 w-4" /> NHIF Preapproval
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {/* NHIF rejection / pending / error banner */}
                                    {errors.nhif && (
                                        <div className="rounded-md border border-red-300 bg-red-50 px-4 py-3 dark:border-red-800 dark:bg-red-950/30">
                                            <p className="mb-1 flex items-center gap-2 text-sm font-semibold text-red-700 dark:text-red-400">
                                                <AlertTriangle className="h-4 w-4 shrink-0" />
                                                Registration Not Completed
                                            </p>
                                            <p className="text-sm text-red-600 dark:text-red-300">{errors.nhif}</p>
                                            <p className="mt-2 text-xs text-red-500 dark:text-red-400">
                                                The walk-in was not registered. You may change the service or practitioner and retry, or register the patient as CASH instead.
                                            </p>
                                        </div>
                                    )}

                                    {selectedService && selectedPractitioner ? (
                                        <>
                                            <dl className="space-y-1.5 text-sm">
                                                <div className="flex justify-between gap-4">
                                                    <dt className="text-muted-foreground">Service</dt>
                                                    <dd className="text-right font-medium">{selectedService.item_name}</dd>
                                                </div>
                                                {nhifPrice !== null && (
                                                    <div className="flex justify-between gap-4">
                                                        <dt className="text-muted-foreground">Price</dt>
                                                        <dd className="font-semibold text-emerald-700 dark:text-emerald-400">
                                                            {currency}{' '}
                                                            {nhifPrice.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                        </dd>
                                                    </div>
                                                )}
                                                <div className="flex justify-between gap-4">
                                                    <dt className="text-muted-foreground">Practitioner</dt>
                                                    <dd className="font-medium">{practitionerName}</dd>
                                                </div>
                                            </dl>

                                            {!selectedPractitioner.nhif_practitioner_no && (
                                                <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
                                                    Cannot submit — practitioner has no NHIF practitioner number.
                                                </p>
                                            )}

                                            <div className="flex flex-wrap justify-end gap-2 pt-1">
                                                <Button
                                                    type="submit"
                                                    disabled={processing || !selectedPractitioner.nhif_practitioner_no}
                                                    className="bg-emerald-700 hover:bg-emerald-800 dark:bg-emerald-700 dark:hover:bg-emerald-600"
                                                >
                                                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                                    {errors.nhif ? 'Retry NHIF Preapproval' : 'Request NHIF Preapproval'}
                                                </Button>
                                            </div>
                                        </>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            Select a consultation service and practitioner above to review and submit.
                                        </p>
                                    )}

                                    <p className="border-t pt-2 text-xs text-muted-foreground">
                                        NHIF preapproval is required before the walk-in can be registered. If NHIF rejects the service,
                                        you may change the practitioner or service and retry, or register the patient as CASH instead.
                                    </p>
                                </CardContent>
                            </Card>
                        </>
                    )}

                    {/* ══════════════════════════════════════════════════════
                        CASH MODE
                    ══════════════════════════════════════════════════════ */}
                    {selectedPatient && !isNhifPatient && !nhifServicesLoading && (
                        <>
                            <Card>
                                <CardContent className="space-y-5 pt-6">

                                    {/* Payment Type — static display */}
                                    <div className="grid gap-2">
                                        <Label>Payment Type</Label>
                                        <div className="flex h-9 items-center rounded-md border bg-muted/40 px-3 text-sm font-medium text-amber-700 dark:text-amber-400">
                                            CASH
                                        </div>
                                    </div>

                                    {/* Service (specialty) */}
                                    <div className="grid gap-2">
                                        <Label htmlFor="specialty_filter">Service</Label>
                                        <Select
                                            value={specialtyFilter === '' ? 'all' : specialtyFilter}
                                            onValueChange={handleSpecialtyChange}
                                        >
                                            <SelectTrigger id="specialty_filter">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {SPECIALTY_OPTIONS.map((opt) => (
                                                    <SelectItem key={opt.value} value={opt.value === '' ? 'all' : opt.value}>
                                                        {opt.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    {/* Practitioner */}
                                    <div className="grid gap-2">
                                        <Label htmlFor="cash_practitioner_id">
                                            Practitioner{' '}
                                            <span className="text-xs font-normal text-muted-foreground">(optional — enables upfront billing)</span>
                                        </Label>
                                        <Select
                                            value={data.practitioner_id || '__none__'}
                                            onValueChange={(v) => setData('practitioner_id', v === '__none__' ? '' : v)}
                                        >
                                            <SelectTrigger id="cash_practitioner_id">
                                                <SelectValue placeholder="No practitioner selected" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">No practitioner selected</SelectItem>
                                                {filteredPractitioners.map((p) => (
                                                    <SelectItem key={p.id} value={String(p.id)}>
                                                        {p.staff
                                                            ? `${p.staff.first_name} ${p.staff.last_name}`
                                                            : p.practitioner_number}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={errors.practitioner_id} />
                                    </div>

                                    {/* Department */}
                                    <div className="grid gap-2">
                                        <Label htmlFor="cash_department_id">Department *</Label>
                                        <Select value={data.department_id} onValueChange={(v) => setData('department_id', v)}>
                                            <SelectTrigger id="cash_department_id">
                                                <SelectValue placeholder="Select department" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {departments.map((d) => (
                                                    <SelectItem key={d.id} value={String(d.id)}>
                                                        {d.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={errors.department_id} />
                                    </div>

                                    {/* Visit Type */}
                                    <div className="grid gap-2">
                                        <Label htmlFor="visit_type">Visit Type *</Label>
                                        <Select
                                            value={data.visit_type}
                                            onValueChange={(v) => setData('visit_type', v as WalkInFormData['visit_type'])}
                                        >
                                            <SelectTrigger id="visit_type">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {visitTypes.map((type) => (
                                                    <SelectItem key={type} value={type}>
                                                        {VISIT_TYPE_LABELS[type] ?? type}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={errors.visit_type} />
                                    </div>

                                    {/* Notes */}
                                    <div className="grid gap-2">
                                        <Label htmlFor="cash_notes">Notes</Label>
                                        <Textarea
                                            id="cash_notes"
                                            rows={3}
                                            placeholder="Notes…"
                                            value={data.clinical_notes}
                                            onChange={(e) => setData('clinical_notes', e.target.value)}
                                        />
                                        <InputError message={errors.clinical_notes} />
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Cash billing preview */}
                            {consultationPrice?.price != null && data.practitioner_id && (
                                <Card className="border-amber-200 dark:border-amber-800">
                                    <CardHeader className="pb-3">
                                        <CardTitle className="text-base text-amber-700 dark:text-amber-400">Consultation Charge</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <dl className="space-y-1.5 text-sm">
                                            <div className="flex justify-between gap-4">
                                                <dt className="text-muted-foreground">Practitioner</dt>
                                                <dd className="font-medium">{practitionerName}</dd>
                                            </div>
                                            <div className="flex justify-between gap-4">
                                                <dt className="text-muted-foreground">Charge</dt>
                                                <dd className="font-bold">
                                                    {currency}{' '}
                                                    {consultationPrice.price.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                </dd>
                                            </div>
                                            <div className="flex justify-between gap-4">
                                                <dt className="text-muted-foreground">Payment</dt>
                                                <dd className="font-semibold text-amber-700 dark:text-amber-400">CASH</dd>
                                            </div>
                                        </dl>
                                        <p className="mt-3 border-t pt-2 text-xs text-muted-foreground">
                                            Patient must settle this charge at the cashier before entering the clinical queue.
                                        </p>
                                    </CardContent>
                                </Card>
                            )}

                            {/* Submit */}
                            <div className="flex justify-end">
                                <Button type="submit" disabled={processing}>
                                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                    Register Walk-in
                                </Button>
                            </div>
                        </>
                    )}
                </form>
            </div>
        </AppLayout>
    );
}
