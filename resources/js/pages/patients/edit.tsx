import { PatientForm } from '@/components/patients/patient-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Patient } from '@/types/patient';
import { Head } from '@inertiajs/react';

interface PatientsEditProps {
    patient: Patient;
}

export default function PatientsEdit({ patient }: PatientsEditProps) {
    const fullName = [patient.first_name, patient.middle_name, patient.last_name].filter(Boolean).join(' ');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Patients', href: '/patients' },
        { title: fullName, href: `/patients/${patient.id}` },
        { title: 'Edit', href: `/patients/${patient.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${fullName}`} />

            <div className="p-4">
                <Card className="max-w-4xl">
                    <CardHeader>
                        <CardTitle>Edit Patient</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <PatientForm patient={patient} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
