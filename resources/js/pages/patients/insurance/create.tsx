import { PatientInsuranceForm } from '@/components/patients/patient-insurance-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type InsuranceProviderSummary } from '@/types/insurance-provider';
import {
    type InsuranceSchemeSummary,
    type PatientInsuranceFormData,
    type PatientInsurancePatient,
    type PossibleDuplicatePatientInsurance,
} from '@/types/patient-insurance';
import { Head } from '@inertiajs/react';

interface InsuranceCreateProps {
    patient: PatientInsurancePatient;
    providers: InsuranceProviderSummary[];
    schemes: InsuranceSchemeSummary[];
    possibleDuplicates: PossibleDuplicatePatientInsurance[];
    old: Partial<PatientInsuranceFormData>;
}

export default function InsuranceCreate({ patient, providers, schemes, possibleDuplicates, old }: InsuranceCreateProps) {
    const fullName = [patient.first_name, patient.middle_name, patient.last_name].filter(Boolean).join(' ');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Patients', href: '/patients' },
        { title: fullName, href: `/patients/${patient.id}` },
        { title: 'Add Insurance', href: `/patients/${patient.id}/insurance/create` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Add Insurance — ${fullName}`} />

            <div className="p-4">
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Add Insurance for {fullName}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <PatientInsuranceForm
                            patientId={patient.id}
                            providers={providers}
                            schemes={schemes}
                            possibleDuplicates={possibleDuplicates}
                            old={old}
                        />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
