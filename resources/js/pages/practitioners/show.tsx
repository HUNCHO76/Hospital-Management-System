import { Can } from '@/components/authorization/can';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { licenseStatus, licenseStatusLabel } from '@/lib/license-status';
import { type BreadcrumbItem } from '@/types';
import { type Practitioner } from '@/types/practitioner';
import { Head, Link } from '@inertiajs/react';
import { Pencil } from 'lucide-react';

interface PractitionersShowProps {
    practitioner: Practitioner;
}

export default function PractitionersShow({ practitioner }: PractitionersShowProps) {
    const staffName = practitioner.staff ? `${practitioner.staff.first_name} ${practitioner.staff.last_name}` : practitioner.practitioner_number;
    const license = licenseStatus(practitioner.license_expiry_date);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Practitioners', href: '/practitioners' },
        { title: staffName, href: `/practitioners/${practitioner.id}` },
    ];

    const licenseVariant: Record<string, 'default' | 'secondary' | 'destructive'> = {
        valid: 'default',
        'expiring-soon': 'secondary',
        expired: 'destructive',
        unknown: 'secondary',
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={staffName} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{staffName}</h1>
                        <p className="text-muted-foreground text-sm">{practitioner.practitioner_number}</p>
                    </div>

                    <Can permission="practitioners.update">
                        <Button asChild variant="outline">
                            <Link href={route('practitioners.edit', practitioner.id)}>
                                <Pencil className="h-4 w-4" />
                                Edit
                            </Link>
                        </Button>
                    </Can>
                </div>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle className="text-base">Practitioner Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="space-y-2 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Type</dt>
                                <dd className="capitalize">{practitioner.practitioner_type.replace('-', ' ')}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Registration Number</dt>
                                <dd>{practitioner.professional_registration_number ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Specialization</dt>
                                <dd>{practitioner.specialization ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">License Number</dt>
                                <dd>{practitioner.license_number ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">License Expiry</dt>
                                <dd className="flex items-center gap-2">
                                    {practitioner.license_expiry_date ?? '—'}
                                    <Badge variant={licenseVariant[license]}>{licenseStatusLabel[license]}</Badge>
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Hospital</dt>
                                <dd>{practitioner.staff?.hospital?.name ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Department</dt>
                                <dd>{practitioner.staff?.department?.name ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Status</dt>
                                <dd>
                                    <Badge variant={practitioner.status === 'active' ? 'default' : 'secondary'}>{practitioner.status}</Badge>
                                </dd>
                            </div>
                            {practitioner.staff && (
                                <div className="pt-2">
                                    <Can permission="staff.view">
                                        <Link href={route('staff.show', practitioner.staff.id)} className="text-primary hover:underline">
                                            View staff profile
                                        </Link>
                                    </Can>
                                </div>
                            )}
                        </dl>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
