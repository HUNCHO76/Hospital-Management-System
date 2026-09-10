import { DepartmentForm } from '@/components/departments/department-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Department, type DepartmentHospital } from '@/types/department';
import { Head } from '@inertiajs/react';

interface DepartmentsEditProps {
    department: Department;
    hospitals: DepartmentHospital[];
}

export default function DepartmentsEdit({ department, hospitals }: DepartmentsEditProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Departments', href: '/departments' },
        { title: department.name, href: `/departments/${department.id}` },
        { title: 'Edit', href: `/departments/${department.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${department.name}`} />

            <div className="p-4">
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Edit Department</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <DepartmentForm department={department} hospitals={hospitals} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
