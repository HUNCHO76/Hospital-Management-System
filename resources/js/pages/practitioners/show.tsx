import { Can } from '@/components/authorization/can';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { licenseStatus, licenseStatusLabel } from '@/lib/license-status';
import { type BreadcrumbItem } from '@/types';
import {
    type NhifAttendanceStatus,
    type NhifPractitionerAttendance,
    type Practitioner,
} from '@/types/practitioner';
import { Head, Link, router } from '@inertiajs/react';
import { LogIn, LogOut, Pencil, ShieldCheck } from 'lucide-react';
import { useState } from 'react';

interface PractitionersShowProps {
    practitioner: Practitioner;
}

// ---- NHIF Attendance Card ----

const nhifAttendanceStatusLabel: Record<NhifAttendanceStatus, string> = {
    success: 'Success',
    failed: 'Failed',
    rejected: 'Rejected',
    already_logged_in: 'Already Logged In',
    error: 'Error',
};

function nhifAttendanceVariant(status: NhifAttendanceStatus): 'default' | 'secondary' | 'destructive' {
    if (status === 'success' || status === 'already_logged_in') return 'default';
    if (status === 'error' || status === 'rejected') return 'destructive';
    return 'secondary';
}

function NhifAttendanceCard({ attendance }: { attendance: NhifPractitionerAttendance }) {
    return (
        <Card className="max-w-2xl">
            <CardHeader>
                <CardTitle className="text-base">Latest NHIF Attendance</CardTitle>
            </CardHeader>
            <CardContent>
                <dl className="space-y-2 text-sm">
                    <div className="flex justify-between gap-4">
                        <dt className="text-muted-foreground">Operation</dt>
                        <dd className="capitalize">{attendance.operation}</dd>
                    </div>
                    <div className="flex justify-between gap-4">
                        <dt className="text-muted-foreground">Status</dt>
                        <dd>
                            <Badge variant={nhifAttendanceVariant(attendance.attendance_status)}>
                                {nhifAttendanceStatusLabel[attendance.attendance_status]}
                            </Badge>
                        </dd>
                    </div>
                    {attendance.nhif_response_code && (
                        <div className="flex justify-between gap-4">
                            <dt className="text-muted-foreground">Response Code</dt>
                            <dd>{attendance.nhif_response_code}</dd>
                        </div>
                    )}
                    {attendance.nhif_message && (
                        <div className="flex justify-between gap-4">
                            <dt className="text-muted-foreground">Message</dt>
                            <dd>{attendance.nhif_message}</dd>
                        </div>
                    )}
                    {attendance.error_message && (
                        <div className="flex justify-between gap-4">
                            <dt className="text-muted-foreground">Error</dt>
                            <dd className="text-destructive">{attendance.error_message}</dd>
                        </div>
                    )}
                    <div className="flex justify-between gap-4">
                        <dt className="text-muted-foreground">At</dt>
                        <dd>{attendance.attendance_at ?? attendance.created_at}</dd>
                    </div>
                </dl>
            </CardContent>
        </Card>
    );
}

// ---- Main page ----

export default function PractitionersShow({ practitioner }: PractitionersShowProps) {
    const [loggingIn, setLoggingIn] = useState(false);
    const [loggingOut, setLoggingOut] = useState(false);

    const staffName = practitioner.staff ? `${practitioner.staff.first_name} ${practitioner.staff.last_name}` : practitioner.practitioner_number;
    const license = licenseStatus(practitioner.license_expiry_date);
    const nhifConfigured = Boolean(practitioner.nhif_practitioner_no);

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

    function handleNhifLogin() {
        setLoggingIn(true);
        router.post(route('practitioners.nhif-login', practitioner.id), {}, {
            onFinish: () => setLoggingIn(false),
        });
    }

    function handleNhifLogout() {
        setLoggingOut(true);
        router.post(route('practitioners.nhif-logout', practitioner.id), {}, {
            onFinish: () => setLoggingOut(false),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={staffName} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{staffName}</h1>
                        <p className="text-muted-foreground text-sm">{practitioner.practitioner_number}</p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {nhifConfigured && (
                            <Can permission="practitioners.nhif-login">
                                <Button variant="outline" onClick={handleNhifLogin} disabled={loggingIn}>
                                    <LogIn className="h-4 w-4" />
                                    {loggingIn ? 'Logging in…' : 'NHIF Login'}
                                </Button>
                                <Button variant="outline" onClick={handleNhifLogout} disabled={loggingOut}>
                                    <LogOut className="h-4 w-4" />
                                    {loggingOut ? 'Logging out…' : 'NHIF Logout'}
                                </Button>
                            </Can>
                        )}

                        <Can permission="practitioners.update">
                            <Button asChild variant="outline">
                                <Link href={route('practitioners.edit', practitioner.id)}>
                                    <Pencil className="h-4 w-4" />
                                    Edit
                                </Link>
                            </Button>
                        </Can>
                    </div>
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
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground flex items-center gap-1">
                                    <ShieldCheck className="h-3 w-3" />
                                    NHIF Practitioner No.
                                </dt>
                                <dd>{practitioner.nhif_practitioner_no ?? <span className="text-muted-foreground italic">Not configured</span>}</dd>
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

                {practitioner.latest_nhif_attendance && (
                    <NhifAttendanceCard attendance={practitioner.latest_nhif_attendance} />
                )}
            </div>
        </AppLayout>
    );
}
