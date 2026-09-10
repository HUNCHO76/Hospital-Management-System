import { LaboratoryTestForm } from '@/components/laboratory/laboratory-test-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import {
    type LaboratoryResultType,
    type LaboratorySpecimenContainer,
    type LaboratorySpecimenType,
    type LaboratoryTestCategoryOption,
    type LaboratoryTestDepartment,
} from '@/types/laboratory';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Laboratory', href: '/laboratory' },
    { title: 'Test Catalog', href: '/laboratory/tests' },
    { title: 'Add Test', href: '/laboratory/tests/create' },
];

interface LaboratoryTestCreateProps {
    categories: LaboratoryTestCategoryOption[];
    departments: LaboratoryTestDepartment[];
    specimenTypes: LaboratorySpecimenType[];
    specimenContainers: LaboratorySpecimenContainer[];
    resultTypes: LaboratoryResultType[];
}

export default function LaboratoryTestCreate({ categories, departments, specimenTypes, specimenContainers, resultTypes }: LaboratoryTestCreateProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add Laboratory Test" />

            <div className="p-4">
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Add Laboratory Test</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <LaboratoryTestForm
                            categories={categories}
                            departments={departments}
                            specimenTypes={specimenTypes}
                            specimenContainers={specimenContainers}
                            resultTypes={resultTypes}
                        />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
