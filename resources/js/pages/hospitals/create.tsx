import { HospitalForm } from '@/components/hospitals/hospital-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type HospitalConsultationServiceOption } from '@/types/hospital';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Hospitals', href: '/hospitals' },
    { title: 'Add Hospital', href: '/hospitals/create' },
];

interface HospitalsCreateProps {
    consultationServices: HospitalConsultationServiceOption[];
}

export default function HospitalsCreate({ consultationServices }: HospitalsCreateProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add Hospital" />

            <div className="p-4">
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Add Hospital</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <HospitalForm consultationServices={consultationServices} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
