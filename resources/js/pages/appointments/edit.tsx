import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Appointment, type AppointmentEditFormData } from '@/types/appointment';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

const APPOINTMENT_TYPE_LABELS: Record<string, string> = {
    initial_consultation: 'Initial Consultation',
    follow_up: 'Follow-up',
    review: 'Review',
    procedure: 'Procedure',
    specialist_consultation: 'Specialist Consultation',
    other: 'Other',
};

interface AppointmentEditProps {
    appointment: Appointment;
    appointmentTypes: string[];
}

/**
 * Only non-scheduling fields are editable here — changing the time goes
 * through the separate reschedule action (see show.tsx), which re-runs
 * conflict detection.
 */
export default function AppointmentEdit({ appointment, appointmentTypes }: AppointmentEditProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Appointments', href: '/appointments' },
        { title: appointment.appointment_number, href: `/appointments/${appointment.id}` },
        { title: 'Edit', href: `/appointments/${appointment.id}/edit` },
    ];

    const { data, setData, put, processing, errors } = useForm<AppointmentEditFormData>({
        appointment_type: appointment.appointment_type,
        reason: appointment.reason ?? '',
        notes: appointment.notes ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('appointments.update', appointment.id));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit — ${appointment.appointment_number}`} />

            <div className="p-4">
                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Edit Appointment {appointment.appointment_number}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-6">
                            <div className="grid gap-2">
                                <Label htmlFor="appointment_type">Appointment Type *</Label>
                                <Select
                                    value={data.appointment_type}
                                    onValueChange={(value) => setData('appointment_type', value as AppointmentEditFormData['appointment_type'])}
                                >
                                    <SelectTrigger id="appointment_type">
                                        <SelectValue />
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
                                <Label htmlFor="reason">Reason</Label>
                                <Textarea id="reason" value={data.reason} onChange={(e) => setData('reason', e.target.value)} rows={2} />
                                <InputError message={errors.reason} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="notes">Notes</Label>
                                <Textarea id="notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={3} />
                                <InputError message={errors.notes} />
                            </div>

                            <div className="flex justify-end gap-2">
                                <Button type="button" variant="outline" asChild>
                                    <Link href={route('appointments.show', appointment.id)}>Cancel</Link>
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                    Save changes
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
