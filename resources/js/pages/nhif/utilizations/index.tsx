import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Pagination } from '@/components/pagination';
import { type FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NHIF', href: '/nhif/dashboard' },
    { title: 'Utilizations', href: '/nhif/utilizations' },
];

const statusColors: Record<string, string> = {
    pending: 'bg-gray-100 text-gray-700',
    submitted: 'bg-blue-100 text-blue-700',
    accepted: 'bg-green-100 text-green-700',
    rejected: 'bg-red-100 text-red-700',
    reconciled: 'bg-purple-100 text-purple-700',
    failed: 'bg-orange-100 text-orange-700',
    excluded: 'bg-gray-100 text-gray-500',
};

interface Utilization {
    id: number;
    utilization_number: string;
    module: string;
    status: string;
    nhif_item_code: string;
    nhif_item_name: string;
    quantity: number;
    hms_amount: number | null;
    nhif_approved_amount: number | null;
    attempt_count: number;
    hospital: { name: string } | null;
    patient: { id: number; name: string } | null;
    created_at: string;
}

interface Props {
    utilizations: Paginated<Utilization>;
    filters: { status?: string; module?: string; search?: string };
    statuses: string[];
    modules: string[];
}

export default function UtilizationsIndex({ utilizations, filters, statuses, modules }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');

    const applyFilter = (key: string, value: string) => {
        const val = value === 'all' ? undefined : (value || undefined);
        router.get(route('nhif.utilization.index'), { ...filters, [key]: val, search }, { preserveState: true, replace: true });
    };

    const submitSearch: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('nhif.utilization.index'), { ...filters, search }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="NHIF Utilizations" />

            <div className="flex h-full flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">NHIF Utilizations</h1>

                <div className="flex flex-wrap gap-2">
                    <Select value={filters.status || 'all'} onValueChange={(v) => applyFilter('status', v)}>
                        <SelectTrigger className="w-36"><SelectValue placeholder="All statuses" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            {statuses.map((s) => <SelectItem key={s} value={s}>{s}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Select value={filters.module || 'all'} onValueChange={(v) => applyFilter('module', v)}>
                        <SelectTrigger className="w-36"><SelectValue placeholder="All modules" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All modules</SelectItem>
                            {modules.map((m) => <SelectItem key={m} value={m}>{m}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <form onSubmit={submitSearch} className="flex gap-2">
                        <Input
                            placeholder="Utilization #…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="w-48"
                        />
                        <Button type="submit" variant="secondary" size="sm">Search</Button>
                    </form>
                </div>

                <div className="rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="border-b bg-muted/40">
                            <tr>
                                <th className="px-4 py-3 text-left font-medium">Utilization #</th>
                                <th className="px-4 py-3 text-left font-medium">Module</th>
                                <th className="px-4 py-3 text-left font-medium">NHIF Code</th>
                                <th className="px-4 py-3 text-left font-medium">Patient</th>
                                <th className="px-4 py-3 text-left font-medium">Status</th>
                                <th className="px-4 py-3 text-right font-medium">HMS Amt</th>
                                <th className="px-4 py-3 text-right font-medium">Approved</th>
                                <th className="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {utilizations.data.length === 0 ? (
                                <tr>
                                    <td colSpan={8} className="px-4 py-8 text-center text-muted-foreground">No utilizations found.</td>
                                </tr>
                            ) : (
                                utilizations.data.map((u) => (
                                    <tr key={u.id} className="hover:bg-muted/30">
                                        <td className="px-4 py-3 font-mono text-xs">{u.utilization_number}</td>
                                        <td className="px-4 py-3 capitalize">{u.module}</td>
                                        <td className="px-4 py-3 font-mono text-xs">{u.nhif_item_code}</td>
                                        <td className="px-4 py-3">{u.patient?.name ?? '—'}</td>
                                        <td className="px-4 py-3">
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${statusColors[u.status] ?? ''}`}>
                                                {u.status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right">{u.hms_amount != null ? Number(u.hms_amount).toLocaleString() : '—'}</td>
                                        <td className="px-4 py-3 text-right">{u.nhif_approved_amount != null ? Number(u.nhif_approved_amount).toLocaleString() : '—'}</td>
                                        <td className="px-4 py-3 text-right">
                                            <Button variant="ghost" size="sm" asChild>
                                                <Link href={route('nhif.utilization.show', u.id)}>View</Link>
                                            </Button>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination links={utilizations.links} />
            </div>
        </AppLayout>
    );
}
