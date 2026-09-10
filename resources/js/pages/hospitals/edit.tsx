import { HospitalForm } from '@/components/hospitals/hospital-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Hospital } from '@/types/hospital';
import { Head } from '@inertiajs/react';

interface HospitalsEditProps {
    hospital: Hospital;
}

export default function HospitalsEdit({ hospital }: HospitalsEditProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Hospitals', href: '/hospitals' },
        { title: hospital.name, href: `/hospitals/${hospital.id}` },
        { title: 'Edit', href: `/hospitals/${hospital.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${hospital.name}`} />

            <div className="p-4">
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Edit Hospital</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <HospitalForm hospital={hospital} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
