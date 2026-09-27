import InputError from '@/components/input-error';
import { PatientSelector } from '@/components/patients/patient-selector';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type AppointmentDepartment } from '@/types/appointment';
import { type PatientSearchResult } from '@/types/patient';
import { type ReceptionPractitioner, type WalkInFormData } from '@/types/reception';
import { Head, useForm } from '@inertiajs/react';
import { ExternalLink, LoaderCircle } from 'lucide-react';
import { type FormEventHandler, useEffect, useRef, useState } from 'react';

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

const SPECIALTY_LABELS: Record<string, string> = {
    '': 'All / General',
    dental: 'Dental',
    eye: 'Eye / Ophthalmology',
};

interface WalkInCreateProps {
    departments: AppointmentDepartment[];
    visitTypes: string[];
    practitioners: ReceptionPractitioner[];
}

/**
 * A single-page guided form (patient → department/type → submit) rather
 * than a multi-route wizard — this app's existing forms (AppointmentForm,
 * PatientInsuranceForm) are all single-page/single-submit. When the patient
 * search finds nothing, "Register New Patient" opens Step 14's existing
 * form in a new tab rather than modifying its redirect behavior — the
 * receptionist keeps this walk-in in progress and re-searches once the new
 * patient exists.
 */
export default function WalkInCreate({ departments, visitTypes, practitioners }: WalkInCreateProps) {
    const [selectedPatient, setSelectedPatient] = useState<PatientSearchResult | null>(null);
    const [consultationPrice, setConsultationPrice] = useState<{ price: number | null; currency: string } | null>(null);
    const [specialtyFilter, setSpecialtyFilter] = useState<'' | 'dental' | 'eye'>('');
    const abortRef = useRef<AbortController | null>(null);

    const { data, setData, post, processing, errors } = useForm<WalkInFormData>({
        patient_id: '',
        department_id: '',
        visit_type: 'walk_in',
        practitioner_id: '',
    });

    const filteredPractitioners = practitioners.filter((p) => {
        if (specialtyFilter === 'dental') return p.practitioner_type === 'dentist';
        if (specialtyFilter === 'eye') return p.practitioner_type === 'ophthalmologist';
        return true;
    });

    const handleSpecialtyChange = (value: string) => {
        const next = (value === 'all' ? '' : value) as '' | 'dental' | 'eye';
        setSpecialtyFilter(next);
        // Clear practitioner if it no longer fits the new specialty filter.
        if (data.practitioner_id) {
            const current = practitioners.find((p) => String(p.id) === data.practitioner_id);
            const stillVisible =
                next === '' ||
                (next === 'dental' && current?.practitioner_type === 'dentist') ||
                (next === 'eye' && current?.practitioner_type === 'ophthalmologist');
            if (!stillVisible) {
                setData('practitioner_id', '');
            }
        }
    };

    useEffect(() => {
        if (abortRef.current) {
            abortRef.current.abort();
        }

        const practitioner = practitioners.find((p) => String(p.id) === data.practitioner_id);
        const hasPractitionerPrice = data.practitioner_id && practitioner?.consultation_service_id;
        const shouldFetch = data.department_id && (hasPractitionerPrice || specialtyFilter);

        if (!shouldFetch) {
            setConsultationPrice(null);
            return;
        }

        const ctrl = new AbortController();
        abortRef.current = ctrl;

        const params = new URLSearchParams({ department_id: data.department_id });
        if (data.practitioner_id) params.set('practitioner_id', data.practitioner_id);
        if (specialtyFilter) params.set('specialty_type', specialtyFilter);

        fetch(route('reception.consultation-price') + '?' + params.toString(), {
            signal: ctrl.signal,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((r) => r.json())
            .then((json: { price: number | null; currency?: string }) => {
                if (!ctrl.signal.aborted) {
                    setConsultationPrice({ price: json.price, currency: json.currency ?? 'TZS' });
                }
            })
            .catch(() => {
                if (!ctrl.signal.aborted) {
                    setConsultationPrice(null);
                }
            });
    }, [data.department_id, data.practitioner_id, specialtyFilter]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('reception.walk-in.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Register Walk-in" />

            <div className="p-4">
                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Register Walk-in</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-6">
                            <div className="grid gap-2">
                                <Label htmlFor="patient_id">Patient *</Label>
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
                                        Register New Patient
                                        <ExternalLink className="h-3.5 w-3.5" />
                                    </a>{' '}
                                    in a new tab, then search again here once they're registered.
                                </p>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="department_id">Department *</Label>
                                    <Select value={data.department_id} onValueChange={(value) => setData('department_id', value)}>
                                        <SelectTrigger id="department_id">
                                            <SelectValue placeholder="Select a department" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {departments.map((department) => (
                                                <SelectItem key={department.id} value={String(department.id)}>
                                                    {department.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.department_id} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="visit_type">Visit Type *</Label>
                                    <Select
                                        value={data.visit_type}
                                        onValueChange={(value) => setData('visit_type', value as WalkInFormData['visit_type'])}
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
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="specialty_filter">
                                        Consultation Specialty{' '}
                                        <span className="text-muted-foreground font-normal">(optional — filters providers)</span>
                                    </Label>
                                    <Select value={specialtyFilter === '' ? 'all' : specialtyFilter} onValueChange={handleSpecialtyChange}>
                                        <SelectTrigger id="specialty_filter">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(SPECIALTY_LABELS).map(([value, label]) => (
                                                <SelectItem key={value} value={value === '' ? 'all' : value}>
                                                    {label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="practitioner_id">
                                    Assigned Provider{' '}
                                    <span className="text-muted-foreground font-normal">(optional — enables upfront billing)</span>
                                </Label>
                                <Select
                                    value={data.practitioner_id}
                                    onValueChange={(value) => setData('practitioner_id', value === 'none' ? '' : value)}
                                >
                                    <SelectTrigger id="practitioner_id">
                                        <SelectValue placeholder="No provider selected (triage/general queue)" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">No provider selected</SelectItem>
                                        {filteredPractitioners.map((p) => (
                                            <SelectItem key={p.id} value={String(p.id)}>
                                                {p.staff ? `${p.staff.first_name} ${p.staff.last_name}` : p.practitioner_number}
                                                {p.consultation_service_id ? ' ✓' : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.practitioner_id} />
                                <p className="text-muted-foreground text-xs">
                                    If selected, a consultation charge will be captured immediately. The charge must be cleared before the patient is sent to the clinical queue.
                                </p>
                            </div>

                            {consultationPrice?.price != null && data.practitioner_id && (
                                <div className="rounded-md border-2 border-primary/20 bg-primary/5 p-4 space-y-3">
                                    <p className="text-xs font-semibold uppercase tracking-wide text-primary">Registration / Consultation Charges</p>
                                    <dl className="space-y-1.5 text-sm">
                                        {(() => {
                                            const p = practitioners.find((x) => String(x.id) === data.practitioner_id);
                                            return p ? (
                                                <div className="flex justify-between gap-4">
                                                    <dt className="text-muted-foreground">Practitioner</dt>
                                                    <dd className="font-medium">{p.staff ? `${p.staff.first_name} ${p.staff.last_name}` : p.practitioner_number}</dd>
                                                </div>
                                            ) : null;
                                        })()}
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-muted-foreground">Service</dt>
                                            <dd className="font-medium">Consultation</dd>
                                        </div>
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-muted-foreground">Charge</dt>
                                            <dd className="font-bold">
                                                {consultationPrice.currency}{' '}
                                                {consultationPrice.price.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                            </dd>
                                        </div>
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-muted-foreground">Payment Type</dt>
                                            <dd>
                                                {selectedPatient?.has_active_nhif ? (
                                                    <span className="font-semibold text-green-700 dark:text-green-400">NHIF</span>
                                                ) : (
                                                    <span className="font-semibold text-amber-700 dark:text-amber-400">CASH</span>
                                                )}
                                            </dd>
                                        </div>
                                    </dl>
                                    <p className="text-[11px] text-muted-foreground border-t pt-2">
                                        {selectedPatient?.has_active_nhif
                                            ? 'NHIF will be billed for this consultation. If NHIF authorizes the service, the patient proceeds directly to the clinical queue.'
                                            : 'Patient must settle this charge at the cashier before entering the clinical queue.'}
                                    </p>
                                </div>
                            )}

                            {selectedPatient && data.department_id && (
                                <div className="bg-muted/50 rounded-md border p-4 text-sm space-y-2">
                                    <p className="font-medium">Request Summary</p>
                                    <div className="grid grid-cols-2 gap-2 text-xs">
                                        <div>
                                            <span className="text-muted-foreground">Patient: </span>
                                            <span className="font-medium text-foreground">
                                                {[selectedPatient.first_name, selectedPatient.middle_name, selectedPatient.last_name].filter(Boolean).join(' ')} ({selectedPatient.patient_number})
                                            </span>
                                        </div>
                                        <div>
                                            <span className="text-muted-foreground">Department: </span>
                                            <span className="font-medium text-foreground">
                                                {departments.find((d) => String(d.id) === data.department_id)?.name}
                                            </span>
                                        </div>
                                        {data.practitioner_id && (
                                            <div>
                                                <span className="text-muted-foreground">Specialist: </span>
                                                <span className="font-medium text-foreground">
                                                    {(() => {
                                                        const p = practitioners.find((x) => String(x.id) === data.practitioner_id);
                                                        return p?.staff ? `${p.staff.first_name} ${p.staff.last_name}` : p?.practitioner_number ?? '';
                                                    })()}
                                                </span>
                                            </div>
                                        )}
                                        {consultationPrice?.price != null && (
                                            <div>
                                                <span className="text-muted-foreground">Price: </span>
                                                <span className="font-bold text-foreground">
                                                    {consultationPrice.currency} {consultationPrice.price.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                </span>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}

                            <div className="flex justify-end gap-2">
                                <Button type="submit" disabled={processing}>
                                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                    Request Consultation
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
