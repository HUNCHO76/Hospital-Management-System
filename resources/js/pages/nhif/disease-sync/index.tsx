import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type NhifPriceSyncLog } from '@/types/nhif';
import { Head, useForm } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NHIF', href: '/nhif/dashboard' },
    { title: 'Disease Sync', href: '/nhif/disease-sync' },
];

interface Props {
    logs: Paginated<NhifPriceSyncLog>;
    diseaseCount: number;
    lastSyncedAt: string | null;
}

const statusColors: Record<string, string> = {
    started: 'bg-blue-100 text-blue-700',
    completed: 'bg-green-100 text-green-700',
    failed: 'bg-red-100 text-red-700',
    partial: 'bg-yellow-100 text-yellow-700',
};

export default function DiseaseSyncIndex({ logs, diseaseCount, lastSyncedAt }: Props) {
    const { post, processing } = useForm({});

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="NHIF Disease Sync" />

            <div className="flex h-full flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">NHIF Disease Sync</h1>
                    <p className="text-muted-foreground text-sm">
                        Sync the NHIF disease reference from the OCS GetDiseases API into the local database.
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-6 rounded-md border p-4">
                    <div className="space-y-1">
                        <p className="text-muted-foreground text-xs font-medium uppercase tracking-wide">Diseases in DB</p>
                        <p className="text-2xl font-semibold">{diseaseCount.toLocaleString()}</p>
                    </div>
                    <div className="space-y-1">
                        <p className="text-muted-foreground text-xs font-medium uppercase tracking-wide">Last Synced</p>
                        <p className="text-sm">
                            {lastSyncedAt ? new Date(lastSyncedAt).toLocaleString() : 'Never'}
                        </p>
                    </div>
                    <div className="ml-auto">
                        <Button
                            onClick={() => post(route('nhif.disease-sync.store'))}
                            disabled={processing}
                        >
                            {processing ? 'Syncing...' : 'Sync Diseases'}
                        </Button>
                    </div>
                </div>

                <div>
                    <h2 className="mb-2 text-sm font-medium">Sync History</h2>
                    <div className="rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/40 border-b">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                    <th className="px-4 py-3 text-right font-medium">Total</th>
                                    <th className="px-4 py-3 text-right font-medium">New</th>
                                    <th className="px-4 py-3 text-right font-medium">Updated</th>
                                    <th className="px-4 py-3 text-right font-medium">Skipped</th>
                                    <th className="px-4 py-3 text-left font-medium">Error</th>
                                    <th className="px-4 py-3 text-left font-medium">Started</th>
                                    <th className="px-4 py-3 text-left font-medium">Completed</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {logs.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={8} className="text-muted-foreground px-4 py-8 text-center">
                                            No sync runs yet.
                                        </td>
                                    </tr>
                                ) : (
                                    logs.data.map((log) => (
                                        <tr key={log.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3">
                                                <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${statusColors[log.status] ?? ''}`}>
                                                    {log.status}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-right">{log.total_records ?? '—'}</td>
                                            <td className="px-4 py-3 text-right">{log.new_records ?? '—'}</td>
                                            <td className="px-4 py-3 text-right">{log.updated_records ?? '—'}</td>
                                            <td className="px-4 py-3 text-right">{log.skipped_records ?? '—'}</td>
                                            <td className="px-4 py-3 text-sm text-red-600">
                                                {log.error_message ? (
                                                    <span className="line-clamp-2">{log.error_message}</span>
                                                ) : (
                                                    '—'
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-xs">
                                                {log.started_at ? new Date(log.started_at).toLocaleString() : '—'}
                                            </td>
                                            <td className="px-4 py-3 text-xs">
                                                {log.completed_at ? new Date(log.completed_at).toLocaleString() : '—'}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                    <div className="mt-2">
                        <Pagination links={logs.links} />
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
