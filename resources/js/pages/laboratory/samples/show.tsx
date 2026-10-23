import { CancelSampleDialog } from '@/components/laboratory/cancel-sample-dialog';
import { CollectSampleDialog } from '@/components/laboratory/collect-sample-dialog';
import { RecollectSampleDialog } from '@/components/laboratory/recollect-sample-dialog';
import { RejectProcessingDialog } from '@/components/laboratory/reject-processing-dialog';
import { RejectSampleDialog } from '@/components/laboratory/reject-sample-dialog';
import { SampleTimeline } from '@/components/laboratory/sample-timeline';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import {
    canAcceptSpecimen,
    canCancelSample,
    canCollectSample,
    canReceiveSample,
    canRecollectSample,
    canRejectDuringProcessing,
    canRejectSample,
    laboratoryProcessingStatusLabel,
    laboratoryProcessingStatusVariant,
    laboratorySampleRejectionReasonLabel,
    laboratorySampleStatusLabel,
    laboratorySampleStatusVariant,
    laboratorySpecimenContainerLabel,
    laboratorySpecimenSourceLabel,
    laboratorySpecimenTypeLabel,
} from '@/lib/laboratory-status';
import { type BreadcrumbItem } from '@/types';
import { type LaboratorySample } from '@/types/laboratory';
import { Head, Link, router } from '@inertiajs/react';
import { Printer } from 'lucide-react';

interface LaboratorySampleShowProps {
    sample: LaboratorySample;
}

/**
 * Standalone specimen detail (spec §26/54): the full record and its
 * traceability timeline, reachable from the collection screen, the sample's
 * own label page, or a direct link.
 */
export default function LaboratorySampleShow({ sample }: LaboratorySampleShowProps) {
    const { can } = usePermissions();
    const patientName = sample.patient
        ? [sample.patient.first_name, sample.patient.middle_name, sample.patient.last_name].filter(Boolean).join(' ')
        : '—';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Laboratory', href: '/laboratory' },
        { title: 'Sample Collection', href: '/laboratory/collection' },
        { title: sample.sample_number, href: `/laboratory/samples/${sample.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Specimen ${sample.sample_number}`} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{sample.sample_number}</h1>
                        <p className="text-muted-foreground text-sm">{patientName}</p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant={laboratorySampleStatusVariant[sample.status]}>{laboratorySampleStatusLabel[sample.status]}</Badge>

                        {sample.processing_status && (
                            <Badge variant={laboratoryProcessingStatusVariant[sample.processing_status]}>
                                {laboratoryProcessingStatusLabel[sample.processing_status]}
                            </Badge>
                        )}

                        <Button asChild variant="outline">
                            <Link href={route('laboratory.samples.label-view', sample.id)}>
                                <Printer className="h-4 w-4" />
                                Label
                            </Link>
                        </Button>

                        {canCollectSample(sample.status) && can('laboratory-samples.collect') && (
                            <CollectSampleDialog
                                sampleId={sample.id}
                                sampleNumber={sample.sample_number}
                                initialSpecimenSource={sample.specimen_source ?? null}
                                trigger={<Button>Collect</Button>}
                            />
                        )}

                        {canReceiveSample(sample.status) && can('laboratory-samples.receive') && (
                            <Button onClick={() => router.post(route('laboratory.samples.receive', sample.id), {}, { preserveScroll: true })}>
                                Receive
                            </Button>
                        )}

                        {canAcceptSpecimen(sample.status, sample.processing_status) && can('laboratory-processing.accept') && (
                            <Button onClick={() => router.post(route('laboratory.processing.accept', sample.id), {}, { preserveScroll: true })}>
                                Accept
                            </Button>
                        )}

                        {canRejectDuringProcessing(sample.status, sample.processing_status) && can('laboratory-processing.reject') && (
                            <RejectProcessingDialog
                                sampleId={sample.id}
                                sampleNumber={sample.sample_number}
                                trigger={<Button variant="destructive">Reject</Button>}
                            />
                        )}

                        {canRejectSample(sample.status) && can('laboratory-samples.reject') && (
                            <RejectSampleDialog
                                sampleId={sample.id}
                                sampleNumber={sample.sample_number}
                                trigger={<Button variant="destructive">Reject</Button>}
                            />
                        )}

                        {sample.processing_status && sample.processing_status !== 'received' && can('laboratory-processing.view') && (
                            <Button asChild variant="outline">
                                <Link href={route('laboratory.processing.show', sample.id)}>View Processing</Link>
                            </Button>
                        )}

                        {canCancelSample(sample.status) && can('laboratory-samples.cancel') && (
                            <CancelSampleDialog
                                sampleId={sample.id}
                                sampleNumber={sample.sample_number}
                                trigger={<Button variant="ghost">Cancel</Button>}
                            />
                        )}
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Specimen Details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Specimen Type</dt>
                                    <dd>{laboratorySpecimenTypeLabel[sample.specimen_type]}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Container</dt>
                                    <dd>{sample.container_type ? laboratorySpecimenContainerLabel[sample.container_type] : '—'}</dd>
                                </div>
                                {sample.specimen_source && (
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Specimen Source</dt>
                                        <dd className="capitalize font-medium">
                                            {laboratorySpecimenSourceLabel[sample.specimen_source] ?? sample.specimen_source}
                                        </dd>
                                    </div>
                                )}
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Collection Site</dt>
                                    <dd>{sample.collection_site ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Collected By</dt>
                                    <dd>{sample.collected_by?.name ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Received By</dt>
                                    <dd>{sample.received_by?.name ?? '—'}</dd>
                                </div>
                                {sample.status === 'rejected' && (
                                    <>
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-muted-foreground">Rejected By</dt>
                                            <dd>{sample.rejected_by?.name ?? '—'}</dd>
                                        </div>
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-muted-foreground">Rejection Reason</dt>
                                            <dd>{sample.rejection_reason ? laboratorySampleRejectionReasonLabel[sample.rejection_reason] : '—'}</dd>
                                        </div>
                                        {sample.rejection_notes && (
                                            <div className="flex justify-between gap-4">
                                                <dt className="text-muted-foreground">Notes</dt>
                                                <dd className="text-right">{sample.rejection_notes}</dd>
                                            </div>
                                        )}
                                        {canRecollectSample(sample.status) && can('laboratory-samples.recollect') && (
                                            <div className="flex justify-end pt-2">
                                                <RecollectSampleDialog
                                                    sampleId={sample.id}
                                                    sampleNumber={sample.sample_number}
                                                    defaultSpecimenType={sample.specimen_type}
                                                    defaultContainerType={sample.container_type}
                                                    specimenTypes={[sample.specimen_type]}
                                                    specimenContainers={sample.container_type ? [sample.container_type] : []}
                                                    trigger={<Button size="sm">Recollect</Button>}
                                                />
                                            </div>
                                        )}
                                    </>
                                )}
                                {sample.recollection_of && (
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Recollection Of</dt>
                                        <dd>
                                            <Link href={route('laboratory.samples.show', sample.recollection_of.id)} className="hover:underline">
                                                {sample.recollection_of.sample_number}
                                            </Link>
                                        </dd>
                                    </div>
                                )}
                                {sample.recollections.length > 0 && (
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Recollected As</dt>
                                        <dd className="space-x-2">
                                            {sample.recollections.map((r) => (
                                                <Link key={r.id} href={route('laboratory.samples.show', r.id)} className="hover:underline">
                                                    {r.sample_number}
                                                </Link>
                                            ))}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Patient & Order</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Patient</dt>
                                    <dd>
                                        {sample.patient ? (
                                            <Link href={route('patients.show', sample.patient.id)} className="font-medium hover:underline">
                                                {patientName}
                                            </Link>
                                        ) : (
                                            '—'
                                        )}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Patient Number</dt>
                                    <dd>{sample.patient?.patient_number ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Order</dt>
                                    <dd>
                                        {sample.laboratory_order ? (
                                            <Link href={route('laboratory.collection.show', sample.laboratory_order_id)} className="hover:underline">
                                                {sample.laboratory_order.order_number}
                                            </Link>
                                        ) : (
                                            '—'
                                        )}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Tests</dt>
                                    <dd className="text-right">{sample.items.map((item) => item.test_code).join(', ')}</dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">History</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <SampleTimeline history={sample.history} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
