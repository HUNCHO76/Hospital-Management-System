import { Can } from '@/components/authorization/can';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Department } from '@/types/department';
import { Head, Link } from '@inertiajs/react';
import { Pencil } from 'lucide-react';

interface DepartmentsShowProps {
    department: Department;
}

export default function DepartmentsShow({ department }: DepartmentsShowProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Departments', href: '/departments' },
        { title: department.name, href: `/departments/${department.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={department.name} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{department.name}</h1>
                        <p className="text-muted-foreground text-sm">{department.code}</p>
                    </div>

                    <Can permission="departments.update">
                        <Button asChild variant="outline">
                            <Link href={route('departments.edit', department.id)}>
                                <Pencil className="h-4 w-4" />
                                Edit
                            </Link>
                        </Button>
                    </Can>
                </div>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle className="text-base">Department Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="space-y-2 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Status</dt>
                                <dd>
                                    <Badge variant={department.status === 'active' ? 'default' : 'secondary'}>{department.status}</Badge>
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Hospital</dt>
                                <dd>
                                    {department.hospital ? (
                                        <Link href={route('hospitals.show', department.hospital.id)} className="hover:underline">
                                            {department.hospital.name}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Description</dt>
                                <dd className="text-right">{department.description ?? '—'}</dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
