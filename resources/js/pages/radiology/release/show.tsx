import { RadiologyExaminationSummary } from '@/components/radiology/radiology-examination-summary';
import { RadiologyReleaseDialog } from '@/components/radiology/radiology-release-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import {
    radiologyBodySiteLabel,
    radiologyModalityLabel,
    radiologyOrderPriorityLabel,
    radiologyReportStatusLabel,
    radiologyReportStatusVariant,
} from '@/lib/radiology-status';
import { type BreadcrumbItem } from '@/types';
import { type RadiologyReport } from '@/types/radiology';
import { Head, Link } from '@inertiajs/react';
import { History } from 'lucide-react';

interface RadiologyReleaseShowProps {
    report: RadiologyReport;
    canRelease: boolean;
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

/**
 * Radiology result release page (Phase 5, Step 44, Part 7): the complete
 * clinical context — patient/encounter/order/procedure, the Step 41
 * examination summary, the verified report itself, and the verification
 * metadata — all read-only here (release changes workflow state, never
 * clinical content — Part 34/38). The Release action only renders when the
 * report is actually VERIFIED and this user holds the release permission
 * (`canRelease`, computed server-side from RadiologyReportPolicy); the
 * backend re-checks on every write regardless.
 */
export default function RadiologyReleaseShow({ report, canRelease }: RadiologyReleaseShowProps) {
    const patientName = report.patient
        ? [report.patient.first_name, report.patient.middle_name, report.patient.last_name].filter(Boolean).join(' ')
        : '—';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Radiology', href: '/radiology' },
        { title: 'Release', href: '/radiology/release' },
        { title: report.report_number, href: `/radiology/release/${report.id}` },
    ];

    const isActionable = report.status === 'verified';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Release ${report.report_number}`} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{report.report_number}</h1>
                        <p className="text-muted-foreground text-sm">
                            {patientName} — {report.item?.procedure_name}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Badge variant={radiologyReportStatusVariant[report.status]}>{radiologyReportStatusLabel[report.status]}</Badge>
                        <Button asChild variant="outline" size="sm">
                            <Link href={route('radiology-results.history', report.id)}>
                                <History className="h-4 w-4" />
                                History
                            </Link>
                        </Button>
                        {isActionable && canRelease && (
                            <RadiologyReleaseDialog report={report} trigger={<Button>Release Result</Button>} />
                        )}
                    </div>
                </div>

                {report.status === 'released' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Released</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Released By</dt>
                                    <dd>{report.released_by?.name ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Released At</dt>
                                    <dd>{formatDateTime(report.released_at)}</dd>
                                </div>
                                {report.release_notes && (
                                    <div>
                                        <dt className="text-muted-foreground mb-1">Release Notes</dt>
                                        <dd className="whitespace-pre-wrap">{report.release_notes}</dd>
                                    </div>
                                )}
                            </dl>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Verification</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="space-y-2 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Verified By</dt>
                                <dd>{report.verifying_practitioner?.name ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">Verified At</dt>
                                <dd>{formatDateTime(report.verified_at)}</dd>
                            </div>
                            {report.verification_notes && (
                                <div>
                                    <dt className="text-muted-foreground mb-1">Verification Notes</dt>
                                    <dd className="whitespace-pre-wrap">{report.verification_notes}</dd>
                                </div>
                            )}
                        </dl>
                    </CardContent>
                </Card>

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Patient &amp; Encounter</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Patient</dt>
                                    <dd>
                                        {report.patient ? (
                                            <Link href={route('patients.show', report.patient.id)} className="font-medium hover:underline">
                                                {patientName}
                                            </Link>
                                        ) : (
                                            '—'
                                        )}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Patient Number</dt>
                                    <dd>{report.patient?.patient_number ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Date of Birth</dt>
                                    <dd>{report.patient?.date_of_birth ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Sex</dt>
                                    <dd>{report.patient?.gender ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Encounter</dt>
                                    <dd>{report.encounter?.encounter_number ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Visit Date</dt>
                                    <dd>{formatDateTime(report.encounter?.started_at ?? null)}</dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Order &amp; Procedure</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Order Number</dt>
                                    <dd>{report.order?.order_number ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Priority</dt>
                                    <dd>{report.order ? radiologyOrderPriorityLabel[report.order.priority] : '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Ordering Practitioner</dt>
                                    <dd>{report.order?.ordered_by?.name ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Procedure</dt>
                                    <dd className="text-right">
                                        {report.item?.procedure_code} — {report.item?.procedure_name}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Modality</dt>
                                    <dd>{report.examination?.modality ? radiologyModalityLabel[report.examination.modality] : '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Body Site</dt>
                                    <dd className="text-right">
                                        {report.item?.body_site_snapshot?.map((site) => radiologyBodySiteLabel[site]).join(', ') || '—'}
                                    </dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Clinical Indication</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="text-sm whitespace-pre-wrap">
                            {report.order?.clinical_indication || 'No clinical indication recorded on the order.'}
                        </p>
                    </CardContent>
                </Card>

                <RadiologyExaminationSummary report={report} />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Report</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="space-y-4 text-sm">
                            <div>
                                <dt className="text-muted-foreground mb-1">Technique</dt>
                                <dd className="whitespace-pre-wrap">{report.technique || '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground mb-1">Findings</dt>
                                <dd className="whitespace-pre-wrap">{report.findings || '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground mb-1">Impression</dt>
                                <dd className="whitespace-pre-wrap">{report.impression || '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground mb-1">Recommendations</dt>
                                <dd className="whitespace-pre-wrap">{report.recommendations || '—'}</dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <div className="text-muted-foreground text-sm">
                    {report.reporting_practitioner?.name && <p>Reporting practitioner: {report.reporting_practitioner.name}</p>}
                </div>
            </div>
        </AppLayout>
    );
}
