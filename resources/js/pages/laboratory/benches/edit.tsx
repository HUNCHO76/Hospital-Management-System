import { LaboratoryBenchForm } from '@/components/laboratory/laboratory-bench-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type LaboratoryBench } from '@/types/laboratory';
import { Head } from '@inertiajs/react';

interface LaboratoryBenchEditProps {
    bench: LaboratoryBench;
}

export default function LaboratoryBenchEdit({ bench }: LaboratoryBenchEditProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Laboratory', href: '/laboratory' },
        { title: 'Benches', href: '/laboratory/benches' },
        { title: 'Edit', href: `/laboratory/benches/${bench.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${bench.name}`} />

            <div className="p-4">
                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Edit Laboratory Bench</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <LaboratoryBenchForm bench={bench} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
