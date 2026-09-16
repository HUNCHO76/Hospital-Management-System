import { Can } from '@/components/authorization/can';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { medicationFormLabel, medicationRouteLabel, medicationStatusVariant } from '@/lib/medication-status';
import { type BreadcrumbItem } from '@/types';
import { type Medication } from '@/types/medication';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Pencil, XCircle } from 'lucide-react';

interface MedicationsShowProps {
    medication: Medication;
    isReferenced: boolean;
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

export default function MedicationsShow({ medication, isReferenced }: MedicationsShowProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Pharmacy', href: '/pharmacy' },
        { title: 'Medications', href: '/pharmacy/medications' },
        { title: medication.generic_name, href: `/pharmacy/medications/${medication.id}` },
    ];

    const toggleStatus = () => {
        const action = medication.status === 'active' ? 'deactivate' : 'activate';
        router.post(route(`medications.${action}`, medication.id), {}, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={medication.generic_name} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">
                            {medication.generic_name}
                            {medication.brand_name && <span className="text-muted-foreground font-normal"> ({medication.brand_name})</span>}
                        </h1>
                        <p className="text-muted-foreground text-sm">{medication.code}</p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant={medicationStatusVariant[medication.status]}>{medication.status === 'active' ? 'Active' : 'Inactive'}</Badge>

                        <Can permission="medications.update">
                            <Button asChild variant="outline" size="sm">
                                <Link href={route('medications.edit', medication.id)}>
                                    <Pencil className="h-4 w-4" />
                                    Edit
                                </Link>
                            </Button>
                        </Can>

                        {medication.status === 'active' ? (
                            <Can permission="medications.deactivate">
                                <Button variant="destructive" size="sm" onClick={toggleStatus}>
                                    <XCircle className="h-4 w-4" />
                                    Deactivate
                                </Button>
                            </Can>
                        ) : (
                            <Can permission="medications.activate">
                                <Button size="sm" onClick={toggleStatus}>
                                    <CheckCircle2 className="h-4 w-4" />
                                    Activate
                                </Button>
                            </Can>
                        )}
                    </div>
                </div>

                {isReferenced && (
                    <p className="text-muted-foreground text-sm">
                        This medication has been referenced by at least one prescription — its code can no longer be changed.
                    </p>
                )}

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Identity</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <Row label="Description" value={medication.description} />
                                <Row label="Category" value={medication.category} />
                                <Row label="Strength" value={medication.strength} />
                                <Row label="Dispensing Unit" value={medication.unit} />
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Form &amp; Route</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <Row label="Form" value={medication.form ? medicationFormLabel[medication.form] : null} />
                                <Row label="Route" value={medication.route ? medicationRouteLabel[medication.route] : null} />
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Billing / NHIF</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <Row label="NHIF Code" value={medication.nhif_code} />
                                <Row label="Price" value={medication.price} />
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Audit</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <Row label="Created By" value={medication.created_by?.UserName} />
                                <Row label="Created At" value={formatDateTime(medication.created_at)} />
                                <Row label="Updated By" value={medication.updated_by?.UserName} />
                                <Row label="Updated At" value={formatDateTime(medication.updated_at)} />
                            </dl>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}

function Row({ label, value }: { label: string; value: string | null | undefined }) {
    return (
        <div className="flex justify-between gap-4">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right">{value || '—'}</dd>
        </div>
    );
}
