import { StaffForm } from '@/components/staff/staff-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type DepartmentHospital } from '@/types/department';
import { type Staff } from '@/types/staff';
import { Head } from '@inertiajs/react';

interface StaffEditProps {
    staff: Staff;
    hospitals: DepartmentHospital[];
    departments: { id: number; hospital_id: number; name: string; code: string }[];
    employmentTypes: string[];
    roles?: string[];
    account?: {
        id: number;
        username: string;
        email: string;
        phone: string | null;
        status: string;
        role?: string;
    } | null;
}

export default function StaffEdit({ staff, hospitals, departments, employmentTypes, roles, account }: StaffEditProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Staff', href: '/staff' },
        { title: `${staff.first_name} ${staff.last_name}`, href: `/staff/${staff.id}` },
        { title: 'Edit', href: `/staff/${staff.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${staff.first_name} ${staff.last_name}`} />

            <div className="p-4">
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>Edit Staff</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <StaffForm
                            staff={staff}
                            hospitals={hospitals}
                            departments={departments}
                            employmentTypes={employmentTypes}
                            roles={roles}
                            account={account}
                        />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
