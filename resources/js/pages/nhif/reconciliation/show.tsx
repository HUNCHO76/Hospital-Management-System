import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NHIF', href: '/nhif/dashboard' },
    { title: 'Reconciliation', href: '/nhif/reconciliation' },
    { title: 'Claim Reconciliation', href: '#' },
];

const discrepancyStatusColors: Record<string, string> = {
    open: 'bg-red-100 text-red-700',
    under_review: 'bg-yellow-100 text-yellow-700',
    resolved: 'bg-green-100 text-green-700',
    accepted: 'bg-blue-100 text-blue-700',
};

interface Reconciliation {
    id: number; source: string; hms_status: string; amounts_confirmed: boolean;
    submitted_amount: number | null; nhif_approved_amount: number | null; variance_amount: number | null;
    discrepancies_count: number; created_at: string;
}

interface Discrepancy {
    id: number; category: string; status: string; description: string | null;
    submitted_amount: number | null; nhif_amount: number | null; variance_amount: number | null;
    resolution_notes: string | null; created_at: string;
}

interface Claim {
    id: number; claim_number: string | null; status: string;
    hms_total: number | null; nhif_approved_total: number | null;
    reconciliation_status: string | null; last_reconciled_at: string | null;
    hospital: { name: string } | null; patient: { name: string } | null;
}

interface Props {
    claim: Claim;
    reconciliations: Reconciliation[];
    discrepancies: Discrepancy[];
}

export default function ReconciliationShow({ claim, reconciliations, discrepancies }: Props) {
    const reconcile = () => {
        const amount = prompt('NHIF approved amount (TZS):');
        const source = prompt('Source (e.g. portal, manual):');
        if (amount && source) {
            router.post(route('nhif.claims.reconciliation.reconcile', claim.id), {
                nhif_approved_amount: amount,
                source,
            });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Reconciliation: ${claim.claim_number ?? claim.id}`} />

            <div className="mx-auto max-w-3xl p-4">
                <div className="mb-4 flex items-center justify-between">
                    <h1 className="text-xl font-semibold">Claim {claim.claim_number ?? `#${claim.id}`} — Reconciliation</h1>
                    <Button size="sm" onClick={reconcile}>Record Reconciliation</Button>
                </div>

                <div className="mb-6 rounded-md border p-4 text-sm">
                    <dl className="grid grid-cols-2 gap-2">
                        <dt className="text-muted-foreground">Patient</dt><dd>{claim.patient?.name ?? '—'}</dd>
                        <dt className="text-muted-foreground">Hospital</dt><dd>{claim.hospital?.name ?? '—'}</dd>
                        <dt className="text-muted-foreground">HMS Total</dt><dd>{claim.hms_total != null ? Number(claim.hms_total).toLocaleString() : '—'} TZS</dd>
                        <dt className="text-muted-foreground">NHIF Approved</dt><dd>{claim.nhif_approved_total != null ? Number(claim.nhif_approved_total).toLocaleString() : '—'} TZS</dd>
                        <dt className="text-muted-foreground">Recon Status</dt><dd className="capitalize">{claim.reconciliation_status ?? '—'}</dd>
                        <dt className="text-muted-foreground">Last Reconciled</dt><dd>{claim.last_reconciled_at ? new Date(claim.last_reconciled_at).toLocaleString() : 'Never'}</dd>
                    </dl>
                </div>

                <h2 className="mb-2 text-sm font-medium">Reconciliation Records ({reconciliations.length})</h2>
                <div className="mb-6 rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="border-b bg-muted/40">
                            <tr>
                                <th className="px-3 py-2 text-left font-medium">Source</th>
                                <th className="px-3 py-2 text-left font-medium">Status</th>
                                <th className="px-3 py-2 text-right font-medium">Submitted</th>
                                <th className="px-3 py-2 text-right font-medium">Approved</th>
                                <th className="px-3 py-2 text-right font-medium">Variance</th>
                                <th className="px-3 py-2 text-left font-medium">Date</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {reconciliations.length === 0 ? (
                                <tr><td colSpan={6} className="px-4 py-4 text-center text-muted-foreground">No reconciliation records.</td></tr>
                            ) : reconciliations.map((r) => (
                                <tr key={r.id}>
                                    <td className="px-3 py-2">{r.source}</td>
                                    <td className="px-3 py-2 capitalize">{r.hms_status}</td>
                                    <td className="px-3 py-2 text-right">{r.submitted_amount != null ? Number(r.submitted_amount).toLocaleString() : '—'}</td>
                                    <td className="px-3 py-2 text-right">{r.nhif_approved_amount != null ? Number(r.nhif_approved_amount).toLocaleString() : '—'}</td>
                                    <td className="px-3 py-2 text-right">{r.variance_amount != null ? Number(r.variance_amount).toLocaleString() : '—'}</td>
                                    <td className="px-3 py-2 text-xs">{new Date(r.created_at).toLocaleDateString()}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {discrepancies.length > 0 && (
                    <>
                        <h2 className="mb-2 text-sm font-medium">Discrepancies ({discrepancies.length})</h2>
                        <div className="rounded-md border">
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/40">
                                    <tr>
                                        <th className="px-3 py-2 text-left font-medium">Category</th>
                                        <th className="px-3 py-2 text-left font-medium">Status</th>
                                        <th className="px-3 py-2 text-right font-medium">Variance</th>
                                        <th className="px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {discrepancies.map((d) => (
                                        <tr key={d.id}>
                                            <td className="px-3 py-2 capitalize">{d.category.replace('_', ' ')}</td>
                                            <td className="px-3 py-2">
                                                <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${discrepancyStatusColors[d.status] ?? ''}`}>
                                                    {d.status}
                                                </span>
                                            </td>
                                            <td className="px-3 py-2 text-right">{d.variance_amount != null ? Number(d.variance_amount).toLocaleString() : '—'}</td>
                                            <td className="px-3 py-2">
                                                {d.status === 'open' && (
                                                    <Button variant="ghost" size="sm" onClick={() => {
                                                        const notes = prompt('Resolution notes:');
                                                        if (notes) {
                                                            router.post(route('nhif.claims.reconciliation.discrepancies.resolve', [claim.id, d.id]), {
                                                                resolution_notes: notes,
                                                                status: 'resolved',
                                                            });
                                                        }
                                                    }}>Resolve</Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}

                <div className="mt-4">
                    <Button variant="ghost" asChild>
                        <Link href={route('nhif.claims.show', claim.id)}>← Back to claim</Link>
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
