import InputError from '@/components/input-error';
import { PatientSelector } from '@/components/patients/patient-selector';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type ClinicalEncounterFormData, type ClinicalEncounterPractitioner, type ClinicalEncounterReceptionVisit } from '@/types/clinical-encounter';
import { type PatientSearchResult } from '@/types/patient';
import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Clinical Encounters', href: '/clinical-encounters' },
    { title: 'New Encounter', href: '/clinical-encounters/create' },
];

const ENCOUNTER_TYPE_LABELS: Record<string, string> = {
    opd: 'OPD',
    walk_in: 'Walk-in',
    scheduled: 'Scheduled',
    follow_up: 'Follow-up',
};

interface EncounterCreateDepartment {
    id: number;
    name: string;
    code: string;
}

interface ReceptionVisitPrefill extends ClinicalEncounterReceptionVisit {
    department_id?: number;
    patient?: PatientSearchResult;
}

interface ClinicalEncounterCreateProps {
    departments: EncounterCreateDepartment[];
    practitioners: ClinicalEncounterPractitioner[];
    encounterTypes: string[];
    patientId: number | null;
    receptionVisit: ReceptionVisitPrefill | null;
}

/**
 * Opens a new clinical encounter — the point where a patient moves from
 * administrative reception into the clinical service (spec §10). When
 * reached from a ready-for-service reception visit, the patient and
 * reception_visit_id are prefilled and the patient is locked, since the
 * encounter must stay tied to that visit's patient (§11); otherwise staff
 * pick the patient directly (e.g. for a clinical workflow that legitimately
 * bypasses reception).
 */
export default function ClinicalEncounterCreate({
    departments,
    practitioners,
    encounterTypes,
    patientId,
    receptionVisit,
}: ClinicalEncounterCreateProps) {
    const [selectedPatient, setSelectedPatient] = useState<PatientSearchResult | null>(receptionVisit?.patient ?? null);

    const { data, setData, post, processing, errors } = useForm<ClinicalEncounterFormData>({
        patient_id: patientId ? String(patientId) : receptionVisit?.patient ? String(receptionVisit.patient.id) : '',
        department_id: receptionVisit?.department_id ? String(receptionVisit.department_id) : '',
        practitioner_id: '',
        reception_visit_id: receptionVisit ? String(receptionVisit.id) : '',
        appointment_id: '',
        encounter_type: receptionVisit ? 'opd' : '',
    });

    const patientLocked = Boolean(receptionVisit);

    const practitionersForDepartment = useMemo(() => {
        if (!data.department_id) {
            return practitioners;
        }

        return practitioners.filter(
            (practitioner) => !practitioner.staff?.department_id || String(practitioner.staff.department_id) === data.department_id,
        );
    }, [practitioners, data.department_id]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('clinical-encounters.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New Clinical Encounter" />

            <div className="p-4">
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>New Clinical Encounter</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-6">
                            {receptionVisit && (
                                <p className="text-muted-foreground bg-muted/50 rounded-md border p-3 text-sm">
                                    Opening this encounter from reception visit <span className="font-medium">{receptionVisit.visit_number}</span>.
                                </p>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="patient_id">Patient *</Label>
                                <PatientSelector
                                    value={data.patient_id}
                                    selected={selectedPatient}
                                    disabled={patientLocked}
                                    onSelect={(patient) => {
                                        setSelectedPatient(patient);
                                        setData('patient_id', String(patient.id));
                                    }}
                                />
                                <InputError message={errors.patient_id} />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="department_id">Department *</Label>
                                    <Select
                                        value={data.department_id}
                                        onValueChange={(value) => {
                                            setData('department_id', value);
                                            setData('practitioner_id', '');
                                        }}
                                    >
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
                                    <Label htmlFor="encounter_type">Encounter Type *</Label>
                                    <Select
                                        value={data.encounter_type}
                                        onValueChange={(value) => setData('encounter_type', value as ClinicalEncounterFormData['encounter_type'])}
                                    >
                                        <SelectTrigger id="encounter_type">
                                            <SelectValue placeholder="Select a type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {encounterTypes.map((type) => (
                                                <SelectItem key={type} value={type}>
                                                    {ENCOUNTER_TYPE_LABELS[type] ?? type}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.encounter_type} />
                                </div>

                                <div className="grid gap-2 sm:col-span-2">
                                    <Label htmlFor="practitioner_id">Practitioner</Label>
                                    <Select
                                        value={data.practitioner_id}
                                        onValueChange={(value) => setData('practitioner_id', value)}
                                        disabled={!data.department_id}
                                    >
                                        <SelectTrigger id="practitioner_id">
                                            <SelectValue placeholder={data.department_id ? 'Assign later if unknown' : 'Select a department first'} />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {practitionersForDepartment.map((practitioner) => (
                                                <SelectItem key={practitioner.id} value={String(practitioner.id)}>
                                                    {practitioner.staff
                                                        ? `${practitioner.staff.first_name} ${practitioner.staff.last_name}`
                                                        : practitioner.practitioner_number}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.practitioner_id} />
                                    <p className="text-muted-foreground text-sm">
                                        Optional — a clinician can be assigned later when the consultation workflow begins.
                                    </p>
                                </div>
                            </div>

                            <div className="flex justify-end gap-2">
                                <Button type="submit" disabled={processing}>
                                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                    Open Encounter
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
