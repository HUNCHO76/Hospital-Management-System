import { Can } from '@/components/authorization/can';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import {
    radiologyBodySiteLabel,
    radiologyContrastRequirementLabel,
    radiologyContrastTypeLabel,
    radiologyLateralityLabel,
    radiologyModalityLabel,
    radiologyPreparationTypeLabel,
    radiologyProcedureStatusVariant,
} from '@/lib/radiology-status';
import { type BreadcrumbItem } from '@/types';
import { type RadiologyProcedure } from '@/types/radiology';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Pencil, XCircle } from 'lucide-react';

interface RadiologyCatalogShowProps {
    procedure: RadiologyProcedure;
    isReferenced: boolean;
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

export default function RadiologyCatalogShow({ procedure, isReferenced }: RadiologyCatalogShowProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Radiology', href: '/radiology' },
        { title: 'Catalog', href: '/radiology/catalog' },
        { title: procedure.name, href: `/radiology/catalog/${procedure.id}` },
    ];

    const toggleStatus = () => {
        const action = procedure.status === 'active' ? 'deactivate' : 'activate';
        router.post(route(`radiology-catalog.${action}`, procedure.id), {}, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={procedure.name} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{procedure.name}</h1>
                        <p className="text-muted-foreground text-sm">{procedure.code}</p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant={radiologyProcedureStatusVariant[procedure.status]}>
                            {procedure.status === 'active' ? 'Active' : 'Inactive'}
                        </Badge>

                        <Can permission="radiology-procedures.update">
                            <Button asChild variant="outline" size="sm">
                                <Link href={route('radiology-catalog.edit', procedure.id)}>
                                    <Pencil className="h-4 w-4" />
                                    Edit
                                </Link>
                            </Button>
                        </Can>

                        {procedure.status === 'active' ? (
                            <Can permission="radiology-procedures.deactivate">
                                <Button variant="destructive" size="sm" onClick={toggleStatus}>
                                    <XCircle className="h-4 w-4" />
                                    Deactivate
                                </Button>
                            </Can>
                        ) : (
                            <Can permission="radiology-procedures.activate">
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
                        This procedure has been referenced by at least one radiology order — its code can no longer be changed.
                    </p>
                )}

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Identity</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <Row label="Description" value={procedure.description} />
                                <Row label="Category / Modality" value={procedure.modality ? radiologyModalityLabel[procedure.modality] : null} />
                                <Row
                                    label="Body Site(s)"
                                    value={
                                        procedure.body_sites?.length ? procedure.body_sites.map((s) => radiologyBodySiteLabel[s]).join(', ') : null
                                    }
                                />
                                <Row label="Department" value={procedure.department?.name} />
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Laterality & Contrast</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <Row label="Laterality" value={radiologyLateralityLabel[procedure.laterality]} />
                                <Row label="Contrast" value={radiologyContrastRequirementLabel[procedure.contrast_requirement]} />
                                <Row
                                    label="Contrast Type"
                                    value={procedure.contrast_type ? radiologyContrastTypeLabel[procedure.contrast_type] : null}
                                />
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Patient Preparation</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <Row label="Preparation Type" value={radiologyPreparationTypeLabel[procedure.preparation_type]} />
                                <Row label="Instructions" value={procedure.preparation_instructions} />
                                <Row
                                    label="Turnaround Time"
                                    value={procedure.turnaround_time_minutes ? `${procedure.turnaround_time_minutes} minutes` : null}
                                />
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Billing / NHIF & Audit</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <Row label="NHIF Code" value={procedure.nhif_code} />
                                <Row label="Price" value={procedure.price} />
                                <Row label="Created By" value={procedure.created_by?.name} />
                                <Row label="Created At" value={formatDateTime(procedure.created_at)} />
                                <Row label="Updated By" value={procedure.updated_by?.name} />
                                <Row label="Updated At" value={formatDateTime(procedure.updated_at)} />
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
