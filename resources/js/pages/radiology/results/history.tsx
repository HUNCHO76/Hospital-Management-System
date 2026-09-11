import { EmptyState } from '@/components/empty-state';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type RadiologyReportHistoryEntry } from '@/types/radiology';
import { Head } from '@inertiajs/react';
import { History } from 'lucide-react';

interface RadiologyReportHistoryProps {
    report: { id: number; report_number: string; status: string };
    logs: RadiologyReportHistoryEntry[];
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

const actionLabel: Record<string, string> = {
    radiology_report_created: 'Report created',
    radiology_report_updated: 'Draft updated',
    radiology_report_submitted: 'Report submitted',
    radiology_report_returned: 'Report returned for correction',
};

/**
 * Report change history (Phase 5, Step 42, Part 18) — reuses the existing
 * AuditLogService/AuditLog table directly rather than a second versioning
 * system; answers "who changed this report, when, and what" from the same
 * audit trail every other clinical record in this app already relies on.
 */
export default function RadiologyReportHistory({ report, logs }: RadiologyReportHistoryProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Radiology', href: '/radiology' },
        { title: 'Results', href: '/radiology/results' },
        { title: report.report_number, href: `/radiology/results/${report.id}` },
        { title: 'History', href: `/radiology/results/${report.id}/history` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`History — ${report.report_number}`} />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Report History</h1>
                    <p className="text-muted-foreground text-sm">{report.report_number}</p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Changes</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {logs.length === 0 ? (
                            <EmptyState icon={History} title="No history yet" description="Changes to this report will appear here." />
                        ) : (
                            <ol className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                                {logs.map((log) => (
                                    <li key={log.id} className="flex flex-col gap-1 py-3 first:pt-0 last:pb-0">
                                        <div className="flex items-center justify-between gap-4">
                                            <span className="font-medium">{actionLabel[log.action] ?? log.action}</span>
                                            <span className="text-muted-foreground text-xs">{formatDateTime(log.created_at)}</span>
                                        </div>
                                        <span className="text-muted-foreground text-xs">{log.user?.name ?? 'System'}</span>
                                        {log.new_values && Object.keys(log.new_values).length > 0 && (
                                            <dl className="mt-1 grid grid-cols-[max-content_1fr] gap-x-3 gap-y-1 text-xs">
                                                {Object.entries(log.new_values).map(([key, value]) => (
                                                    <div key={key} className="contents">
                                                        <dt className="text-muted-foreground">{key}</dt>
                                                        <dd className="truncate">{String(value ?? '—')}</dd>
                                                    </div>
                                                ))}
                                            </dl>
                                        )}
                                    </li>
                                ))}
                            </ol>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
