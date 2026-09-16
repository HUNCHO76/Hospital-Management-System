import { MedicationForm } from '@/components/pharmacy/medication-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type MedicationForm as MedicationFormValue, type MedicationRoute } from '@/types/medication';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pharmacy', href: '/pharmacy' },
    { title: 'Medications', href: '/pharmacy/medications' },
    { title: 'Add Medication', href: '/pharmacy/medications/create' },
];

interface MedicationsCreateProps {
    forms: MedicationFormValue[];
    routes: MedicationRoute[];
}

export default function MedicationsCreate(props: MedicationsCreateProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add Medication" />

            <div className="p-4">
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Add Medication</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <MedicationForm {...props} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
