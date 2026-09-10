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
import { type WalkInFormData } from '@/types/reception';
import { Head, useForm } from '@inertiajs/react';
import { ExternalLink, LoaderCircle } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

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
export default function WalkInCreate({ departments, visitTypes }: WalkInCreateProps) {
    const [selectedPatient, setSelectedPatient] = useState<PatientSearchResult | null>(null);

    const { data, setData, post, processing, errors } = useForm<WalkInFormData>({
        patient_id: '',
        department_id: '',
        visit_type: 'walk_in',
    });

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

                            {selectedPatient && data.department_id && (
                                <div className="bg-muted/50 rounded-md border p-4 text-sm">
                                    <p className="font-medium">Review</p>
                                    <p className="text-muted-foreground mt-1">
                                        Registering{' '}
                                        <span className="text-foreground font-medium">
                                            {[selectedPatient.first_name, selectedPatient.middle_name, selectedPatient.last_name]
                                                .filter(Boolean)
                                                .join(' ')}
                                        </span>{' '}
                                        ({selectedPatient.patient_number}) as a{' '}
                                        <span className="text-foreground font-medium">{VISIT_TYPE_LABELS[data.visit_type] ?? data.visit_type}</span>{' '}
                                        visit to{' '}
                                        <span className="text-foreground font-medium">
                                            {departments.find((d) => String(d.id) === data.department_id)?.name}
                                        </span>
                                        .
                                    </p>
                                </div>
                            )}

                            <div className="flex justify-end gap-2">
                                <Button type="submit" disabled={processing}>
                                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                    Create Visit
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
