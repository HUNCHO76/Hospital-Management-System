import { Can } from '@/components/authorization/can';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { calculateAge, formatDate } from '@/lib/date';
import { effectiveInsuranceStatus, insuranceStatusLabel } from '@/lib/insurance-status';
import { type BreadcrumbItem } from '@/types';
import { type Patient } from '@/types/patient';
import { Head, Link, router } from '@inertiajs/react';
import { CalendarClock, ClipboardList, History, Pencil, Plus, Star, Stethoscope } from 'lucide-react';

interface PatientsShowProps {
    patient: Patient;
}

const statusVariant: Record<string, 'default' | 'secondary' | 'destructive'> = {
    active: 'default',
    inactive: 'secondary',
    deceased: 'destructive',
};

export default function PatientsShow({ patient }: PatientsShowProps) {
    const { can } = usePermissions();
    const fullName = [patient.first_name, patient.middle_name, patient.last_name].filter(Boolean).join(' ');
    const insurances = patient.insurances ?? [];
    const primaryInsurance = insurances.find((insurance) => insurance.is_primary);
    const otherInsurances = insurances.filter((insurance) => !insurance.is_primary);

    const makePrimary = (insuranceId: number) => {
        router.post(route('patients.insurance.make-primary', [patient.id, insuranceId]), {}, { preserveScroll: true });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Patients', href: '/patients' },
        { title: fullName, href: `/patients/${patient.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={fullName} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{fullName}</h1>
                        <p className="text-muted-foreground text-sm">{patient.patient_number}</p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Can permission="patients.view">
                            <Button asChild variant="outline">
                                <Link href={route('patients.history', patient.id)}>
                                    <History className="h-4 w-4" />
                                    History
                                </Link>
                            </Button>
                        </Can>
                        <Can permission="clinical-history.view">
                            <Button asChild variant="outline">
                                <Link href={route('patients.clinical-history', patient.id)}>
                                    <Stethoscope className="h-4 w-4" />
                                    Clinical History
                                </Link>
                            </Button>
                        </Can>
                        <Can permission="appointments.view">
                            <Button asChild variant="outline">
                                <Link href={route('appointments.index', { patient_id: patient.id })}>
                                    <CalendarClock className="h-4 w-4" />
                                    Appointments
                                </Link>
                            </Button>
                        </Can>
                        <Can permission="reception.view">
                            <Button asChild variant="outline">
                                <Link href={route('reception.visits.index', { patient_id: patient.id })}>
                                    <ClipboardList className="h-4 w-4" />
                                    Reception Visits
                                </Link>
                            </Button>
                        </Can>
                        <Can permission="patients.update">
                            <Button asChild variant="outline">
                                <Link href={route('patients.edit', patient.id)}>
                                    <Pencil className="h-4 w-4" />
                                    Edit
                                </Link>
                            </Button>
                        </Can>
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Patient Information</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Patient Number</dt>
                                    <dd>{patient.patient_number}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Gender</dt>
                                    <dd className="capitalize">{patient.gender}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Date of Birth</dt>
                                    <dd>
                                        {formatDate(patient.date_of_birth)}
                                        {patient.date_of_birth && (
                                            <span className="text-muted-foreground ml-1">({calculateAge(patient.date_of_birth)})</span>
                                        )}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Marital Status</dt>
                                    <dd className="capitalize">{patient.marital_status ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Occupation</dt>
                                    <dd>{patient.occupation ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Blood Group</dt>
                                    <dd>{patient.blood_group ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Status</dt>
                                    <dd>
                                        <Badge variant={statusVariant[patient.status]}>{patient.status}</Badge>
                                    </dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Contact Information</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Phone</dt>
                                    <dd>{patient.phone ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Email</dt>
                                    <dd>{patient.email ?? '—'}</dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Identification</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">National ID</dt>
                                    <dd>{patient.national_id ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Passport Number</dt>
                                    <dd>{patient.passport_number ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Other Identification</dt>
                                    <dd>{patient.other_identification ?? '—'}</dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Address</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Address</dt>
                                    <dd className="text-right">{patient.address ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Region</dt>
                                    <dd>{patient.region ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">District</dt>
                                    <dd>{patient.district ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Ward</dt>
                                    <dd>{patient.ward ?? '—'}</dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Emergency / Next-of-Kin Contacts</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {patient.contacts && patient.contacts.length > 0 ? (
                            <ul className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                                {patient.contacts.map((contact) => (
                                    <li key={contact.id} className="flex flex-wrap items-center justify-between gap-2 py-3">
                                        <div>
                                            <p className="font-medium">
                                                {contact.name}
                                                {contact.is_primary && (
                                                    <Badge className="ml-2" variant="default">
                                                        Primary
                                                    </Badge>
                                                )}
                                            </p>
                                            <p className="text-muted-foreground text-sm">
                                                {contact.relationship} · {contact.phone}
                                                {contact.alternative_phone ? ` / ${contact.alternative_phone}` : ''}
                                            </p>
                                            {contact.address && <p className="text-muted-foreground text-sm">{contact.address}</p>}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-muted-foreground text-sm">No emergency or next-of-kin contacts registered.</p>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0">
                        <CardTitle className="text-base">Insurance</CardTitle>
                        <Can permission="patient-insurance.create">
                            <Button asChild size="sm" variant="outline">
                                <Link href={route('patients.insurance.create', patient.id)}>
                                    <Plus className="h-4 w-4" />
                                    Add Insurance
                                </Link>
                            </Button>
                        </Can>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {insurances.length === 0 ? (
                            <p className="text-muted-foreground text-sm">No insurance records registered for this patient.</p>
                        ) : (
                            <>
                                {primaryInsurance && (
                                    <div>
                                        <h3 className="text-muted-foreground mb-2 text-xs font-medium tracking-wide uppercase">Primary Insurance</h3>
                                        <InsuranceRow patient={patient} insurance={primaryInsurance} canUpdate={can('patient-insurance.update')} />
                                    </div>
                                )}

                                {otherInsurances.length > 0 && (
                                    <div>
                                        <h3 className="text-muted-foreground mb-2 text-xs font-medium tracking-wide uppercase">Other Insurance</h3>
                                        <div className="space-y-3">
                                            {otherInsurances.map((insurance) => (
                                                <InsuranceRow
                                                    key={insurance.id}
                                                    patient={patient}
                                                    insurance={insurance}
                                                    canUpdate={can('patient-insurance.update')}
                                                    onMakePrimary={insurance.status === 'active' ? () => makePrimary(insurance.id) : undefined}
                                                />
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

interface InsuranceRowProps {
    patient: Patient;
    insurance: NonNullable<Patient['insurances']>[number];
    canUpdate: boolean;
    onMakePrimary?: () => void;
}

function InsuranceRow({ patient, insurance, canUpdate, onMakePrimary }: InsuranceRowProps) {
    const displayStatus = effectiveInsuranceStatus(insurance.status, insurance.effective_to);

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3">
            <div>
                <p className="font-medium">{insurance.insurance_provider?.name ?? 'Insurance'}</p>
                <p className="text-muted-foreground text-sm">
                    Membership: {insurance.membership_number ?? '—'}
                    {insurance.insurance_scheme && ` · ${insurance.insurance_scheme.name}`}
                </p>
                <div className="mt-1 flex items-center gap-2">
                    <Badge variant={displayStatus === 'active' ? 'default' : 'secondary'}>{insuranceStatusLabel[displayStatus]}</Badge>
                    <span className="text-muted-foreground text-xs capitalize">Verification: {insurance.verification_status}</span>
                </div>
            </div>
            <div className="flex items-center gap-1">
                <Button variant="ghost" size="sm" asChild>
                    <Link href={route('patients.insurance.show', [patient.id, insurance.id])}>View</Link>
                </Button>
                {canUpdate && (
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={route('patients.insurance.edit', [patient.id, insurance.id])}>Edit</Link>
                    </Button>
                )}
                {canUpdate && onMakePrimary && (
                    <Button variant="ghost" size="sm" onClick={onMakePrimary}>
                        <Star className="h-4 w-4" />
                        Set Primary
                    </Button>
                )}
            </div>
        </div>
    );
}
