import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Pagination } from '@/components/pagination';
import { NhifNewClaimDialog } from '@/components/nhif/nhif-new-claim-dialog';
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
    signed: 'bg-indigo-100 text-indigo-700',
    submitted: 'bg-amber-100 text-amber-700',
    bill_confirmation_requested: 'bg-orange-100 text-orange-700',
    confirmed: 'bg-emerald-100 text-emerald-700',
    finalized: 'bg-green-100 text-green-700',
    accepted: 'bg-green-100 text-green-700',
    rejected: 'bg-red-100 text-red-700',
    signing_failed: 'bg-rose-100 text-rose-700',
    submission_failed: 'bg-red-100 text-red-700',
    bill_confirmation_failed: 'bg-orange-100 text-orange-700',
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
        const val = value === 'all' ? undefined : (value || undefined);
        router.get(route('nhif.claims.index'), { ...filters, [key]: val, search }, { preserveState: true, replace: true });
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
                    <div>
                        <h1 className="text-xl font-semibold">NHIF Claims</h1>
                        <p className="text-xs text-muted-foreground">Manage and finalize patient folios and monthly claim consolidations.</p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Link href={route('nhif.claims.monthly')}>
                            <Button variant="outline" size="sm" className="gap-2">
                                <Plus className="w-4 h-4" />
                                Monthly Claim Submission
                            </Button>
                        </Link>
                        <NhifNewClaimDialog />
                    </div>
                </div>

                <div className="flex flex-wrap gap-2">
                    <Select value={filters.status || 'all'} onValueChange={(v) => applyFilter('status', v)}>
                        <SelectTrigger className="w-36"><SelectValue placeholder="All statuses" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            {statuses.map((s) => <SelectItem key={s} value={s}>{s}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Select value={filters.claim_type || 'all'} onValueChange={(v) => applyFilter('claim_type', v)}>
                        <SelectTrigger className="w-36"><SelectValue placeholder="All types" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All types</SelectItem>
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
