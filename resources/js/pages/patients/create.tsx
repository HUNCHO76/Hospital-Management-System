import { PatientForm } from '@/components/patients/patient-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type PatientFormData, type PossibleDuplicatePatient } from '@/types/patient';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Patients', href: '/patients' },
    { title: 'Register Patient', href: '/patients/create' },
];

interface PatientsCreateProps {
    possibleDuplicates: PossibleDuplicatePatient[];
    confirmedDuplicate: PossibleDuplicatePatient | null;
    old: Partial<PatientFormData>;
}

export default function PatientsCreate({ possibleDuplicates, confirmedDuplicate, old }: PatientsCreateProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Register Patient" />

            <div className="p-4">
                <Card className="max-w-4xl">
                    <CardHeader>
                        <CardTitle>Register Patient</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <PatientForm possibleDuplicates={possibleDuplicates} confirmedDuplicate={confirmedDuplicate} old={old} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
