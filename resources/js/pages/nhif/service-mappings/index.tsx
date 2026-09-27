import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type NhifServiceMapping } from '@/types/nhif';
import { Head, Link, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Pagination } from '@/components/pagination';
import { Plus } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NHIF', href: '/nhif/service-mappings' },
    { title: 'Service Mappings', href: '/nhif/service-mappings' },
];

const coverageColors: Record<string, string> = {
    covered: 'bg-green-100 text-green-800',
    partially_covered: 'bg-yellow-100 text-yellow-800',
    excluded: 'bg-red-100 text-red-800',
    not_mapped: 'bg-gray-100 text-gray-800',
    unknown: 'bg-gray-100 text-gray-600',
};

interface Props {
    mappings: Paginated<NhifServiceMapping>;
    filters: { search?: string };
}

export default function ServiceMappingsIndex({ mappings, filters }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');

    const submitSearch: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('nhif.service-mappings.index'), { search }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="NHIF Service Mappings" />

            <div className="flex h-full flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">NHIF Service Mappings</h1>
                        <p className="text-sm text-muted-foreground">
                            Map HMS services to NHIF item codes for claims and coverage determination.
                        </p>
                    </div>
                    <Button asChild>
                        <Link href={route('nhif.service-mappings.create')}>
                            <Plus className="mr-2 h-4 w-4" />
                            New Mapping
                        </Link>
                    </Button>
                </div>

                <form onSubmit={submitSearch} className="flex gap-2">
                    <Input
                        placeholder="Search service name or NHIF code…"
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
                                <th className="px-4 py-3 text-left font-medium">HMS Service</th>
                                <th className="px-4 py-3 text-left font-medium">NHIF Code</th>
                                <th className="px-4 py-3 text-left font-medium">NHIF Name</th>
                                <th className="px-4 py-3 text-left font-medium">Coverage</th>
                                <th className="px-4 py-3 text-right font-medium">Allowable (TZS)</th>
                                <th className="px-4 py-3 text-left font-medium">Source</th>
                                <th className="px-4 py-3 text-left font-medium">Status</th>
                                <th className="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {mappings.data.length === 0 ? (
                                <tr>
                                    <td colSpan={8} className="px-4 py-8 text-center text-muted-foreground">
                                        No service mappings found.{' '}
                                        <Link href={route('nhif.service-mappings.create')} className="text-primary underline">
                                            Add the first mapping.
                                        </Link>
                                    </td>
                                </tr>
                            ) : (
                                mappings.data.map((m) => (
                                    <tr key={m.id} className="hover:bg-muted/30">
                                        <td className="px-4 py-3">{m.service?.name ?? '—'}</td>
                                        <td className="px-4 py-3 font-mono text-xs">{m.nhif_item_code}</td>
                                        <td className="px-4 py-3">{m.nhif_item_name}</td>
                                        <td className="px-4 py-3">
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${coverageColors[m.coverage_status] ?? ''}`}>
                                                {m.coverage_status.replace('_', ' ')}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {m.current_allowable_amount != null
                                                ? Number(m.current_allowable_amount).toLocaleString()
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-3 capitalize">{m.mapping_source}</td>
                                        <td className="px-4 py-3">
                                            <Badge variant={m.is_active ? 'default' : 'secondary'}>
                                                {m.is_active ? 'Active' : 'Inactive'}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Button variant="ghost" size="sm" asChild>
                                                <Link href={route('nhif.service-mappings.show', m.id)}>View</Link>
                                            </Button>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination links={mappings.links} />
            </div>
        </AppLayout>
    );
}
