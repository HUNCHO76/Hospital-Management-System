import { PatientInsuranceForm } from '@/components/patients/patient-insurance-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type InsuranceProviderSummary } from '@/types/insurance-provider';
import { type InsuranceSchemeSummary, type PatientInsurance, type PatientInsurancePatient } from '@/types/patient-insurance';
import { Head } from '@inertiajs/react';

interface InsuranceEditProps {
    patient: PatientInsurancePatient;
    insurance: PatientInsurance;
    providers: InsuranceProviderSummary[];
    schemes: InsuranceSchemeSummary[];
}

export default function InsuranceEdit({ patient, insurance, providers, schemes }: InsuranceEditProps) {
    const fullName = [patient.first_name, patient.middle_name, patient.last_name].filter(Boolean).join(' ');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Patients', href: '/patients' },
        { title: fullName, href: `/patients/${patient.id}` },
        { title: 'Edit Insurance', href: `/patients/${patient.id}/insurance/${insurance.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit Insurance — ${fullName}`} />

            <div className="p-4">
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Edit Insurance for {fullName}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <PatientInsuranceForm patientId={patient.id} insurance={insurance} providers={providers} schemes={schemes} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
