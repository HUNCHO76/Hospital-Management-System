import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { laboratoryAbnormalFlagLabel, laboratoryAbnormalFlagVariant } from '@/lib/laboratory-status';
import { type LaboratoryReport } from '@/types/laboratory';
import { Link } from '@inertiajs/react';

interface LaboratoryResultsSectionProps {
    laboratoryResults: LaboratoryReport[];
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

/**
 * Official released laboratory results on the clinical encounter page — the
 * doctor sees these automatically once the lab releases a report, without
 * needing to navigate to a separate laboratory module. Only the currently
 * ACTIVE (non-superseded) version per order is ever shown here, filtered
 * server-side in ClinicalEncounterController::show(). Deliberately separate
 * from LaboratoryOrdersSection above, which continues showing plain
 * order/item status tracking for every workflow status.
 */
export function LaboratoryResultsSection({ laboratoryResults }: LaboratoryResultsSectionProps) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Laboratory Results</CardTitle>
            </CardHeader>
            <CardContent>
                {laboratoryResults.length > 0 ? (
                    <ul className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                        {laboratoryResults.map((report) => (
                            <li key={report.id} className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {report.laboratory_order?.order_number ?? report.report_number}
                                        </span>
                                        <Badge variant="secondary">Released</Badge>
                                        {report.version > 1 && (
                                            <Badge variant="outline">v{report.version}</Badge>
                                        )}
                                    </div>
                                    <Link
                                        href={route('laboratory.reports.show', report.id)}
                                        className="text-primary text-sm hover:underline"
                                    >
                                        View Full Report
                                    </Link>
                                </div>

                                {report.results.flatMap((g) => g.results).length > 0 && (
                                    <table className="w-full border-collapse text-sm">
                                        <thead>
                                            <tr className="border-b text-left">
                                                <th className="text-muted-foreground py-1 pr-2 text-xs font-medium">Test</th>
                                                <th className="text-muted-foreground py-1 pr-2 text-xs font-medium">Result</th>
                                                <th className="text-muted-foreground py-1 pr-2 text-xs font-medium">Reference</th>
                                                <th className="text-muted-foreground py-1 text-xs font-medium">Flag</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {report.results.flatMap((group) =>
                                                group.results.map((result) => {
                                                    const raw = result.value_numeric ?? result.value_text ?? result.value_code ?? '—';
                                                    const value =
                                                        result.value_numeric && result.unit ? `${raw} ${result.unit}` : raw;

                                                    return (
                                                        <tr key={result.laboratory_result_id} className="border-b last:border-0">
                                                            <td className="py-1.5 pr-2">
                                                                {result.test_code} — {result.test_name}
                                                            </td>
                                                            <td className="py-1.5 pr-2 font-medium">{value}</td>
                                                            <td className="text-muted-foreground py-1.5 pr-2">
                                                                {result.reference_range ?? '—'}
                                                            </td>
                                                            <td className="py-1.5">
                                                                {result.abnormal_flag && result.abnormal_flag !== 'normal' && (
                                                                    <Badge variant={laboratoryAbnormalFlagVariant[result.abnormal_flag]}>
                                                                        {laboratoryAbnormalFlagLabel[result.abnormal_flag]}
                                                                    </Badge>
                                                                )}
                                                            </td>
                                                        </tr>
                                                    );
                                                }),
                                            )}
                                        </tbody>
                                    </table>
                                )}

                                <div className="text-muted-foreground grid gap-0.5 text-xs">
                                    <span>Released By: {report.released_by ?? '—'}</span>
                                    <span>Released: {formatDateTime(report.released_at)}</span>
                                </div>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-muted-foreground text-sm">No released laboratory results yet.</p>
                )}
            </CardContent>
        </Card>
    );
}
