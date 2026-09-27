import InputError from '@/components/input-error';
import { PatientSelector } from '@/components/patients/patient-selector';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { admissionTypeLabel } from '@/lib/admission-status';
import { type BreadcrumbItem } from '@/types';
import {
    type AdmissionDepartment,
    type AdmissionFormData,
    type AdmissionHospital,
    type AdmissionPractitioner,
    type AdmissionService as AdmissionServiceOption,
    type AdmissionType,
} from '@/types/admission';
import { type PatientSearchResult } from '@/types/patient';
import { Head, useForm } from '@inertiajs/react';
import { ExternalLink, LoaderCircle } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admissions', href: '/admissions' },
    { title: 'New Request', href: '/admissions/create' },
];

interface AdmissionCreateProps {
    hospitals: AdmissionHospital[];
    departments: AdmissionDepartment[];
    practitioners: AdmissionPractitioner[];
    services: AdmissionServiceOption[];
    admissionTypes: AdmissionType[];
}

/**
 * A single-page guided form (patient → hospital/department → billing tier
 * → submit), mirroring reception/walk-in/create.tsx's own precedent for
 * this app's request-style forms.
 */
export default function AdmissionCreate({ hospitals, departments, practitioners, services, admissionTypes }: AdmissionCreateProps) {
    const [selectedPatient, setSelectedPatient] = useState<PatientSearchResult | null>(null);

    const { data, setData, post, processing, errors } = useForm<AdmissionFormData>({
        hospital_id: '',
        patient_id: '',
        department_id: '',
        practitioner_id: '',
        service_id: '',
        admission_type: '',
        ward_type: '',
        room_type: '',
        diagnosis_at_admission: '',
        notes: '',
    });

    const filteredDepartments = data.hospital_id ? departments.filter((d) => String(d.hospital_id) === data.hospital_id) : departments;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admissions.store'));
    };

    const selectedService = services.find((s) => String(s.id) === data.service_id);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New Admission Request" />

            <div className="p-4">
                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>New Admission Request</CardTitle>
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
                                    <Label htmlFor="hospital_id">Hospital *</Label>
                                    <Select
                                        value={data.hospital_id}
                                        onValueChange={(value) => {
                                            setData('hospital_id', value);
                                            setData('department_id', '');
                                        }}
                                    >
                                        <SelectTrigger id="hospital_id">
                                            <SelectValue placeholder="Select a hospital" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {hospitals.map((hospital) => (
                                                <SelectItem key={hospital.id} value={String(hospital.id)}>
                                                    {hospital.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.hospital_id} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="department_id">Department / Ward</Label>
                                    <Select value={data.department_id} onValueChange={(value) => setData('department_id', value)}>
                                        <SelectTrigger id="department_id">
                                            <SelectValue placeholder="Select a department" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {filteredDepartments.map((department) => (
                                                <SelectItem key={department.id} value={String(department.id)}>
                                                    {department.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.department_id} />
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="admission_type">Admission Type</Label>
                                    <Select
                                        value={data.admission_type}
                                        onValueChange={(value) => setData('admission_type', value as AdmissionFormData['admission_type'])}
                                    >
                                        <SelectTrigger id="admission_type">
                                            <SelectValue placeholder="Select a type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {admissionTypes.map((type) => (
                                                <SelectItem key={type} value={type}>
                                                    {admissionTypeLabel[type] ?? type}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.admission_type} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="practitioner_id">Admitting Practitioner</Label>
                                    <Select
                                        value={data.practitioner_id}
                                        onValueChange={(value) => setData('practitioner_id', value === 'none' ? '' : value)}
                                    >
                                        <SelectTrigger id="practitioner_id">
                                            <SelectValue placeholder="Select a practitioner" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">No practitioner selected</SelectItem>
                                            {practitioners.map((p) => (
                                                <SelectItem key={p.id} value={String(p.id)}>
                                                    {p.staff ? `${p.staff.first_name} ${p.staff.last_name}` : p.practitioner_number}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.practitioner_id} />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="service_id">
                                    Billing Tier <span className="text-muted-foreground font-normal">(daily rate — determines the admission charge)</span>
                                </Label>
                                <Select value={data.service_id} onValueChange={(value) => setData('service_id', value === 'none' ? '' : value)}>
                                    <SelectTrigger id="service_id">
                                        <SelectValue placeholder="No billing tier selected" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">No billing tier selected</SelectItem>
                                        {services.map((service) => (
                                            <SelectItem key={service.id} value={String(service.id)}>
                                                {service.code} — {service.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.service_id} />
                                <p className="text-muted-foreground text-xs">
                                    If selected, an admission charge will be captured immediately. Payment (and NHIF pre-authorization, if required)
                                    must be cleared before the request can be confirmed and the patient admitted.
                                </p>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="ward_type">Ward</Label>
                                    <Input
                                        id="ward_type"
                                        value={data.ward_type}
                                        onChange={(e) => setData('ward_type', e.target.value)}
                                        placeholder="e.g. Male Ward"
                                    />
                                    <InputError message={errors.ward_type} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="room_type">Room</Label>
                                    <Input
                                        id="room_type"
                                        value={data.room_type}
                                        onChange={(e) => setData('room_type', e.target.value)}
                                        placeholder="e.g. Room 204"
                                    />
                                    <InputError message={errors.room_type} />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="diagnosis_at_admission">Diagnosis / Reason for Admission</Label>
                                <Textarea
                                    id="diagnosis_at_admission"
                                    value={data.diagnosis_at_admission}
                                    onChange={(e) => setData('diagnosis_at_admission', e.target.value)}
                                    rows={2}
                                />
                                <InputError message={errors.diagnosis_at_admission} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="notes">Notes</Label>
                                <Textarea id="notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} />
                                <InputError message={errors.notes} />
                            </div>

                            {selectedPatient && data.hospital_id && (
                                <div className="bg-muted/50 rounded-md border p-4 text-sm space-y-2">
                                    <p className="font-medium">Request Summary</p>
                                    <div className="grid grid-cols-2 gap-2 text-xs">
                                        <div>
                                            <span className="text-muted-foreground">Patient: </span>
                                            <span className="font-medium text-foreground">
                                                {[selectedPatient.first_name, selectedPatient.middle_name, selectedPatient.last_name]
                                                    .filter(Boolean)
                                                    .join(' ')}{' '}
                                                ({selectedPatient.patient_number})
                                            </span>
                                        </div>
                                        <div>
                                            <span className="text-muted-foreground">Hospital: </span>
                                            <span className="font-medium text-foreground">
                                                {hospitals.find((h) => String(h.id) === data.hospital_id)?.name}
                                            </span>
                                        </div>
                                        {selectedService && (
                                            <div>
                                                <span className="text-muted-foreground">Billing Tier: </span>
                                                <span className="font-bold text-foreground">{selectedService.name}</span>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}

                            <div className="flex justify-end gap-2">
                                <Button type="submit" disabled={processing}>
                                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                    Submit Admission Request
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
