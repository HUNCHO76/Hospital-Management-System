import { AuditChangeDiff } from '@/components/audit/audit-change-diff';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type AuditLog } from '@/types/audit-log';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { type ReactNode } from 'react';

interface AuditLogShowProps {
    auditLog: AuditLog;
}

function formatDateTime(value: string): string {
    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}

function DetailRow({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div className="flex justify-between gap-4">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right break-all">{value}</dd>
        </div>
    );
}

export default function AuditLogShow({ auditLog }: AuditLogShowProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Audit Logs', href: '/audit-logs' },
        { title: `#${auditLog.id}`, href: `/audit-logs/${auditLog.id}` },
    ];

    const isCreate = auditLog.old_values === null && auditLog.new_values !== null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Audit Log #${auditLog.id}`} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="flex items-center gap-2 text-2xl font-semibold">
                            <Badge variant="secondary">{auditLog.action}</Badge>
                        </h1>
                        <p className="text-muted-foreground text-sm">{formatDateTime(auditLog.created_at)}</p>
                    </div>

                    <Button asChild variant="outline">
                        <Link href={route('audit-logs.index')}>
                            <ArrowLeft className="h-4 w-4" />
                            Back to logs
                        </Link>
                    </Button>
                </div>

                <div className="grid gap-6 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Event Information</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <DetailRow label="Action" value={auditLog.action} />
                                <DetailRow label="Date/Time" value={formatDateTime(auditLog.created_at)} />
                                <DetailRow label="User" value={auditLog.user ? `${auditLog.user.name} (${auditLog.user.email})` : 'System / unauthenticated'} />
                                <DetailRow label="IP Address" value={auditLog.ip_address ?? '—'} />
                                <DetailRow label="URL" value={auditLog.url ?? '—'} />
                                <DetailRow label="User Agent" value={auditLog.user_agent ?? '—'} />
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Affected Record</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <DetailRow label="Module" value={auditLog.module} />
                                <DetailRow label="Record ID" value={auditLog.auditable_id ?? '—'} />
                            </dl>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">{isCreate ? 'Recorded Values' : 'Changes'}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <AuditChangeDiff oldValues={auditLog.old_values} newValues={auditLog.new_values} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
