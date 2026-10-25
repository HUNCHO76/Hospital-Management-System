import { Can } from '@/components/authorization/can';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Staff } from '@/types/staff';
import { Head, Link, router } from '@inertiajs/react';
import { KeyRound, Pencil, Shield } from 'lucide-react';
import { useState } from 'react';

interface StaffShowProps {
    staff: Staff;
    roles: string[];
}

export default function StaffShow({ staff, roles }: StaffShowProps) {
    const [activationOpen, setActivationOpen] = useState(false);
    const [activationEmail, setActivationEmail] = useState(staff.email ?? staff.user?.email ?? '');
    const [isActivating, setIsActivating] = useState(false);

    const handleGenerateActivation = (e: React.FormEvent) => {
        e.preventDefault();
        setIsActivating(true);
        router.post(
            route('staff.activation.store', staff.id),
            { email: activationEmail || undefined },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setIsActivating(false);
                    setActivationOpen(false);
                },
                onError: () => {
                    setIsActivating(false);
                },
            },
        );
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Staff', href: '/staff' },
        { title: `${staff.first_name} ${staff.last_name}`, href: `/staff/${staff.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${staff.first_name} ${staff.last_name}`} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">
                            {staff.first_name} {staff.middle_name ? `${staff.middle_name} ` : ''}
                            {staff.last_name}
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            {staff.staff_number} · {staff.job_title}
                        </p>
                    </div>

                    <Can permission="staff.update">
                        <Button asChild variant="outline">
                            <Link href={route('staff.edit', staff.id)}>
                                <Pencil className="h-4 w-4" />
                                Edit
                            </Link>
                        </Button>
                    </Can>
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Personal</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Gender</dt>
                                    <dd className="capitalize">{staff.gender}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Date of Birth</dt>
                                    <dd>{staff.date_of_birth ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Phone</dt>
                                    <dd>{staff.phone ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Email</dt>
                                    <dd>{staff.email ?? '—'}</dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Employment</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Staff Number</dt>
                                    <dd>{staff.staff_number}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Hospital</dt>
                                    <dd>{staff.hospital?.name ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Department</dt>
                                    <dd>{staff.department?.name ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Employment Type</dt>
                                    <dd className="capitalize">{staff.employment_type.replace('_', ' ')}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Status</dt>
                                    <dd>
                                        <Badge variant={staff.status === 'active' ? 'default' : 'secondary'}>{staff.status}</Badge>
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Hired At</dt>
                                    <dd>{staff.hired_at ?? '—'}</dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base flex items-center justify-between">
                                <span>Account</span>
                                {staff.user && (
                                    <Badge variant={staff.user.status === 'active' ? 'default' : 'secondary'}>
                                        {staff.user.status === 'active' ? 'Active' : 'Pending Activation'}
                                    </Badge>
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {staff.user ? (
                                <dl className="space-y-2 text-sm">
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Username</dt>
                                        <dd className="font-mono text-xs">{staff.user.UserName}</dd>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Email</dt>
                                        <dd>{staff.user.email ?? <span className="text-muted-foreground italic">Awaiting verification</span>}</dd>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Roles</dt>
                                        <dd className="text-right">{roles.length > 0 ? roles.join(', ') : '—'}</dd>
                                    </div>
                                    <div className="pt-3 border-t space-y-2">
                                        <Button asChild size="sm" variant="outline" className="w-full">
                                            <Link href={route('users.permissions.edit', staff.user.id)}>
                                                <Shield className="mr-1.5 h-4 w-4 text-primary" />
                                                Manage Permissions & Overrides
                                            </Link>
                                        </Button>

                                        <Can permission="staff.update">
                                            <Button
                                                size="sm"
                                                variant="secondary"
                                                className="w-full"
                                                onClick={() => setActivationOpen(true)}
                                            >
                                                <KeyRound className="mr-1.5 h-4 w-4" />
                                                {staff.user.status === 'active' ? 'Generate Activation / Reset Link' : 'Activate Account & Set Email'}
                                            </Button>
                                        </Can>
                                    </div>
                                </dl>
                            ) : (
                                <p className="text-muted-foreground text-sm">No system login account.</p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Practitioner</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {staff.practitioner ? (
                                <dl className="space-y-2 text-sm">
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Practitioner Number</dt>
                                        <dd>{staff.practitioner.practitioner_number}</dd>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Type</dt>
                                        <dd className="capitalize">{staff.practitioner.practitioner_type.replace('-', ' ')}</dd>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Registration Number</dt>
                                        <dd>{staff.practitioner.professional_registration_number ?? '—'}</dd>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Specialization</dt>
                                        <dd>{staff.practitioner.specialization ?? '—'}</dd>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">License</dt>
                                        <dd>{staff.practitioner.license_number ?? '—'}</dd>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Status</dt>
                                        <dd>
                                            <Badge variant={staff.practitioner.status === 'active' ? 'default' : 'secondary'}>
                                                {staff.practitioner.status}
                                            </Badge>
                                        </dd>
                                    </div>
                                    <Can permission="practitioners.view">
                                        <Link
                                            href={route('practitioners.show', staff.practitioner.id)}
                                            className="text-primary text-sm hover:underline"
                                        >
                                            View practitioner profile
                                        </Link>
                                    </Can>
                                </dl>
                            ) : (
                                <p className="text-muted-foreground text-sm">No practitioner profile.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>

            {/* Account Activation Dialog */}
            <Dialog open={activationOpen} onOpenChange={setActivationOpen}>
                <DialogContent>
                    <form onSubmit={handleGenerateActivation}>
                        <DialogHeader>
                            <DialogTitle>Generate Secure Activation Link</DialogTitle>
                            <DialogDescription>
                                Set or verify the staff member's real email address to generate a single-use,
                                cryptographically secure account activation link.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-4 py-4">
                            <div className="space-y-2">
                                <Label htmlFor="staff_email">Staff Verified Email Address</Label>
                                <Input
                                    id="staff_email"
                                    type="email"
                                    placeholder="e.g. staff.name@hospital.org"
                                    value={activationEmail}
                                    onChange={(e) => setActivationEmail(e.target.value)}
                                    autoFocus
                                />
                                <p className="text-xs text-muted-foreground">
                                    The activation link will allow the user to set their password upon first login.
                                    The link expires automatically after 7 days.
                                </p>
                            </div>
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setActivationOpen(false)}
                                disabled={isActivating}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={isActivating}>
                                {isActivating ? 'Generating...' : 'Generate Activation Link'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
