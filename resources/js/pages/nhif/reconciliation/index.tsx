import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Pagination } from '@/components/pagination';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NHIF', href: '/nhif/dashboard' },
    { title: 'Reconciliation', href: '/nhif/reconciliation' },
];

const statusColors: Record<string, string> = {
    pending: 'bg-yellow-100 text-yellow-700',
    confirmed: 'bg-green-100 text-green-700',
    disputed: 'bg-red-100 text-red-700',
};

interface Reconciliation {
    id: number;
    claim: { id: number; claim_number: string | null } | null;
    source: string;
    hms_status: string;
    amounts_confirmed: boolean;
    submitted_amount: number | null;
    nhif_approved_amount: number | null;
    variance_amount: number | null;
    hospital: { name: string } | null;
    reconciled_by: string | null;
    created_at: string;
}

interface Props {
    reconciliations: Paginated<Reconciliation>;
    filters: { status?: string };
}

export default function ReconciliationIndex({ reconciliations }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="NHIF Reconciliation" />

            <div className="flex h-full flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">NHIF Claim Reconciliation</h1>

                <div className="rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="border-b bg-muted/40">
                            <tr>
                                <th className="px-4 py-3 text-left font-medium">Claim #</th>
                                <th className="px-4 py-3 text-left font-medium">Source</th>
                                <th className="px-4 py-3 text-left font-medium">Status</th>
                                <th className="px-4 py-3 text-right font-medium">Submitted</th>
                                <th className="px-4 py-3 text-right font-medium">Approved</th>
                                <th className="px-4 py-3 text-right font-medium">Variance</th>
                                <th className="px-4 py-3 text-left font-medium">Reconciled By</th>
                                <th className="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {reconciliations.data.length === 0 ? (
                                <tr>
                                    <td colSpan={8} className="px-4 py-8 text-center text-muted-foreground">No reconciliations yet.</td>
                                </tr>
                            ) : (
                                reconciliations.data.map((r) => (
                                    <tr key={r.id} className="hover:bg-muted/30">
                                        <td className="px-4 py-3 font-mono text-xs">
                                            {r.claim ? (
                                                <Link href={route('nhif.claims.show', r.claim.id)} className="text-primary underline">
                                                    {r.claim.claim_number ?? `#${r.claim.id}`}
                                                </Link>
                                            ) : '—'}
                                        </td>
                                        <td className="px-4 py-3">{r.source}</td>
                                        <td className="px-4 py-3">
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${statusColors[r.hms_status] ?? ''}`}>
                                                {r.hms_status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right">{r.submitted_amount != null ? Number(r.submitted_amount).toLocaleString() : '—'}</td>
                                        <td className="px-4 py-3 text-right">{r.nhif_approved_amount != null ? Number(r.nhif_approved_amount).toLocaleString() : '—'}</td>
                                        <td className="px-4 py-3 text-right">
                                            {r.variance_amount != null ? (
                                                <span className={Number(r.variance_amount) > 0 ? 'text-orange-600' : ''}>
                                                    {Number(r.variance_amount).toLocaleString()}
                                                </span>
                                            ) : '—'}
                                        </td>
                                        <td className="px-4 py-3">{r.reconciled_by ?? '—'}</td>
                                        <td className="px-4 py-3">
                                            {r.claim && (
                                                <Button variant="ghost" size="sm" asChild>
                                                    <Link href={route('nhif.claims.reconciliation.show', r.claim.id)}>Details</Link>
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination links={reconciliations.links} />
            </div>
        </AppLayout>
    );
}
