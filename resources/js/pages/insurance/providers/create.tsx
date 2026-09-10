import { ProviderForm } from '@/components/insurance/provider-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Insurance Providers', href: '/insurance/providers' },
    { title: 'Add Provider', href: '/insurance/providers/create' },
];

interface ProvidersCreateProps {
    types: string[];
}

export default function ProvidersCreate({ types }: ProvidersCreateProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add Insurance Provider" />

            <div className="p-4">
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Add Insurance Provider</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ProviderForm types={types} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
