import { Can } from '@/components/authorization/can';
import { LaboratoryTestParameterSection } from '@/components/laboratory/laboratory-test-parameter-section';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import {
    laboratoryOrderPriorityLabel,
    laboratoryResultTypeLabel,
    laboratorySpecimenContainerLabel,
    laboratorySpecimenTypeLabel,
    laboratoryTestStatusVariant,
} from '@/lib/laboratory-status';
import { type BreadcrumbItem } from '@/types';
import { type LaboratoryTest } from '@/types/laboratory';
import { usePermissions } from '@/hooks/use-permissions';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Pencil, XCircle } from 'lucide-react';

interface LaboratoryTestShowProps {
    test: LaboratoryTest;
    isReferenced: boolean;
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

export default function LaboratoryTestShow({ test, isReferenced }: LaboratoryTestShowProps) {
    const { can } = usePermissions();
    const canManageParameters = can('laboratory-test-parameters.manage');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Laboratory', href: '/laboratory' },
        { title: 'Test Catalog', href: '/laboratory/tests' },
        { title: test.name, href: `/laboratory/tests/${test.id}` },
    ];

    const toggleStatus = () => {
        const action = test.status === 'active' ? 'deactivate' : 'activate';
        router.post(route(`laboratory-tests.${action}`, test.id), {}, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={test.name} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{test.name}</h1>
                        <p className="text-muted-foreground text-sm">{test.code}</p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant={laboratoryTestStatusVariant[test.status]}>{test.status === 'active' ? 'Active' : 'Inactive'}</Badge>

                        <Can permission="laboratory-tests.update">
                            <Button asChild variant="outline" size="sm">
                                <Link href={route('laboratory-tests.edit', test.id)}>
                                    <Pencil className="h-4 w-4" />
                                    Edit
                                </Link>
                            </Button>
                        </Can>

                        {test.status === 'active' ? (
                            <Can permission="laboratory-tests.deactivate">
                                <Button variant="destructive" size="sm" onClick={toggleStatus}>
                                    <XCircle className="h-4 w-4" />
                                    Deactivate
                                </Button>
                            </Can>
                        ) : (
                            <Can permission="laboratory-tests.activate">
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
                        This test has been referenced by at least one laboratory order — its code can no longer be changed.
                    </p>
                )}

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Identity</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <Row label="Short Name" value={test.short_name} />
                                <Row label="Description" value={test.description} />
                                <Row label="Category" value={test.category?.name} />
                                <Row label="Department" value={test.department?.name} />
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Specimen</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <Row label="Specimen Type" value={test.specimen_type ? laboratorySpecimenTypeLabel[test.specimen_type] : null} />
                                <Row
                                    label="Container"
                                    value={test.specimen_container ? laboratorySpecimenContainerLabel[test.specimen_container] : null}
                                />
                                <Row label="Collection Notes" value={test.collection_notes} />
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Result Configuration</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <Row label="Result Type" value={test.result_type ? laboratoryResultTypeLabel[test.result_type] : null} />
                                <Row label="Unit" value={test.unit} />
                                <Row label="Reference Range" value={test.reference_range} />
                                <Row label="Result Options" value={test.result_options ? test.result_options.join(', ') : null} />
                                <Row
                                    label="Turnaround Time"
                                    value={test.turnaround_time_minutes ? `${test.turnaround_time_minutes} minutes` : null}
                                />
                                <Row label="Default Priority" value={laboratoryOrderPriorityLabel[test.default_priority]} />
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Lifecycle & Mapping</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <Row label="Effective From" value={test.effective_from} />
                                <Row label="Effective To" value={test.effective_to} />
                                <Row label="NHIF Code" value={test.nhif_code} />
                                <Row label="Price" value={test.price} />
                                <Row label="Created By" value={test.created_by?.name} />
                                <Row label="Created At" value={formatDateTime(test.created_at)} />
                                <Row label="Updated By" value={test.updated_by?.name} />
                                <Row label="Updated At" value={formatDateTime(test.updated_at)} />
                            </dl>
                        </CardContent>
                    </Card>
                </div>

                <LaboratoryTestParameterSection test={test} canManage={canManageParameters} />
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
