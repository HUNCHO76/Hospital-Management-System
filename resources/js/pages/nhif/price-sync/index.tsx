import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type NhifPriceSyncLog } from '@/types/nhif';
import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Pagination } from '@/components/pagination';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NHIF', href: '/nhif/dashboard' },
    { title: 'Price Sync', href: '/nhif/price-sync' },
];

interface Hospital { id: number; name: string; code: string }

interface Props {
    logs: Paginated<NhifPriceSyncLog & { hospital: Hospital | null }>;
    hospitals: Hospital[];
}

const statusColors: Record<string, string> = {
    started: 'bg-blue-100 text-blue-700',
    completed: 'bg-green-100 text-green-700',
    failed: 'bg-red-100 text-red-700',
    partial: 'bg-yellow-100 text-yellow-700',
};

export default function PriceSyncIndex({ logs, hospitals }: Props) {
    const { data, setData, post, processing, transform } = useForm({
        sync_type: 'items',
        hospital_id: 'all',
    });

    transform((d) => ({ ...d, hospital_id: d.hospital_id === 'all' ? null : d.hospital_id }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="NHIF Price Sync" />

            <div className="flex h-full flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">NHIF Price Sync</h1>
                    <p className="text-sm text-muted-foreground">
                        Pull the latest service catalog and tariff data from the NHIF API.
                    </p>
                </div>

                <div className="rounded-md border p-4">
                    <h2 className="mb-4 text-sm font-medium">Run Sync</h2>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            post(route('nhif.price-sync.store'));
                        }}
                        className="flex flex-wrap items-end gap-4"
                    >
                        <div className="space-y-1">
                            <Label htmlFor="sync_type">Sync Type</Label>
                            <Select value={data.sync_type} onValueChange={(v) => setData('sync_type', v)}>
                                <SelectTrigger className="w-40" id="sync_type">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="items">Services / Items</SelectItem>
                                    <SelectItem value="packages">Packages</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="hospital_id">Facility (optional)</Label>
                            <Select value={data.hospital_id} onValueChange={(v) => setData('hospital_id', v)}>
                                <SelectTrigger className="w-52" id="hospital_id">
                                    <SelectValue placeholder="All facilities" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All facilities</SelectItem>
                                    {hospitals.map((h) => (
                                        <SelectItem key={h.id} value={String(h.id)}>{h.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <Button type="submit" disabled={processing}>
                            {processing ? 'Syncing…' : 'Start Sync'}
                        </Button>
                    </form>
                </div>

                <div>
                    <h2 className="mb-2 text-sm font-medium">Sync History</h2>
                    <div className="rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/40">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">Type</th>
                                    <th className="px-4 py-3 text-left font-medium">Facility</th>
                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                    <th className="px-4 py-3 text-right font-medium">Total</th>
                                    <th className="px-4 py-3 text-right font-medium">New</th>
                                    <th className="px-4 py-3 text-right font-medium">Updated</th>
                                    <th className="px-4 py-3 text-left font-medium">Started</th>
                                    <th className="px-4 py-3 text-left font-medium">Completed</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {logs.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={8} className="px-4 py-8 text-center text-muted-foreground">
                                            No sync runs yet.
                                        </td>
                                    </tr>
                                ) : (
                                    logs.data.map((log) => (
                                        <tr key={log.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3 capitalize">{log.sync_type}</td>
                                            <td className="px-4 py-3">{log.hospital?.name ?? 'All'}</td>
                                            <td className="px-4 py-3">
                                                <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${statusColors[log.status] ?? ''}`}>
                                                    {log.status}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-right">{log.total_records ?? '—'}</td>
                                            <td className="px-4 py-3 text-right">{log.new_records ?? '—'}</td>
                                            <td className="px-4 py-3 text-right">{log.updated_records ?? '—'}</td>
                                            <td className="px-4 py-3 text-xs">{log.started_at ? new Date(log.started_at).toLocaleString() : '—'}</td>
                                            <td className="px-4 py-3 text-xs">{log.completed_at ? new Date(log.completed_at).toLocaleString() : '—'}</td>
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
