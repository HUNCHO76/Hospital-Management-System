import { Can } from '@/components/authorization/can';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { effectiveInsuranceStatus, insuranceStatusLabel } from '@/lib/insurance-status';
import { type BreadcrumbItem } from '@/types';
import { type PatientInsurance, type PatientInsurancePatient } from '@/types/patient-insurance';
import { Head, Link } from '@inertiajs/react';
import { Pencil } from 'lucide-react';

interface InsuranceShowProps {
    patient: PatientInsurancePatient;
    insurance: PatientInsurance;
}

export default function InsuranceShow({ patient, insurance }: InsuranceShowProps) {
    const fullName = [patient.first_name, patient.middle_name, patient.last_name].filter(Boolean).join(' ');
    const displayStatus = effectiveInsuranceStatus(insurance.status, insurance.effective_to);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Patients', href: '/patients' },
        { title: fullName, href: `/patients/${patient.id}` },
        { title: 'Insurance', href: `/patients/${patient.id}/insurance/${insurance.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Insurance — ${fullName}`} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{insurance.insurance_provider?.name ?? 'Insurance'}</h1>
                        <p className="text-muted-foreground text-sm">
                            {fullName} · {patient.patient_number}
                        </p>
                    </div>

                    <Can permission="patient-insurance.update">
                        <Button asChild variant="outline">
                            <Link href={route('patients.insurance.edit', [patient.id, insurance.id])}>
                                <Pencil className="h-4 w-4" />
                                Edit
                            </Link>
                        </Button>
                    </Can>
                </div>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle className="text-base">Insurance Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="space-y-2 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Scheme</dt>
                                <dd>{insurance.insurance_scheme?.name ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Membership Number</dt>
                                <dd>{insurance.membership_number ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Policy Number</dt>
                                <dd>{insurance.policy_number ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Card Number</dt>
                                <dd>{insurance.card_number ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Principal Member</dt>
                                <dd>{insurance.principal_member_name ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Relationship</dt>
                                <dd className="capitalize">{insurance.relationship ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Effective</dt>
                                <dd>
                                    {insurance.effective_from ?? '—'} to {insurance.effective_to ?? '—'}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Primary</dt>
                                <dd>{insurance.is_primary ? 'Yes' : 'No'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Status</dt>
                                <dd>
                                    <Badge variant={displayStatus === 'active' ? 'default' : 'secondary'}>
                                        {insuranceStatusLabel[displayStatus]}
                                    </Badge>
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Verification</dt>
                                <dd className="capitalize">{insurance.verification_status}</dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
