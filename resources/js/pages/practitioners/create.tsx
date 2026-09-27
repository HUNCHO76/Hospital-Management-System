import { PractitionerForm } from '@/components/practitioners/practitioner-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type ConsultationServiceOption, type PractitionerStaff } from '@/types/practitioner';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Practitioners', href: '/practitioners' },
    { title: 'Add Practitioner', href: '/practitioners/create' },
];

interface PractitionersCreateProps {
    eligibleStaff: PractitionerStaff[];
    practitionerTypes: string[];
    services: ConsultationServiceOption[];
}

export default function PractitionersCreate({ eligibleStaff, practitionerTypes, services }: PractitionersCreateProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add Practitioner" />

            <div className="p-4">
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Add Practitioner</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {eligibleStaff.length > 0 ? (
                            <PractitionerForm eligibleStaff={eligibleStaff} practitionerTypes={practitionerTypes} services={services} />
                        ) : (
                            <p className="text-muted-foreground text-sm">
                                There are no staff members eligible for a practitioner profile — every staff member either already has one, or
                                none exist yet.
                            </p>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
