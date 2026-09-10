import { LaboratoryBenchForm } from '@/components/laboratory/laboratory-bench-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Laboratory', href: '/laboratory' },
    { title: 'Benches', href: '/laboratory/benches' },
    { title: 'Add Bench', href: '/laboratory/benches/create' },
];

export default function LaboratoryBenchCreate() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add Laboratory Bench" />

            <div className="p-4">
                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Add Laboratory Bench</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <LaboratoryBenchForm />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
