import { SchemeForm } from '@/components/insurance/scheme-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type InsuranceProviderSummary } from '@/types/insurance-provider';
import { type InsuranceScheme } from '@/types/insurance-scheme';
import { Head } from '@inertiajs/react';

interface SchemesEditProps {
    scheme: InsuranceScheme;
    providers: InsuranceProviderSummary[];
}

export default function SchemesEdit({ scheme, providers }: SchemesEditProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Insurance Schemes', href: '/insurance/schemes' },
        { title: scheme.name, href: `/insurance/schemes/${scheme.id}` },
        { title: 'Edit', href: `/insurance/schemes/${scheme.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${scheme.name}`} />

            <div className="p-4">
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Edit Insurance Scheme</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <SchemeForm scheme={scheme} providers={providers} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
