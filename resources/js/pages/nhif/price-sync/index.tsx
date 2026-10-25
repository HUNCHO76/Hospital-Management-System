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
    { title: 'Price & Catalogue Sync', href: '/nhif/price-sync' },
];

interface Hospital { id: number; name: string; code: string }

interface CatalogueReport {
    total_schemes_services_codes: number;
    total_nhif_services_synced: number;
    schemes_codes_missing_in_nhif_services: number;
    nhif_services_active: number;
    nhif_services_inactive: number;
    nhif_services_restricted: number;
    item_types_count: number;
}

interface Props {
    logs: Paginated<NhifPriceSyncLog & { hospital: Hospital | null }>;
    hospitals: Hospital[];
    catalogueReport?: CatalogueReport;
}

const statusColors: Record<string, string> = {
    started: 'bg-blue-100 text-blue-700',
    completed: 'bg-green-100 text-green-700',
    failed: 'bg-red-100 text-red-700',
    partial: 'bg-yellow-100 text-yellow-700',
};

export default function PriceSyncIndex({ logs, hospitals, catalogueReport }: Props) {
    const { data, setData, post, processing, transform } = useForm({
        sync_type: 'items',
        hospital_id: 'all',
    });

    transform((d) => ({ ...d, hospital_id: d.hospital_id === 'all' ? null : d.hospital_id }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="NHIF Price & Catalogue Sync" />

            <div className="flex h-full flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">NHIF Price &amp; Catalogue Sync</h1>
                    <p className="text-sm text-muted-foreground">
                        Synchronize national item types, master items, disease classifications, and scheme service tariffs from the NHIF OCS API.
                    </p>
                </div>

                {catalogueReport && (
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div className="rounded-lg border bg-card p-4 text-card-foreground shadow-sm">
                            <div className="text-xs font-medium text-muted-foreground">National Items (OCS)</div>
                            <div className="mt-1 text-2xl font-bold">{catalogueReport.total_nhif_services_synced.toLocaleString()}</div>
                            <div className="mt-1 flex items-center gap-2 text-xs">
                                <span className="text-emerald-600 font-medium">{catalogueReport.nhif_services_active.toLocaleString()} Active</span>
                                <span>•</span>
                                <span className="text-amber-600 font-medium">{catalogueReport.nhif_services_inactive.toLocaleString()} Inactive</span>
                            </div>
                        </div>

                        <div className="rounded-lg border bg-card p-4 text-card-foreground shadow-sm">
                            <div className="text-xs font-medium text-muted-foreground">Item Types (Categories)</div>
                            <div className="mt-1 text-2xl font-bold">{catalogueReport.item_types_count}</div>
                            <p className="mt-1 text-xs text-muted-foreground">Standard national service types</p>
                        </div>

                        <div className="rounded-lg border bg-card p-4 text-card-foreground shadow-sm">
                            <div className="text-xs font-medium text-muted-foreground">Schemes Services Mapping</div>
                            <div className="mt-1 text-2xl font-bold">{catalogueReport.total_schemes_services_codes.toLocaleString()}</div>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {catalogueReport.schemes_codes_missing_in_nhif_services === 0
                                    ? '100% matched to national items'
                                    : `${catalogueReport.schemes_codes_missing_in_nhif_services} unmatched codes`}
                            </p>
                        </div>

                        <div className="rounded-lg border bg-card p-4 text-card-foreground shadow-sm">
                            <div className="text-xs font-medium text-muted-foreground">Pre-Auth Restricted Items</div>
                            <div className="mt-1 text-2xl font-bold">{catalogueReport.nhif_services_restricted.toLocaleString()}</div>
                            <p className="mt-1 text-xs text-muted-foreground">Requires prior NHIF authorization</p>
                        </div>
                    </div>
                )}

                <div className="rounded-md border p-4">
                    <h2 className="mb-4 text-sm font-medium">Run Live Synchronization</h2>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            post(route('nhif.price-sync.store'));
                        }}
                        className="flex flex-wrap items-end gap-4"
                    >
                        <div className="space-y-1">
                            <Label htmlFor="sync_type">Sync Target</Label>
                            <Select value={data.sync_type} onValueChange={(v) => setData('sync_type', v)}>
                                <SelectTrigger className="w-56" id="sync_type">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="items">National Items (OCS GetItems)</SelectItem>
                                    <SelectItem value="item_types">Item Types (OCS GetItemTypes)</SelectItem>
                                    <SelectItem value="packages">Benefit Packages / Schemes</SelectItem>
                                    <SelectItem value="diseases">Disease Codes (OCS GetDiseases)</SelectItem>
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
                            {processing ? 'Synchronizing…' : 'Start Synchronization'}
                        </Button>
                    </form>
                </div>

                <div>
                    <h2 className="mb-2 text-sm font-medium">Sync History &amp; Audit Logs</h2>
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
                                            No sync runs recorded yet.
                                        </td>
                                    </tr>
                                ) : (
                                    logs.data.map((log) => (
                                        <tr key={log.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3 font-mono text-xs">{log.sync_type}</td>
                                            <td className="px-4 py-3">{log.hospital?.name ?? 'All'}</td>
                                            <td className="px-4 py-3">
                                                <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${statusColors[log.status] ?? ''}`}>
                                                    {log.status}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-right">{log.total_records ?? '—'}</td>
                                            <td className="px-4 py-3 text-right text-emerald-600 font-medium">{log.new_records ?? '—'}</td>
                                            <td className="px-4 py-3 text-right text-blue-600 font-medium">{log.updated_records ?? '—'}</td>
                                            <td className="px-4 py-3 text-xs text-muted-foreground">{log.started_at ? new Date(log.started_at).toLocaleString() : '—'}</td>
                                            <td className="px-4 py-3 text-xs text-muted-foreground">{log.completed_at ? new Date(log.completed_at).toLocaleString() : '—'}</td>
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
