import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type NhifDashboardStats } from '@/types/nhif';
import { Head, Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NHIF', href: '/nhif/dashboard' },
    { title: 'Dashboard', href: '/nhif/dashboard' },
];

interface RecentClaim {
    id: number;
    claim_number: string;
    status: string;
    claim_type: string;
    hms_total: number | null;
    nhif_approved_total: number | null;
    hospital: { name: string } | null;
    updated_at: string;
}

interface Props {
    stats: NhifDashboardStats;
    recentClaims: RecentClaim[];
}

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

export default function NhifDashboard({ stats, recentClaims }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="NHIF Dashboard" />

            <div className="flex flex-col gap-6 p-4">
                <h1 className="text-xl font-semibold">NHIF Operations Dashboard</h1>

                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                    <StatCard title="Verifications Today" value={stats.today_verifications} />
                    <StatCard title="Eligibility Today" value={stats.today_eligibility_checks} />
                    <StatCard title="Pending Preauth" value={stats.pending_preauthorizations} warn />
                    <StatCard title="Pending Utilizations" value={stats.pending_utilizations} warn />
                    <StatCard title="Open Discrepancies" value={stats.open_discrepancies} danger={stats.open_discrepancies > 0} />
                </div>

                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <StatCard title="Draft Claims" value={stats.draft_claims} />
                    <StatCard title="Submitted Claims" value={stats.submitted_claims} />
                    <div className="col-span-2 rounded-md border p-4">
                        <p className="text-xs text-muted-foreground">Last Catalog Sync</p>
                        <p className="mt-1 text-sm font-medium">
                            {stats.last_sync_at
                                ? new Date(stats.last_sync_at).toLocaleString()
                                : 'Never synced'}
                        </p>
                        <Link href={route('nhif.price-sync.index')} className="mt-1 text-xs text-primary underline">
                            Sync now →
                        </Link>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm font-medium">Recent Claims</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {recentClaims.length === 0 ? (
                            <p className="px-4 py-6 text-center text-sm text-muted-foreground">No claims yet.</p>
                        ) : (
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/40">
                                    <tr>
                                        <th className="px-4 py-2 text-left font-medium">Claim #</th>
                                        <th className="px-4 py-2 text-left font-medium">Type</th>
                                        <th className="px-4 py-2 text-left font-medium">Status</th>
                                        <th className="px-4 py-2 text-right font-medium">HMS Total</th>
                                        <th className="px-4 py-2 text-right font-medium">NHIF Approved</th>
                                        <th className="px-4 py-2 text-left font-medium">Updated</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {recentClaims.map((c) => (
                                        <tr key={c.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-2">
                                                <Link href={route('nhif.claims.show', c.id)} className="text-primary underline">
                                                    {c.claim_number}
                                                </Link>
                                            </td>
                                            <td className="px-4 py-2 capitalize">{c.claim_type}</td>
                                            <td className="px-4 py-2">
                                                <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${statusColors[c.status] ?? ''}`}>
                                                    {c.status}
                                                </span>
                                            </td>
                                            <td className="px-4 py-2 text-right">
                                                {c.hms_total != null ? Number(c.hms_total).toLocaleString() : '—'}
                                            </td>
                                            <td className="px-4 py-2 text-right">
                                                {c.nhif_approved_total != null ? Number(c.nhif_approved_total).toLocaleString() : '—'}
                                            </td>
                                            <td className="px-4 py-2 text-xs text-muted-foreground">
                                                {new Date(c.updated_at).toLocaleDateString()}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

function StatCard({ title, value, warn = false, danger = false }: { title: string; value: number; warn?: boolean; danger?: boolean }) {
    return (
        <div className={`rounded-md border p-4 ${danger ? 'border-red-200 bg-red-50' : warn && value > 0 ? 'border-yellow-200 bg-yellow-50' : ''}`}>
            <p className="text-xs text-muted-foreground">{title}</p>
            <p className={`mt-1 text-2xl font-bold ${danger ? 'text-red-600' : warn && value > 0 ? 'text-yellow-700' : ''}`}>{value}</p>
        </div>
    );
}
