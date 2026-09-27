import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type NhifPackage } from '@/types/nhif';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Pagination } from '@/components/pagination';
import { type FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NHIF', href: '/nhif/dashboard' },
    { title: 'Packages', href: '/nhif/catalog/packages' },
];

interface Props {
    packages: Paginated<NhifPackage>;
    filters: { search?: string };
    lastSync: string | null;
}

export default function NhifCatalogPackages({ packages, filters, lastSync }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');

    const submitSearch: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('nhif.catalog.packages'), { search }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="NHIF Packages" />

            <div className="flex h-full flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">NHIF Benefit Packages</h1>
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
                        placeholder="Search package code or name…"
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
                                <th className="px-4 py-3 text-left font-medium">Package Code</th>
                                <th className="px-4 py-3 text-left font-medium">Package Name</th>
                                <th className="px-4 py-3 text-left font-medium">Scheme</th>
                                <th className="px-4 py-3 text-left font-medium">Scheme Name</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {packages.data.length === 0 ? (
                                <tr>
                                    <td colSpan={4} className="px-4 py-8 text-center text-muted-foreground">
                                        No packages found.
                                    </td>
                                </tr>
                            ) : (
                                packages.data.map((p) => (
                                    <tr key={p.id} className="hover:bg-muted/30">
                                        <td className="px-4 py-3 font-mono text-xs">{p.nhif_package_code}</td>
                                        <td className="px-4 py-3">{p.nhif_package_name}</td>
                                        <td className="px-4 py-3 text-xs text-muted-foreground">{p.scheme_id ?? '—'}</td>
                                        <td className="px-4 py-3 text-xs text-muted-foreground">{p.scheme_name ?? '—'}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination links={packages.links} />
            </div>
        </AppLayout>
    );
}
