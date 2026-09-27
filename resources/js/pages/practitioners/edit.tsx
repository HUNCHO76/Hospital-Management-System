import { PractitionerForm } from '@/components/practitioners/practitioner-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type ConsultationServiceOption, type Practitioner } from '@/types/practitioner';
import { Head } from '@inertiajs/react';

interface PractitionersEditProps {
    practitioner: Practitioner;
    practitionerTypes: string[];
    services: ConsultationServiceOption[];
}

export default function PractitionersEdit({ practitioner, practitionerTypes, services }: PractitionersEditProps) {
    const staffName = practitioner.staff ? `${practitioner.staff.first_name} ${practitioner.staff.last_name}` : practitioner.practitioner_number;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Practitioners', href: '/practitioners' },
        { title: staffName, href: `/practitioners/${practitioner.id}` },
        { title: 'Edit', href: `/practitioners/${practitioner.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${staffName}`} />

            <div className="p-4">
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Edit Practitioner</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <PractitionerForm practitioner={practitioner} practitionerTypes={practitionerTypes} services={services} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
