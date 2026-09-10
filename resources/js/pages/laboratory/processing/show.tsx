import { AssignProcessingDialog } from '@/components/laboratory/assign-processing-dialog';
import { CompleteProcessingDialog } from '@/components/laboratory/complete-processing-dialog';
import { ProcessingTimeline } from '@/components/laboratory/processing-timeline';
import { RejectProcessingDialog } from '@/components/laboratory/reject-processing-dialog';
import { StartProcessingDialog } from '@/components/laboratory/start-processing-dialog';
import { TransferProcessingDialog } from '@/components/laboratory/transfer-processing-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import {
    canAcceptSpecimen,
    canAssignProcessing,
    canCompleteProcessing,
    canRejectDuringProcessing,
    canStartProcessing,
    canTransferProcessing,
    laboratoryOrderPriorityLabel,
    laboratoryOrderPriorityVariant,
    laboratoryProcessingStatusLabel,
    laboratoryProcessingStatusVariant,
    laboratorySampleStatusLabel,
    laboratorySampleStatusVariant,
    laboratorySpecimenContainerLabel,
    laboratorySpecimenTypeLabel,
} from '@/lib/laboratory-status';
import { type BreadcrumbItem } from '@/types';
import { type LaboratoryBenchOption, type LaboratoryOrderUser, type LaboratorySample } from '@/types/laboratory';
import { Head, Link, router } from '@inertiajs/react';

interface LaboratoryProcessingShowProps {
    sample: LaboratorySample;
    benches: LaboratoryBenchOption[];
    technicians: LaboratoryOrderUser[];
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
 * The laboratory processing detail for a single specimen (Phase 4, Step
 * 32, spec §33) — patient identification, specimen details, and every
 * test's own processing record with its own assign/start/complete/
 * transfer actions and timeline. Accept/reject remain whole-specimen
 * actions in the header.
 */
export default function LaboratoryProcessingShow({ sample, benches, technicians }: LaboratoryProcessingShowProps) {
    const { can } = usePermissions();
    const patientName = sample.patient
        ? [sample.patient.first_name, sample.patient.middle_name, sample.patient.last_name].filter(Boolean).join(' ')
        : '—';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Laboratory', href: '/laboratory' },
        { title: 'Processing', href: '/laboratory/processing' },
        { title: sample.sample_number, href: `/laboratory/processing/${sample.id}` },
    ];

    const acceptSpecimen = () => {
        router.post(route('laboratory.processing.accept', sample.id), {}, { preserveScroll: true });
    };

    const records = sample.processing_records ?? [];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Processing — ${sample.sample_number}`} />

            <div className="flex flex-col gap-6 p-4">
                <Card>
                    <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2 space-y-0">
                        <CardTitle className="text-base">{sample.sample_number}</CardTitle>
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge variant={laboratorySampleStatusVariant[sample.status]}>{laboratorySampleStatusLabel[sample.status]}</Badge>

                            {canAcceptSpecimen(sample.status, sample.processing_status) && can('laboratory-processing.accept') && (
                                <Button size="sm" onClick={acceptSpecimen}>
                                    Accept Specimen
                                </Button>
                            )}

                            {canRejectDuringProcessing(sample.status, sample.processing_status) && can('laboratory-processing.reject') && (
                                <RejectProcessingDialog
                                    sampleId={sample.id}
                                    sampleNumber={sample.sample_number}
                                    trigger={
                                        <Button size="sm" variant="destructive">
                                            Reject Specimen
                                        </Button>
                                    }
                                />
                            )}
                        </div>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <dt className="text-muted-foreground">Patient</dt>
                                <dd className="font-medium">
                                    {sample.patient ? (
                                        <Link href={route('patients.show', sample.patient.id)} className="hover:underline">
                                            {patientName}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Patient No. / DOB / Sex</dt>
                                <dd>
                                    {sample.patient?.patient_number ?? '—'} · {formatAge(sample.patient?.date_of_birth ?? null)} ·{' '}
                                    {sample.patient?.gender ?? '—'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Order No.</dt>
                                <dd>{sample.laboratory_order?.order_number ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Priority</dt>
                                <dd>
                                    {sample.laboratory_order && (
                                        <Badge variant={laboratoryOrderPriorityVariant[sample.laboratory_order.priority]}>
                                            {laboratoryOrderPriorityLabel[sample.laboratory_order.priority]}
                                        </Badge>
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Specimen / Container</dt>
                                <dd>
                                    {laboratorySpecimenTypeLabel[sample.specimen_type]}
                                    {sample.container_type ? ` / ${laboratorySpecimenContainerLabel[sample.container_type]}` : ''}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Collected</dt>
                                <dd>{sample.collected_at ? formatDateTime(sample.collected_at) : '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Received</dt>
                                <dd>{sample.received_at ? formatDateTime(sample.received_at) : '—'}</dd>
                            </div>
                            {sample.status === 'rejected' && (
                                <div>
                                    <dt className="text-muted-foreground">Rejection</dt>
                                    <dd>
                                        {sample.rejection_reason} — {sample.rejected_by?.name ?? '—'}
                                    </dd>
                                </div>
                            )}
                        </dl>
                    </CardContent>
                </Card>

                {records.map((record) => (
                    <Card key={record.id}>
                        <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2 space-y-0">
                            <CardTitle className="text-base">
                                {record.item?.test_code} — {record.item?.test_name}
                            </CardTitle>
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge variant={laboratoryProcessingStatusVariant[record.status]}>
                                    {laboratoryProcessingStatusLabel[record.status]}
                                </Badge>

                                {canAssignProcessing(record.status) && can('laboratory-processing.assign') && (
                                    <AssignProcessingDialog
                                        processingId={record.id}
                                        testName={record.item?.test_name ?? 'this test'}
                                        technicians={technicians}
                                        benches={benches}
                                        trigger={
                                            <Button size="sm" variant="outline">
                                                Assign
                                            </Button>
                                        }
                                    />
                                )}

                                {canStartProcessing(record.status) && can('laboratory-processing.start') && (
                                    <StartProcessingDialog
                                        processingId={record.id}
                                        testName={record.item?.test_name ?? 'this test'}
                                        trigger={<Button size="sm">Start</Button>}
                                    />
                                )}

                                {canCompleteProcessing(record.status) && can('laboratory-processing.complete') && (
                                    <CompleteProcessingDialog
                                        processingId={record.id}
                                        testName={record.item?.test_name ?? 'this test'}
                                        trigger={<Button size="sm">Complete</Button>}
                                    />
                                )}

                                {canTransferProcessing(record.status) && can('laboratory-processing.transfer') && (
                                    <TransferProcessingDialog
                                        processingId={record.id}
                                        testName={record.item?.test_name ?? 'this test'}
                                        currentBenchId={record.bench?.id ?? null}
                                        benches={benches}
                                        trigger={
                                            <Button size="sm" variant="outline">
                                                Transfer
                                            </Button>
                                        }
                                    />
                                )}
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <dl className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <dt className="text-muted-foreground">Bench</dt>
                                    <dd>{record.bench?.name ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">Assigned To</dt>
                                    <dd>{record.assigned_to?.name ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">Started</dt>
                                    <dd>{record.started_at ? formatDateTime(record.started_at) : '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">Completed</dt>
                                    <dd>{record.completed_at ? formatDateTime(record.completed_at) : '—'}</dd>
                                </div>
                            </dl>

                            {record.processing_notes && (
                                <p className="text-muted-foreground text-sm">
                                    <span className="font-medium">Notes:</span> {record.processing_notes}
                                </p>
                            )}

                            <div className="border-t pt-3">
                                <h4 className="mb-2 text-sm font-medium">History</h4>
                                <ProcessingTimeline history={record.history} />
                            </div>
                        </CardContent>
                    </Card>
                ))}
            </div>
        </AppLayout>
    );
}
