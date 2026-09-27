import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Pagination } from '@/components/pagination';
import { Plus } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NHIF', href: '/nhif/dashboard' },
    { title: 'Claims', href: '/nhif/claims' },
];

const statusColors: Record<string, string> = {
    draft: 'bg-gray-100 text-gray-700',
    validated: 'bg-blue-100 text-blue-700',
    ready: 'bg-cyan-100 text-cyan-700',
    submitted: 'bg-yellow-100 text-yellow-700',
    accepted: 'bg-green-100 text-green-700',
    rejected: 'bg-red-100 text-red-700',
    reconciled: 'bg-purple-100 text-purple-700',
    cancelled: 'bg-gray-100 text-gray-500',
};

interface Claim {
    id: number;
    claim_number: string | null;
    status: string;
    claim_type: string;
    folio_no: string | null;
    hms_total: number | null;
    nhif_allowable_total: number | null;
    nhif_approved_total: number | null;
    hospital: { name: string } | null;
    patient: { id: number; name: string } | null;
    updated_at: string;
}

interface Props {
    claims: Paginated<Claim>;
    filters: { status?: string; claim_type?: string; search?: string };
    statuses: string[];
    claimTypes: string[];
}

export default function ClaimsIndex({ claims, filters, statuses, claimTypes }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');

    const applyFilter = (key: string, value: string) => {
        router.get(route('nhif.claims.index'), { ...filters, [key]: value || undefined, search }, { preserveState: true, replace: true });
    };

    const submitSearch: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('nhif.claims.index'), { ...filters, search }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="NHIF Claims" />

            <div className="flex h-full flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-semibold">NHIF Claims</h1>
                    <Button asChild>
                        <Link href="#" onClick={() => alert('Use the patient insurance record to generate a claim.')}>
                            <Plus className="mr-2 h-4 w-4" />
                            New Claim
                        </Link>
                    </Button>
                </div>

                <div className="flex flex-wrap gap-2">
                    <Select value={filters.status ?? ''} onValueChange={(v) => applyFilter('status', v)}>
                        <SelectTrigger className="w-36"><SelectValue placeholder="All statuses" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="">All statuses</SelectItem>
                            {statuses.map((s) => <SelectItem key={s} value={s}>{s}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Select value={filters.claim_type ?? ''} onValueChange={(v) => applyFilter('claim_type', v)}>
                        <SelectTrigger className="w-36"><SelectValue placeholder="All types" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="">All types</SelectItem>
                            {claimTypes.map((t) => <SelectItem key={t} value={t}>{t}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <form onSubmit={submitSearch} className="flex gap-2">
                        <Input
                            placeholder="Claim #…"
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
                                <th className="px-4 py-3 text-left font-medium">Claim #</th>
                                <th className="px-4 py-3 text-left font-medium">Type</th>
                                <th className="px-4 py-3 text-left font-medium">Folio</th>
                                <th className="px-4 py-3 text-left font-medium">Patient</th>
                                <th className="px-4 py-3 text-left font-medium">Status</th>
                                <th className="px-4 py-3 text-right font-medium">HMS Total</th>
                                <th className="px-4 py-3 text-right font-medium">Approved</th>
                                <th className="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {claims.data.length === 0 ? (
                                <tr>
                                    <td colSpan={8} className="px-4 py-8 text-center text-muted-foreground">No claims found.</td>
                                </tr>
                            ) : (
                                claims.data.map((c) => (
                                    <tr key={c.id} className="hover:bg-muted/30">
                                        <td className="px-4 py-3 font-mono text-xs">{c.claim_number ?? '—'}</td>
                                        <td className="px-4 py-3 capitalize">{c.claim_type}</td>
                                        <td className="px-4 py-3 font-mono text-xs">{c.folio_no ?? '—'}</td>
                                        <td className="px-4 py-3">{c.patient?.name ?? '—'}</td>
                                        <td className="px-4 py-3">
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${statusColors[c.status] ?? ''}`}>
                                                {c.status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right">{c.hms_total != null ? Number(c.hms_total).toLocaleString() : '—'}</td>
                                        <td className="px-4 py-3 text-right">{c.nhif_approved_total != null ? Number(c.nhif_approved_total).toLocaleString() : '—'}</td>
                                        <td className="px-4 py-3 text-right">
                                            <Button variant="ghost" size="sm" asChild>
                                                <Link href={route('nhif.claims.show', c.id)}>View</Link>
                                            </Button>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination links={claims.links} />
            </div>
        </AppLayout>
    );
}
