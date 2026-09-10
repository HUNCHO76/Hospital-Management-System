import { StaffForm } from '@/components/staff/staff-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type DepartmentHospital } from '@/types/department';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Staff', href: '/staff' },
    { title: 'Add Staff', href: '/staff/create' },
];

interface StaffCreateProps {
    hospitals: DepartmentHospital[];
    departments: { id: number; hospital_id: number; name: string; code: string }[];
    roles: string[];
    employmentTypes: string[];
}

export default function StaffCreate({ hospitals, departments, roles, employmentTypes }: StaffCreateProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add Staff" />

            <div className="p-4">
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Add Staff</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <StaffForm hospitals={hospitals} departments={departments} roles={roles} employmentTypes={employmentTypes} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
