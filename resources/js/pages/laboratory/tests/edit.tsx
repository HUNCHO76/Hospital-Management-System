import { LaboratoryTestForm } from '@/components/laboratory/laboratory-test-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import {
    type LaboratoryResultType,
    type LaboratorySpecimenContainer,
    type LaboratorySpecimenType,
    type LaboratoryTest,
    type LaboratoryTestCategoryOption,
    type LaboratoryTestDepartment,
} from '@/types/laboratory';
import { Head } from '@inertiajs/react';

interface LaboratoryTestEditProps {
    test: LaboratoryTest;
    isReferenced: boolean;
    categories: LaboratoryTestCategoryOption[];
    departments: LaboratoryTestDepartment[];
    specimenTypes: LaboratorySpecimenType[];
    specimenContainers: LaboratorySpecimenContainer[];
    resultTypes: LaboratoryResultType[];
}

export default function LaboratoryTestEdit({
    test,
    isReferenced,
    categories,
    departments,
    specimenTypes,
    specimenContainers,
    resultTypes,
}: LaboratoryTestEditProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Laboratory', href: '/laboratory' },
        { title: 'Test Catalog', href: '/laboratory/tests' },
        { title: test.name, href: `/laboratory/tests/${test.id}` },
        { title: 'Edit', href: `/laboratory/tests/${test.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${test.name}`} />

            <div className="p-4">
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Edit Laboratory Test</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <LaboratoryTestForm
                            test={test}
                            isReferenced={isReferenced}
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
