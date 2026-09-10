import { Can } from '@/components/authorization/can';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Department } from '@/types/department';
import { type Hospital } from '@/types/hospital';
import { Head, Link } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';

interface HospitalsShowProps {
    hospital: Hospital & { departments: Department[] };
}

export default function HospitalsShow({ hospital }: HospitalsShowProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Hospitals', href: '/hospitals' },
        { title: hospital.name, href: `/hospitals/${hospital.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={hospital.name} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{hospital.name}</h1>
                        <p className="text-muted-foreground text-sm">{hospital.code}</p>
                    </div>

                    <Can permission="hospitals.update">
                        <Button asChild variant="outline">
                            <Link href={route('hospitals.edit', hospital.id)}>
                                <Pencil className="h-4 w-4" />
                                Edit
                            </Link>
                        </Button>
                    </Can>
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Hospital Details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Status</dt>
                                    <dd>
                                        <Badge variant={hospital.status === 'active' ? 'default' : 'secondary'}>{hospital.status}</Badge>
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Registration Number</dt>
                                    <dd>{hospital.registration_number ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Phone</dt>
                                    <dd>{hospital.phone ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Email</dt>
                                    <dd>{hospital.email ?? '—'}</dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Location</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Address</dt>
                                    <dd className="text-right">{hospital.address ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Region</dt>
                                    <dd>{hospital.region ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">District</dt>
                                    <dd>{hospital.district ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Ward</dt>
                                    <dd>{hospital.ward ?? '—'}</dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0">
                        <CardTitle className="text-base">Departments</CardTitle>
                        <Can permission="departments.create">
                            <Button asChild size="sm" variant="outline">
                                <Link href={route('departments.create')}>
                                    <Plus className="h-4 w-4" />
                                    Add Department
                                </Link>
                            </Button>
                        </Can>
                    </CardHeader>
                    <CardContent>
                        {hospital.departments.length > 0 ? (
                            <ul className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                                {hospital.departments.map((department) => (
                                    <li key={department.id} className="flex items-center justify-between gap-4 py-2">
                                        <div>
                                            <Link href={route('departments.show', department.id)} className="font-medium hover:underline">
                                                {department.name}
                                            </Link>
                                            <span className="text-muted-foreground ml-2 text-xs">{department.code}</span>
                                        </div>
                                        <Badge variant={department.status === 'active' ? 'default' : 'secondary'}>{department.status}</Badge>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-muted-foreground text-sm">No departments found for this hospital.</p>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
