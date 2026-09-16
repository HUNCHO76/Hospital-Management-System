import { ServiceForm } from '@/components/billing/service-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type ServiceCategory } from '@/types/service';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing', href: '/billing' },
    { title: 'Services', href: '/billing/services' },
    { title: 'New Service', href: '/billing/services/create' },
];

interface BillingServiceCreateProps {
    categories: ServiceCategory[];
}

export default function BillingServiceCreate({ categories }: BillingServiceCreateProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New Service" />

            <div className="p-4">
                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>New Service</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ServiceForm categories={categories} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
