import { DepartmentForm } from '@/components/departments/department-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type DepartmentHospital } from '@/types/department';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Departments', href: '/departments' },
    { title: 'Add Department', href: '/departments/create' },
];

interface DepartmentsCreateProps {
    hospitals: DepartmentHospital[];
}

export default function DepartmentsCreate({ hospitals }: DepartmentsCreateProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add Department" />

            <div className="p-4">
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Add Department</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <DepartmentForm hospitals={hospitals} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
