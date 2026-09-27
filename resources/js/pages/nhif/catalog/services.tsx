import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type NhifService } from '@/types/nhif';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Pagination } from '@/components/pagination';
import { type FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NHIF', href: '/nhif/dashboard' },
    { title: 'Service Catalog', href: '/nhif/catalog/services' },
];

interface Props {
    services: Paginated<NhifService>;
    filters: { search?: string; active_only?: string };
    lastSync: string | null;
}

export default function NhifCatalogServices({ services, filters, lastSync }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');

    const submitSearch: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('nhif.catalog.services'), { search }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="NHIF Service Catalog" />

            <div className="flex h-full flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">NHIF Service Catalog</h1>
                        <p className="text-sm text-muted-foreground">
                            {lastSync ? `Last synced: ${new Date(lastSync).toLocaleString()}` : 'Not yet synced from NHIF.'}
                        </p>
                    </div>
                    <Button asChild variant="secondary">
                        <Link href={route('nhif.price-sync.index')}>Sync Catalog</Link>
                    </Button>
                </div>

                <form onSubmit={submitSearch} className="flex gap-2">
                    <Input
                        placeholder="Search item code or name…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="max-w-sm"
                    />
                    <Button type="submit" variant="secondary">Search</Button>
                </form>

                <div className="rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="border-b bg-muted/40">
                            <tr>
                                <th className="px-4 py-3 text-left font-medium">Code</th>
                                <th className="px-4 py-3 text-left font-medium">Name</th>
                                <th className="px-4 py-3 text-left font-medium">Type</th>
                                <th className="px-4 py-3 text-left font-medium">Status</th>
                                <th className="px-4 py-3 text-left font-medium">Synced</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {services.data.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="px-4 py-8 text-center text-muted-foreground">
                                        No services found.
                                    </td>
                                </tr>
                            ) : (
                                services.data.map((s) => (
                                    <tr key={s.id} className="hover:bg-muted/30">
                                        <td className="px-4 py-3 font-mono text-xs">{s.nhif_item_code}</td>
                                        <td className="px-4 py-3">{s.nhif_item_name}</td>
                                        <td className="px-4 py-3 text-xs text-muted-foreground">{s.nhif_item_type_name ?? '—'}</td>
                                        <td className="px-4 py-3">
                                            <Badge variant={s.is_active ? 'default' : 'secondary'}>
                                                {s.is_active ? 'Active' : 'Inactive'}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-xs text-muted-foreground">
                                            {s.synced_at ? new Date(s.synced_at).toLocaleDateString() : '—'}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination links={services.links} />
            </div>
        </AppLayout>
    );
}
