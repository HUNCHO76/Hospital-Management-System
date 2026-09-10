import { SchemeForm } from '@/components/insurance/scheme-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type InsuranceProviderSummary } from '@/types/insurance-provider';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Insurance Schemes', href: '/insurance/schemes' },
    { title: 'Add Scheme', href: '/insurance/schemes/create' },
];

interface SchemesCreateProps {
    providers: InsuranceProviderSummary[];
}

export default function SchemesCreate({ providers }: SchemesCreateProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add Insurance Scheme" />

            <div className="p-4">
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Add Insurance Scheme</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {providers.length > 0 ? (
                            <SchemeForm providers={providers} />
                        ) : (
                            <p className="text-muted-foreground text-sm">
                                There are no active insurance providers yet. Add a provider first.
                            </p>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
