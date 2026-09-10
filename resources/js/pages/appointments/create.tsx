import { AppointmentForm } from '@/components/appointments/appointment-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type AppointmentDepartment, type AppointmentPractitioner } from '@/types/appointment';
import { type PatientSearchResult } from '@/types/patient';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Appointments', href: '/appointments' },
    { title: 'Book Appointment', href: '/appointments/create' },
];

interface AppointmentCreateProps {
    departments: AppointmentDepartment[];
    practitioners: AppointmentPractitioner[];
    appointmentTypes: string[];
    preselectedPatient: PatientSearchResult | null;
    preselectedDepartmentId: number | null;
}

export default function AppointmentCreate({
    departments,
    practitioners,
    appointmentTypes,
    preselectedPatient,
    preselectedDepartmentId,
}: AppointmentCreateProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Book Appointment" />

            <div className="p-4">
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Book Appointment</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <AppointmentForm
                            departments={departments}
                            practitioners={practitioners}
                            appointmentTypes={appointmentTypes}
                            preselectedPatient={preselectedPatient}
                            preselectedDepartmentId={preselectedDepartmentId}
                        />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
