import InputError from '@/components/input-error';
import { PatientSelector } from '@/components/patients/patient-selector';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { type AppointmentBookingFormData, type AppointmentDepartment, type AppointmentPractitioner } from '@/types/appointment';
import { type PatientSearchResult } from '@/types/patient';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, useMemo, useState } from 'react';

const APPOINTMENT_TYPE_LABELS: Record<string, string> = {
    initial_consultation: 'Initial Consultation',
    follow_up: 'Follow-up',
    review: 'Review',
    procedure: 'Procedure',
    specialist_consultation: 'Specialist Consultation',
    other: 'Other',
};

interface AppointmentFormProps {
    departments: AppointmentDepartment[];
    practitioners: AppointmentPractitioner[];
    appointmentTypes: string[];
    preselectedPatient?: PatientSearchResult | null;
    preselectedDepartmentId?: number | null;
}

/**
 * The "book a new appointment" form. Editing an existing appointment's
 * non-scheduling fields and rescheduling its time are handled by separate,
 * smaller forms (see edit.tsx and the reschedule dialog on show.tsx) —
 * this component only covers creation, where patient/department/
 * practitioner/time are all chosen together. `preselectedPatient`/
 * `preselectedDepartmentId` support the encounter disposition dialog's
 * "Schedule Follow-up" handoff (Step 27) without introducing a second
 * scheduling flow — the patient/department can still be changed here.
 */
export function AppointmentForm({
    departments,
    practitioners,
    appointmentTypes,
    preselectedPatient = null,
    preselectedDepartmentId = null,
}: AppointmentFormProps) {
    const [selectedPatient, setSelectedPatient] = useState<PatientSearchResult | null>(preselectedPatient);

    const { data, setData, post, processing, errors } = useForm<AppointmentBookingFormData>({
        patient_id: preselectedPatient ? String(preselectedPatient.id) : '',
        department_id: preselectedDepartmentId ? String(preselectedDepartmentId) : '',
        practitioner_id: '',
        appointment_type: '',
        scheduled_start: '',
        scheduled_end: '',
        reason: '',
        notes: '',
    });

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
        post(route('appointments.store'));
    };

    return (
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
                    <Label htmlFor="practitioner_id">Practitioner *</Label>
                    <Select value={data.practitioner_id} onValueChange={(value) => setData('practitioner_id', value)} disabled={!data.department_id}>
                        <SelectTrigger id="practitioner_id">
                            <SelectValue placeholder={data.department_id ? 'Select a practitioner' : 'Select a department first'} />
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
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="appointment_type">Appointment Type *</Label>
                    <Select
                        value={data.appointment_type}
                        onValueChange={(value) => setData('appointment_type', value as AppointmentBookingFormData['appointment_type'])}
                    >
                        <SelectTrigger id="appointment_type">
                            <SelectValue placeholder="Select a type" />
                        </SelectTrigger>
                        <SelectContent>
                            {appointmentTypes.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {APPOINTMENT_TYPE_LABELS[type] ?? type}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.appointment_type} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="scheduled_start">Start Time *</Label>
                    <Input
                        id="scheduled_start"
                        type="datetime-local"
                        value={data.scheduled_start}
                        onChange={(e) => setData('scheduled_start', e.target.value)}
                    />
                    <InputError message={errors.scheduled_start} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="scheduled_end">End Time *</Label>
                    <Input
                        id="scheduled_end"
                        type="datetime-local"
                        value={data.scheduled_end}
                        onChange={(e) => setData('scheduled_end', e.target.value)}
                    />
                    <InputError message={errors.scheduled_end} />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="reason">Reason</Label>
                <Textarea id="reason" value={data.reason} onChange={(e) => setData('reason', e.target.value)} rows={2} />
                <InputError message={errors.reason} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="notes">Notes</Label>
                <Textarea id="notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} />
                <InputError message={errors.notes} />
            </div>

            <div className="flex justify-end gap-2">
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Book Appointment
                </Button>
            </div>
        </form>
    );
}
