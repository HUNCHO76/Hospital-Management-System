import { BrandMark } from '@/components/brand-mark';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { brand } from '@/lib/brand';
import { laboratoryAbnormalFlagLabel, laboratoryOrderPriorityLabel, laboratorySpecimenTypeLabel } from '@/lib/laboratory-status';
import { type LaboratoryReport, type LaboratoryReportResultValueEntry, type LaboratoryReportVersionSummary } from '@/types/laboratory';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Download, Printer } from 'lucide-react';
import React from 'react';

interface LaboratoryReportShowProps {
    report: LaboratoryReport;
    versions: LaboratoryReportVersionSummary[];
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

function formatAge(dateOfBirth: string | null): string {
    if (!dateOfBirth) {
        return '—';
    }

    const years = Math.floor((Date.now() - new Date(dateOfBirth).getTime()) / (365.25 * 24 * 60 * 60 * 1000));

    return `${years}y`;
}

/**
 * The laboratory report — one printable/downloadable document per released
 * version (Phase 4, Step 35, spec Part 19/38). Deliberately bypasses
 * AppLayout entirely, mirroring the exact precedent LaboratorySampleLabel
 * already established: the sidebar/nav chrome has no place on a printed
 * clinical document. This app has no PDF-generation dependency, so "Print"
 * and "Download" both stamp their own server-side audit timestamp first,
 * then trigger the browser's own native print dialog against this same
 * template — "Save as PDF" in that dialog is this app's download mechanism,
 * per the same reasoning LaboratorySampleLabel already established for
 * specimen labels.
 */
export default function LaboratoryReportShow({ report, versions }: LaboratoryReportShowProps) {
    const patientName = report.patient
        ? [report.patient.first_name, report.patient.middle_name, report.patient.last_name].filter(Boolean).join(' ')
        : '—';

    const print = () => {
        router.post(route('laboratory.reports.print', report.id), {}, { preserveScroll: true, onFinish: () => window.print() });
    };

    const download = () => {
        router.post(route('laboratory.reports.download', report.id), {}, { preserveScroll: true, onFinish: () => window.print() });
    };

    const resultValue = (result: LaboratoryReport['results'][number]['results'][number]): string => {
        const raw = result.value_numeric ?? result.value_text ?? result.value_code ?? '—';

        return result.value_numeric && result.unit ? `${raw} ${result.unit}` : raw;
    };

    const paramValue = (rv: LaboratoryReportResultValueEntry): string => {
        const raw = rv.value_numeric ?? rv.value_text ?? rv.value_code ?? '—';

        return rv.value_numeric && rv.unit ? `${raw}` : raw;
    };

    const formatRefRange = (rv: LaboratoryReportResultValueEntry): string => {
        const parts: string[] = [];
        if (rv.reference_low !== null && rv.reference_high !== null) {
            parts.push(`${rv.reference_low} – ${rv.reference_high}`);
        } else if (rv.reference_low !== null) {
            parts.push(`≥ ${rv.reference_low}`);
        } else if (rv.reference_high !== null) {
            parts.push(`≤ ${rv.reference_high}`);
        }

        if (rv.reference_critical_low !== null && rv.reference_critical_high !== null) {
            parts.push(` (crit: ${rv.reference_critical_low} – ${rv.reference_critical_high})`);
        }

        if (parts.length > 0) {
            return parts.join('');
        }

        // If numeric parameter has no configured range, do not show blank or imply normal
        if (rv.value_numeric !== null) {
            return 'Reference range not configured';
        }

        return '—';
    };

    const allResults = report.results.flatMap((g) => g.results);
    const verifiers = [...new Set(allResults.map((r) => r.verified_by).filter((name): name is string => Boolean(name)))];
    const authorizers = [...new Set(allResults.map((r) => r.authorized_by).filter((name): name is string => Boolean(name)))];

    return (
        <div className="bg-muted/30 flex min-h-screen flex-col items-center gap-4 p-6 print:bg-white print:p-0">
            <Head title={`Laboratory Report — ${report.report_number}`} />

            <div className="flex w-full max-w-3xl items-center justify-between print:hidden">
                <Button asChild variant="ghost" size="sm">
                    <Link href={route('laboratory.results.index')}>
                        <ArrowLeft className="h-4 w-4" />
                        Back to Results
                    </Link>
                </Button>
                <div className="flex gap-2">
                    <Button variant="outline" onClick={print}>
                        <Printer className="h-4 w-4" />
                        Print
                    </Button>
                    <Button onClick={download}>
                        <Download className="h-4 w-4" />
                        Download
                    </Button>
                </div>
            </div>

            {report.status === 'superseded' && (
                <div className="w-full max-w-3xl rounded-md border border-amber-500 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100 print:hidden">
                    This is a superseded version (v{report.version}). A newer report version exists for this order — see Report History below.
                </div>
            )}

            {versions.length > 1 && (
                <div className="w-full max-w-3xl rounded-md border bg-white p-3 text-sm print:hidden">
                    <h3 className="text-muted-foreground mb-2 text-xs font-medium tracking-wide uppercase">Report History</h3>
                    <ul className="space-y-1">
                        {versions.map((version) => (
                            <li key={version.id} className="flex items-center justify-between gap-2">
                                <span>
                                    Version {version.version} — {formatDateTime(version.released_at)}
                                </span>
                                <div className="flex items-center gap-2">
                                    <Badge variant={version.status === 'active' ? 'default' : 'secondary'}>
                                        {version.status === 'active' ? 'Current' : 'Superseded'}
                                    </Badge>
                                    {version.id !== report.id && (
                                        <Link href={route('laboratory.reports.show', version.id)} className="text-primary hover:underline">
                                            View
                                        </Link>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="w-full max-w-3xl rounded-md border bg-white p-8 text-black shadow-sm print:w-full print:max-w-none print:border-0 print:p-0 print:shadow-none">
                <div className="mb-6 flex items-start justify-between border-b pb-4">
                    <div className="flex items-center gap-3">
                        <BrandMark className="h-12 w-12" />
                        <div>
                            <div className="text-lg font-semibold">{report.hospital?.name ?? brand.hospitalName}</div>
                            <div className="text-muted-foreground text-xs">
                                {[report.hospital?.address, report.hospital?.phone, report.hospital?.email].filter(Boolean).join(' · ')}
                            </div>
                            {report.department && <div className="text-muted-foreground text-xs">{report.department.name} Laboratory</div>}
                        </div>
                    </div>
                    <div className="text-right">
                        <div className="text-sm font-semibold tracking-wide uppercase">Laboratory Report</div>
                        <div className="text-muted-foreground text-xs">{report.report_number}</div>
                        <div className="text-muted-foreground text-xs">Version {report.version}</div>
                    </div>
                </div>

                <div className="mb-4 grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
                    <div>
                        <span className="text-muted-foreground">Patient: </span>
                        <span className="font-medium">{patientName}</span>
                    </div>
                    <div>
                        <span className="text-muted-foreground">Patient No: </span>
                        {report.patient?.patient_number ?? '—'}
                    </div>
                    <div>
                        <span className="text-muted-foreground">DOB / Age: </span>
                        {report.patient?.date_of_birth ?? '—'} ({formatAge(report.patient?.date_of_birth ?? null)})
                    </div>
                    <div>
                        <span className="text-muted-foreground">Sex: </span>
                        {report.patient?.gender ?? '—'}
                    </div>
                </div>

                <div className="mb-6 grid grid-cols-2 gap-x-6 gap-y-1 border-t pt-3 text-sm">
                    <div>
                        <span className="text-muted-foreground">Order No: </span>
                        {report.laboratory_order?.order_number ?? '—'}
                    </div>
                    <div>
                        <span className="text-muted-foreground">Priority: </span>
                        {report.laboratory_order ? laboratoryOrderPriorityLabel[report.laboratory_order.priority] : '—'}
                    </div>
                    <div>
                        <span className="text-muted-foreground">Ordered By: </span>
                        {report.laboratory_order?.ordered_by ?? '—'}
                    </div>
                    <div>
                        <span className="text-muted-foreground">Ordered At: </span>
                        {formatDateTime(report.laboratory_order?.ordered_at ?? null)}
                    </div>
                    {report.laboratory_order?.clinical_indication && (
                        <div className="col-span-2">
                            <span className="text-muted-foreground">Clinical Indication: </span>
                            {report.laboratory_order.clinical_indication}
                        </div>
                    )}
                </div>

                <div className="space-y-6">
                    {report.results.map((group, index) => (
                        <div key={index}>
                            <h3 className="mb-2 text-sm font-semibold">
                                Specimen: {group.sample_number ?? '—'}
                                {group.specimen_type && ` (${laboratorySpecimenTypeLabel[group.specimen_type]})`}
                            </h3>
                            <table className="w-full border-collapse text-sm">
                                <thead>
                                    <tr className="border-b text-left">
                                        <th className="py-1 pr-2 font-medium">Parameter</th>
                                        <th className="py-1 pr-2 font-medium">Result</th>
                                        <th className="py-1 pr-2 font-medium">Unit</th>
                                        <th className="py-1 pr-2 font-medium">Reference Range</th>
                                        <th className="py-1 pr-2 font-medium">Flag</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {group.results.map((result) => {
                                        const hasParams = result.result_values && result.result_values.length > 0;

                                        if (hasParams) {
                                            return (
                                                <React.Fragment key={result.laboratory_result_id}>
                                                    <tr className="bg-muted/30 border-b">
                                                        <td colSpan={5} className="py-2 pr-2 font-semibold">
                                                            {result.test_code} — {result.test_name}
                                                        </td>
                                                    </tr>
                                                    {result.result_values.map((rv, rvIndex) => (
                                                        <tr key={`${result.laboratory_result_id}-rv-${rvIndex}`} className="border-b last:border-0">
                                                            <td className="py-1.5 pl-4 pr-2">{rv.parameter_name ?? rv.parameter_code ?? '—'}</td>
                                                            <td className="py-1.5 pr-2 font-medium">{paramValue(rv)}</td>
                                                            <td className="text-muted-foreground py-1.5 pr-2">{rv.unit ?? '—'}</td>
                                                            <td className="text-muted-foreground py-1.5 pr-2">{formatRefRange(rv)}</td>
                                                            <td className="py-1.5 pr-2">
                                                                {rv.abnormal_flag && rv.abnormal_flag !== 'normal' && (
                                                                    <Badge variant={rv.abnormal_flag === 'critical' ? 'destructive' : 'default'}>
                                                                        {laboratoryAbnormalFlagLabel[rv.abnormal_flag]}
                                                                    </Badge>
                                                                )}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </React.Fragment>
                                            );
                                        }

                                        return (
                                            <tr key={result.laboratory_result_id} className="border-b last:border-0">
                                                <td className="py-2 pr-2">
                                                    {result.test_code} — {result.test_name}
                                                </td>
                                                <td className="py-2 pr-2 font-medium">{resultValue(result)}</td>
                                                <td className="text-muted-foreground py-2 pr-2">{result.unit ?? '—'}</td>
                                                <td className="text-muted-foreground py-2 pr-2">
                                                    {result.reference_range 
                                                        ? result.reference_range 
                                                        : (result.result_type === 'numeric' ? 'Reference range not configured' : '—')}
                                                </td>
                                                <td className="py-2 pr-2">
                                                    {result.abnormal_flag && result.abnormal_flag !== 'normal' && (
                                                        <Badge variant={result.abnormal_flag === 'critical' ? 'destructive' : 'default'}>
                                                            {laboratoryAbnormalFlagLabel[result.abnormal_flag]}
                                                        </Badge>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                            {group.results.some((r) => r.result_comment) && (
                                <div className="text-muted-foreground mt-2 space-y-1 text-xs">
                                    {group.results
                                        .filter((r) => r.result_comment)
                                        .map((r) => (
                                            <p key={r.laboratory_result_id}>
                                                <span className="font-medium">{r.test_code} comment:</span> {r.result_comment}
                                            </p>
                                        ))}
                                </div>
                            )}
                        </div>
                    ))}
                </div>

                <div className="mt-8 grid grid-cols-2 gap-x-6 gap-y-1 border-t pt-4 text-sm">
                    {verifiers.length > 0 && (
                        <div>
                            <span className="text-muted-foreground">Verified By: </span>
                            {verifiers.join(', ')}
                        </div>
                    )}
                    {authorizers.length > 0 && (
                        <div>
                            <span className="text-muted-foreground">Authorized By: </span>
                            {authorizers.join(', ')}
                        </div>
                    )}
                </div>

                <div className="text-muted-foreground mt-6 flex justify-between border-t pt-4 text-xs">
                    <span>
                        {report.report_number} · v{report.version}
                    </span>
                    <span>
                        Released by {report.released_by ?? '—'} on {formatDateTime(report.released_at)}
                    </span>
                </div>
            </div>
        </div>
    );
}
