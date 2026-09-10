import { ProviderForm } from '@/components/insurance/provider-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type InsuranceProvider } from '@/types/insurance-provider';
import { Head } from '@inertiajs/react';

interface ProvidersEditProps {
    provider: InsuranceProvider;
    types: string[];
}

export default function ProvidersEdit({ provider, types }: ProvidersEditProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Insurance Providers', href: '/insurance/providers' },
        { title: provider.name, href: `/insurance/providers/${provider.id}` },
        { title: 'Edit', href: `/insurance/providers/${provider.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${provider.name}`} />

            <div className="p-4">
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Edit Insurance Provider</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ProviderForm provider={provider} types={types} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
