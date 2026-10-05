import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type NhifPriceSyncLog } from '@/types/nhif';
import { Head, router, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Pagination } from '@/components/pagination';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NHIF', href: '/nhif/dashboard' },
    { title: 'Package Prices', href: '/nhif/package-prices' },
];

interface NhifPackagePrice {
    id: number;
    item_code: string;
    price_code: string | null;
    item_name: string | null;
    scheme_id: number | null;
    package_id: number | null;
    unit_price: number;
    is_restricted: boolean;
    synced_at: string | null;
}

interface SyncLogSummary {
    id: number;
    status: string;
    total_records: number | null;
    new_records: number | null;
    updated_records: number | null;
    started_at: string | null;
    completed_at: string | null;
    error_message: string | null;
}

interface Props {
    prices: Paginated<NhifPackagePrice>;
    latestSync: SyncLogSummary | null;
    filters: { scheme_id?: string; search?: string };
}

const statusColors: Record<string, string> = {
    started: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
    completed: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
    failed: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
    partial: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300',
};

export default function PackagePricesIndex({ prices, latestSync, filters }: Props) {
    const syncForm = useForm({ scheme_id: '', package_id: '' });

    function handleSearch(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const fd = new FormData(e.currentTarget);
        router.get(route('nhif.package-prices.index'), {
            scheme_id: fd.get('scheme_id') ?? '',
            search: fd.get('search') ?? '',
        }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="NHIF Package Prices" />

            <div className="flex h-full flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Package Price Catalog</h1>
                    <p className="text-sm text-muted-foreground">
                        NHIF GetPricePackage catalog — used to resolve correct unit prices for preapproval requests.
                    </p>
                </div>

                {/* Latest sync banner */}
                {latestSync && (
                    <div className="rounded-md border px-4 py-3 text-sm">
                        <span className="font-medium">Last sync: </span>
                        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${statusColors[latestSync.status] ?? ''}`}>
                            {latestSync.status}
                        </span>
                        {latestSync.status === 'completed' && (
                            <span className="ml-2 text-muted-foreground">
                                {latestSync.total_records ?? 0} total, {latestSync.new_records ?? 0} new, {latestSync.updated_records ?? 0} updated
                                {latestSync.completed_at ? ` — ${new Date(latestSync.completed_at).toLocaleString()}` : ''}
                            </span>
                        )}
                        {latestSync.status === 'failed' && latestSync.error_message && (
                            <span className="ml-2 text-destructive">{latestSync.error_message}</span>
                        )}
                    </div>
                )}

                {/* Sync controls */}
                <div className="rounded-md border p-4">
                    <h2 className="mb-4 text-sm font-medium">Sync from NHIF</h2>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            syncForm.post(route('nhif.package-prices.sync'));
                        }}
                        className="flex flex-wrap items-end gap-4"
                    >
                        <div className="space-y-1">
                            <Label htmlFor="sync_scheme_id">Scheme ID (optional)</Label>
                            <Input
                                id="sync_scheme_id"
                                type="number"
                                className="w-36"
                                placeholder="All schemes"
                                value={syncForm.data.scheme_id}
                                onChange={(e) => syncForm.setData('scheme_id', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="sync_package_id">Package ID (optional)</Label>
                            <Input
                                id="sync_package_id"
                                type="number"
                                className="w-36"
                                placeholder="All packages"
                                value={syncForm.data.package_id}
                                onChange={(e) => syncForm.setData('package_id', e.target.value)}
                            />
                        </div>
                        <Button type="submit" disabled={syncForm.processing}>
                            {syncForm.processing ? 'Syncing…' : 'Sync Prices'}
                        </Button>
                    </form>
                </div>

                {/* Filter bar */}
                <form onSubmit={handleSearch} className="flex flex-wrap items-end gap-3">
                    <div className="space-y-1">
                        <Label htmlFor="filter_scheme_id">Filter by Scheme ID</Label>
                        <Input
                            id="filter_scheme_id"
                            name="scheme_id"
                            type="number"
                            className="w-36"
                            placeholder="Any"
                            defaultValue={filters.scheme_id ?? ''}
                        />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="filter_search">Search code / name</Label>
                        <Input
                            id="filter_search"
                            name="search"
                            className="w-52"
                            placeholder="e.g. CONSULT-GP"
                            defaultValue={filters.search ?? ''}
                        />
                    </div>
                    <Button type="submit" variant="secondary">Filter</Button>
                </form>

                {/* Price table */}
                <div>
                    <div className="rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/40">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">Item Code</th>
                                    <th className="px-4 py-3 text-left font-medium">Name</th>
                                    <th className="px-4 py-3 text-right font-medium">Scheme ID</th>
                                    <th className="px-4 py-3 text-right font-medium">Package ID</th>
                                    <th className="px-4 py-3 text-right font-medium">Unit Price</th>
                                    <th className="px-4 py-3 text-center font-medium">Restricted</th>
                                    <th className="px-4 py-3 text-left font-medium">Synced At</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {prices.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={7} className="px-4 py-10 text-center text-muted-foreground">
                                            No package prices found. Run a sync to populate this catalog.
                                        </td>
                                    </tr>
                                ) : (
                                    prices.data.map((p) => (
                                        <tr key={p.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3 font-mono text-xs">{p.item_code}</td>
                                            <td className="px-4 py-3">{p.item_name ?? '—'}</td>
                                            <td className="px-4 py-3 text-right tabular-nums">{p.scheme_id ?? '—'}</td>
                                            <td className="px-4 py-3 text-right tabular-nums">{p.package_id ?? '—'}</td>
                                            <td className="px-4 py-3 text-right tabular-nums font-medium">
                                                {p.unit_price.toLocaleString('en-TZ', { minimumFractionDigits: 2 })}
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                {p.is_restricted ? (
                                                    <span className="rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-700 dark:bg-red-900/30 dark:text-red-300">Yes</span>
                                                ) : (
                                                    <span className="text-muted-foreground">—</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-xs text-muted-foreground">
                                                {p.synced_at ? new Date(p.synced_at).toLocaleString() : '—'}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                    <div className="mt-2">
                        <Pagination links={prices.links} />
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
