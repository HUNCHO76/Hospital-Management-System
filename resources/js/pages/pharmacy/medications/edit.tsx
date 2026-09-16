import { MedicationForm } from '@/components/pharmacy/medication-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Medication, type MedicationForm as MedicationFormValue, type MedicationRoute } from '@/types/medication';
import { Head } from '@inertiajs/react';

interface MedicationsEditProps {
    medication: Medication;
    isReferenced: boolean;
    forms: MedicationFormValue[];
    routes: MedicationRoute[];
}

export default function MedicationsEdit({ medication, ...rest }: MedicationsEditProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Pharmacy', href: '/pharmacy' },
        { title: 'Medications', href: '/pharmacy/medications' },
        { title: medication.generic_name, href: `/pharmacy/medications/${medication.id}` },
        { title: 'Edit', href: `/pharmacy/medications/${medication.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${medication.generic_name}`} />

            <div className="p-4">
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Edit Medication</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <MedicationForm medication={medication} {...rest} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
