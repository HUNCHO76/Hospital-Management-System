import { CancelSampleDialog } from '@/components/laboratory/cancel-sample-dialog';
import { CollectSampleDialog } from '@/components/laboratory/collect-sample-dialog';
import { RecollectSampleDialog } from '@/components/laboratory/recollect-sample-dialog';
import { RegisterSampleForm } from '@/components/laboratory/register-sample-form';
import { RejectProcessingDialog } from '@/components/laboratory/reject-processing-dialog';
import { RejectSampleDialog } from '@/components/laboratory/reject-sample-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import {
    canAcceptSpecimen,
    canCancelSample,
    canCollectSample,
    canLabelSample,
    canReceiveSample,
    canRecollectSample,
    canRejectDuringProcessing,
    canRejectSample,
    laboratoryOrderPriorityLabel,
    laboratoryOrderPriorityVariant,
    laboratoryOrderStatusLabel,
    laboratoryOrderStatusVariant,
    laboratoryProcessingStatusLabel,
    laboratoryProcessingStatusVariant,
    laboratorySampleStatusLabel,
    laboratorySampleStatusVariant,
    laboratorySpecimenContainerLabel,
    laboratorySpecimenTypeLabel,
} from '@/lib/laboratory-status';
import { type BreadcrumbItem } from '@/types';
import { type LaboratoryOrder, type LaboratorySample, type LaboratorySpecimenContainer, type LaboratorySpecimenType } from '@/types/laboratory';
import { Head, Link, router } from '@inertiajs/react';
import { Printer, XCircle } from 'lucide-react';

interface LaboratoryCollectionShowProps {
    order: LaboratoryOrder;
    samples: LaboratorySample[];
    specimenTypes: LaboratorySpecimenType[];
    specimenContainers: LaboratorySpecimenContainer[];
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

function formatAge(dateOfBirth: string | null): string {
    if (!dateOfBirth) {
        return '—';
    }

    const years = Math.floor((Date.now() - new Date(dateOfBirth).getTime()) / (365.25 * 24 * 60 * 60 * 1000));

    return `${years}y`;
}

/**
 * The specimen collection detail for a single order (Phase 4, Step 31,
 * spec §26-27): patient identification, every test's own catalog specimen
 * requirement, every specimen registered so far, and the form to register
 * a new one. Reached only from the collection worklist — this is a
 * laboratory-department screen, not part of the clinical encounter page.
 */
export default function LaboratoryCollectionShow({
    order,
    samples,
    specimenTypes,
    specimenContainers,
    orderFinancialStatus = 'authorized',
    patientAccountId = null,
}: LaboratoryCollectionShowProps & { orderFinancialStatus?: string; patientAccountId?: number | null }) {
    const { can } = usePermissions();
    const isPaymentRequired = orderFinancialStatus === 'payment_required';
    const patientName = order.patient
        ? [order.patient.first_name, order.patient.middle_name, order.patient.last_name].filter(Boolean).join(' ')
        : '—';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Laboratory', href: '/laboratory' },
        { title: 'Sample Collection', href: '/laboratory/collection' },
        { title: order.order_number, href: `/laboratory/collection/${order.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Collect — ${order.order_number}`} />

            <div className="flex flex-col gap-6 p-4">
                {isPaymentRequired && (
                    <div className="rounded-md border border-amber-500/50 bg-amber-500/10 p-4 text-amber-800 dark:text-amber-300">
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <div className="space-y-1">
                                <div className="flex items-center gap-2 font-bold text-sm">
                                    <span>Financial Status: PAYMENT REQUIRED</span>
                                    <Badge variant="destructive" className="bg-amber-600 dark:bg-amber-700 text-white">
                                        PAYMENT REQUIRED
                                    </Badge>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Specimen collection is blocked until order charges are settled at the cashier.
                                </p>
                            </div>
                            {patientAccountId && (
                                <Button size="sm" variant="default" className="bg-amber-700 hover:bg-amber-800 text-white" asChild>
                                    <Link href={route('billing.accounts.show', patientAccountId)}>
                                        View Bill / Proceed to Payment
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </div>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Patient Identification</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <dt className="text-muted-foreground">Patient</dt>
                                <dd className="font-medium">{patientName}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Patient Number</dt>
                                <dd>{order.patient?.patient_number ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Date of Birth / Age</dt>
                                <dd>
                                    {order.patient?.date_of_birth ?? '—'} ({formatAge(order.patient?.date_of_birth ?? null)})
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Sex</dt>
                                <dd>{order.patient?.gender ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Order Number</dt>
                                <dd>{order.order_number}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Priority</dt>
                                <dd>
                                    <Badge variant={laboratoryOrderPriorityVariant[order.priority]}>
                                        {laboratoryOrderPriorityLabel[order.priority]}
                                    </Badge>
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between gap-2 space-y-0">
                        <CardTitle className="text-base">Tests</CardTitle>
                        <Badge variant={laboratoryOrderStatusVariant[order.status]}>{laboratoryOrderStatusLabel[order.status]}</Badge>
                    </CardHeader>
                    <CardContent>
                        <ul className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                            {order.items.map((item) => (
                                <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 py-2 first:pt-0 last:pb-0">
                                    <span className={item.status === 'cancelled' ? 'text-muted-foreground line-through' : 'font-medium'}>
                                        {item.test_code} — {item.test_name}
                                    </span>
                                    <div className="flex items-center gap-2 text-xs">
                                        <span className="text-muted-foreground">
                                            {item.specimen_type ? laboratorySpecimenTypeLabel[item.specimen_type] : 'Not configured'}
                                            {item.specimen_container ? ` / ${laboratorySpecimenContainerLabel[item.specimen_container]}` : ''}
                                        </span>
                                        <Badge variant={laboratoryOrderStatusVariant[item.status]}>{laboratoryOrderStatusLabel[item.status]}</Badge>
                                    </div>
                                </li>
                            ))}
                        </ul>

                        {can('laboratory-samples.collect') && !isPaymentRequired && (
                            <RegisterSampleForm orderId={order.id} items={order.items} />
                        )}
                        {isPaymentRequired && (
                            <div className="mt-4 rounded-md border border-amber-500/30 bg-amber-500/5 p-3 text-xs text-amber-700 dark:text-amber-400">
                                Sample collection registration is disabled because this order requires payment clearance.
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Specimens</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {samples.length === 0 ? (
                            <p className="text-muted-foreground text-sm">No specimens registered yet.</p>
                        ) : (
                            <ul className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                                {samples.map((sample) => (
                                    <li key={sample.id} className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Link href={route('laboratory.samples.show', sample.id)} className="font-medium hover:underline">
                                                    {sample.sample_number}
                                                </Link>
                                                <Badge variant={laboratorySampleStatusVariant[sample.status]}>
                                                    {laboratorySampleStatusLabel[sample.status]}
                                                </Badge>
                                                {sample.processing_status && (
                                                    <Badge variant={laboratoryProcessingStatusVariant[sample.processing_status]}>
                                                        {laboratoryProcessingStatusLabel[sample.processing_status]}
                                                    </Badge>
                                                )}
                                                <span className="text-muted-foreground text-xs">
                                                    {laboratorySpecimenTypeLabel[sample.specimen_type]}
                                                    {sample.container_type ? ` / ${laboratorySpecimenContainerLabel[sample.container_type]}` : ''}
                                                </span>
                                            </div>

                                            <div className="flex flex-wrap items-center gap-2">
                                                {canLabelSample(sample.status) && can('laboratory-samples.label') && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            router.post(
                                                                route('laboratory.samples.label', sample.id),
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                    onSuccess: () =>
                                                                        router.visit(
                                                                            route('laboratory.samples.label-view', sample.id),
                                                                        ),
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <Printer className="h-4 w-4" />
                                                        Label
                                                    </Button>
                                                )}

                                                {canCollectSample(sample.status) && can('laboratory-samples.collect') && (
                                                    <CollectSampleDialog
                                                        sampleId={sample.id}
                                                        sampleNumber={sample.sample_number}
                                                        trigger={<Button size="sm">Collect</Button>}
                                                    />
                                                )}

                                                {canReceiveSample(sample.status) && can('laboratory-samples.receive') && (
                                                    <Button
                                                        size="sm"
                                                        onClick={() =>
                                                            router.post(route('laboratory.samples.receive', sample.id), {}, { preserveScroll: true })
                                                        }
                                                    >
                                                        Receive
                                                    </Button>
                                                )}

                                                {canAcceptSpecimen(sample.status, sample.processing_status) &&
                                                    can('laboratory-processing.accept') && (
                                                        <Button
                                                            size="sm"
                                                            onClick={() =>
                                                                router.post(
                                                                    route('laboratory.processing.accept', sample.id),
                                                                    {},
                                                                    { preserveScroll: true },
                                                                )
                                                            }
                                                        >
                                                            Accept
                                                        </Button>
                                                    )}

                                                {canRejectDuringProcessing(sample.status, sample.processing_status) &&
                                                    can('laboratory-processing.reject') && (
                                                        <RejectProcessingDialog
                                                            sampleId={sample.id}
                                                            sampleNumber={sample.sample_number}
                                                            trigger={
                                                                <Button size="sm" variant="destructive">
                                                                    Reject
                                                                </Button>
                                                            }
                                                        />
                                                    )}

                                                {canRejectSample(sample.status) && can('laboratory-samples.reject') && (
                                                    <RejectSampleDialog
                                                        sampleId={sample.id}
                                                        sampleNumber={sample.sample_number}
                                                        trigger={
                                                            <Button size="sm" variant="destructive">
                                                                Reject
                                                            </Button>
                                                        }
                                                    />
                                                )}

                                                {sample.processing_status &&
                                                    sample.processing_status !== 'received' &&
                                                    can('laboratory-processing.view') && (
                                                        <Button asChild size="sm" variant="outline">
                                                            <Link href={route('laboratory.processing.show', sample.id)}>View Processing</Link>
                                                        </Button>
                                                    )}

                                                {canRecollectSample(sample.status) && can('laboratory-samples.recollect') && (
                                                    <RecollectSampleDialog
                                                        sampleId={sample.id}
                                                        sampleNumber={sample.sample_number}
                                                        defaultSpecimenType={sample.specimen_type}
                                                        defaultContainerType={sample.container_type}
                                                        specimenTypes={specimenTypes}
                                                        specimenContainers={specimenContainers}
                                                        trigger={<Button size="sm">Recollect</Button>}
                                                    />
                                                )}

                                                {canCancelSample(sample.status) && can('laboratory-samples.cancel') && (
                                                    <CancelSampleDialog
                                                        sampleId={sample.id}
                                                        sampleNumber={sample.sample_number}
                                                        trigger={
                                                            <Button size="sm" variant="ghost">
                                                                <XCircle className="h-4 w-4" />
                                                                Cancel
                                                            </Button>
                                                        }
                                                    />
                                                )}
                                            </div>
                                        </div>

                                        <div className="text-muted-foreground flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                            <span>Tests: {sample.items.map((i) => i.test_code).join(', ')}</span>
                                            {sample.collected_at && (
                                                <span>
                                                    Collected {formatDateTime(sample.collected_at)} by {sample.collected_by?.name ?? '—'}
                                                </span>
                                            )}
                                            {sample.received_at && (
                                                <span>
                                                    Received {formatDateTime(sample.received_at)} by {sample.received_by?.name ?? '—'}
                                                </span>
                                            )}
                                            {sample.status === 'rejected' && (
                                                <span>
                                                    Rejected: {sample.rejection_reason} {sample.rejection_notes ? `— ${sample.rejection_notes}` : ''}
                                                </span>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
