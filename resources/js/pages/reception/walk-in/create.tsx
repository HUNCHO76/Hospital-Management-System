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
    const abortRef = useRef<AbortController | null>(null);

    const { data, setData, post, processing, errors } = useForm<WalkInFormData>({
        patient_id: '',
        department_id: '',
        visit_type: 'walk_in',
        practitioner_id: '',
    });

    useEffect(() => {
        if (abortRef.current) {
            abortRef.current.abort();
        }

        const practitioner = practitioners.find((p) => String(p.id) === data.practitioner_id);
        if (!data.department_id || !data.practitioner_id || !practitioner?.consultation_service_id) {
            setConsultationPrice(null);
            return;
        }

        const ctrl = new AbortController();
        abortRef.current = ctrl;

        fetch(route('reception.consultation-price') + `?department_id=${data.department_id}&practitioner_id=${data.practitioner_id}`, {
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
    }, [data.department_id, data.practitioner_id]);

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
                                        {practitioners.map((p) => (
                                            <SelectItem key={p.id} value={String(p.id)}>
                                                {p.staff ? `${p.staff.first_name} ${p.staff.last_name}` : p.practitioner_number}
                                                {p.consultation_service_id ? ' ✓' : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.practitioner_id} />
                                {consultationPrice?.price != null ? (
                                    <div className="rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-xs text-amber-700 dark:text-amber-400 space-y-1">
                                        <div className="flex justify-between items-center">
                                            <span className="font-semibold">Consultation Fee:</span>
                                            <span className="text-sm font-bold">
                                                {consultationPrice.currency}{' '}
                                                {consultationPrice.price.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                            </span>
                                        </div>
                                        <div className="flex justify-between items-center text-muted-foreground">
                                            <span>Financial Requirement:</span>
                                            <span className="font-semibold text-amber-700 dark:text-amber-400">PAYMENT REQUIRED</span>
                                        </div>
                                        <p className="pt-1 text-[11px] text-muted-foreground">
                                            Submitting creates a billable consultation charge. Patient must settle payment at the cashier before entering the clinical queue.
                                        </p>
                                    </div>
                                ) : (
                                    <p className="text-muted-foreground text-xs">
                                        If selected, a consultation charge will be captured immediately. Payment must be cleared before the patient is sent to the clinical queue.
                                    </p>
                                )}
                            </div>

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
