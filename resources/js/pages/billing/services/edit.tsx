import { ServiceForm } from '@/components/billing/service-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Service, type ServiceCategory } from '@/types/service';
import { Head } from '@inertiajs/react';

interface BillingServiceEditProps {
    service: Service;
    categories: ServiceCategory[];
}

export default function BillingServiceEdit({ service, categories }: BillingServiceEditProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Billing', href: '/billing' },
        { title: 'Services', href: '/billing/services' },
        { title: service.name, href: `/billing/services/${service.id}` },
        { title: 'Edit', href: `/billing/services/${service.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${service.name}`} />

            <div className="p-4">
                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Edit Service</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ServiceForm service={service} categories={categories} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
