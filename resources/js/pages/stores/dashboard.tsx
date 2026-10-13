import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRightLeft,
    Boxes,
    Clock,
    DollarSign,
    PackageCheck,
    Plus,
    Send,
    Truck,
} from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Store & Inventory', href: '/stores' },
];

interface Metrics {
    total_valuation: number;
    total_items: number;
    pending_grns: number;
    pending_requests: number;
    expiring_batches: number;
}

interface Store {
    id: number;
    code: string;
    name: string;
    type: string;
    status: string;
}

interface Props {
    metrics: Metrics;
    stores: Store[];
    recent_grns: any[];
    recent_issues: any[];
    recent_transfers: any[];
}

export default function StoreDashboard({ metrics, stores, recent_grns, recent_issues, recent_transfers }: Props) {
    const { can } = usePermissions();
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Store & Inventory Management" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <PageHeader
                        title="Store & Inventory Hub"
                        description="Physical goods receiving (GRN), FEFO batch stock allocation, department outward requests, and inter-store transfers."
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        {can('stores-grn.create') && (
                            <Link href="/stores/grn/create">
                                <Button size="sm">
                                    <PackageCheck className="mr-2 h-4 w-4" /> Receive Goods (GRN)
                                </Button>
                            </Link>
                        )}
                        {can('stores-requests.create') && (
                            <Link href="/stores/requests/create">
                                <Button size="sm" variant="outline">
                                    <Send className="mr-2 h-4 w-4" /> Request Stock
                                </Button>
                            </Link>
                        )}
                        {can('stores-transfers.create') && (
                            <Link href="/stores/transfers/create">
                                <Button size="sm" variant="outline">
                                    <ArrowRightLeft className="mr-2 h-4 w-4" /> Inter-Store Transfer
                                </Button>
                            </Link>
                        )}
                    </div>
                </div>

                {/* KPI Metrics */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-xs font-medium uppercase text-muted-foreground">Inventory Valuation</CardTitle>
                            <DollarSign className="h-4 w-4 text-emerald-600" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-xl font-bold text-emerald-600">
                                TZS {metrics.total_valuation.toLocaleString()}
                            </div>
                            <p className="text-xs text-muted-foreground mt-1">Weighted batch cost basis</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-xs font-medium uppercase text-muted-foreground">Active Stock Items</CardTitle>
                            <Boxes className="h-4 w-4 text-blue-600" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{metrics.total_items}</div>
                            <p className="text-xs text-muted-foreground mt-1">In hospital pharmacy & stores</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-xs font-medium uppercase text-muted-foreground">Pending GRN QA</CardTitle>
                            <PackageCheck className="h-4 w-4 text-amber-600" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-amber-600">{metrics.pending_grns}</div>
                            <p className="text-xs text-muted-foreground mt-1">Goods awaiting physical inspection</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-xs font-medium uppercase text-muted-foreground">Dept Indent Requests</CardTitle>
                            <Clock className="h-4 w-4 text-purple-600" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-purple-600">{metrics.pending_requests}</div>
                            <p className="text-xs text-muted-foreground mt-1">Awaiting store allocation</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-xs font-medium uppercase text-muted-foreground">Expiring &lt; 60 Days</CardTitle>
                            <AlertTriangle className="h-4 w-4 text-red-600" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-red-600">{metrics.expiring_batches}</div>
                            <p className="text-xs text-muted-foreground mt-1">FEFO alert batches</p>
                        </CardContent>
                    </Card>
                </div>

                {/* Hospital Stores Hierarchy */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base font-semibold">Hospital Storage Locations & Sub-Stores</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                            {stores.map((s) => (
                                <div key={s.id} className="rounded-lg border p-3 bg-muted/20 hover:bg-muted/40 transition">
                                    <div className="flex items-center justify-between">
                                        <span className="font-semibold text-sm">{s.name}</span>
                                        <Badge variant="outline" className="text-xs capitalize">{s.type.replace('_', ' ')}</Badge>
                                    </div>
                                    <div className="text-xs text-muted-foreground mt-1">Code: {s.code}</div>
                                    <div className="mt-3 flex items-center justify-between text-xs">
                                        <Link href={`/stores/inventory?store_id=${s.id}`} className="text-primary hover:underline font-medium">
                                            View Balances &rarr;
                                        </Link>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                {/* Recent Activities Section */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    {/* Recent GRNs */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-3">
                            <CardTitle className="text-sm font-semibold">Recent Inward Receipts (GRN)</CardTitle>
                            <Link href="/stores/grn">
                                <Button variant="ghost" size="sm" className="text-xs">View All</Button>
                            </Link>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="divide-y text-xs">
                                {recent_grns.length === 0 ? (
                                    <div className="p-4 text-center text-muted-foreground">No recent receipts</div>
                                ) : (
                                    recent_grns.map((g: any) => (
                                        <div key={g.id} className="p-3 hover:bg-muted/50 flex items-center justify-between">
                                            <div>
                                                <Link href={`/stores/grn/${g.id}`} className="font-semibold text-primary hover:underline">
                                                    {g.grn_number}
                                                </Link>
                                                <div className="text-muted-foreground">{g.vendor?.name}</div>
                                            </div>
                                            <Badge variant="outline" className="capitalize text-[10px]">{g.status}</Badge>
                                        </div>
                                    ))
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Recent Issues */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-3">
                            <CardTitle className="text-sm font-semibold">Recent Department Issues</CardTitle>
                            <Link href="/stores/issues">
                                <Button variant="ghost" size="sm" className="text-xs">View All</Button>
                            </Link>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="divide-y text-xs">
                                {recent_issues.length === 0 ? (
                                    <div className="p-4 text-center text-muted-foreground">No recent issues</div>
                                ) : (
                                    recent_issues.map((iss: any) => (
                                        <div key={iss.id} className="p-3 hover:bg-muted/50 flex items-center justify-between">
                                            <div>
                                                <Link href={`/stores/issues/${iss.id}`} className="font-semibold text-primary hover:underline">
                                                    {iss.issue_number}
                                                </Link>
                                                <div className="text-muted-foreground">To: {iss.department?.name}</div>
                                            </div>
                                            <Badge variant="outline" className="capitalize text-[10px]">{iss.status}</Badge>
                                        </div>
                                    ))
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Recent Transfers */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-3">
                            <CardTitle className="text-sm font-semibold">Recent Inter-Store Transfers</CardTitle>
                            <Link href="/stores/transfers">
                                <Button variant="ghost" size="sm" className="text-xs">View All</Button>
                            </Link>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="divide-y text-xs">
                                {recent_transfers.length === 0 ? (
                                    <div className="p-4 text-center text-muted-foreground">No recent transfers</div>
                                ) : (
                                    recent_transfers.map((tr: any) => (
                                        <div key={tr.id} className="p-3 hover:bg-muted/50 flex items-center justify-between">
                                            <div>
                                                <Link href={`/stores/transfers/${tr.id}`} className="font-semibold text-primary hover:underline">
                                                    {tr.transfer_number}
                                                </Link>
                                                <div className="text-muted-foreground">
                                                    {tr.from_store?.name} &rarr; {tr.to_store?.name}
                                                </div>
                                            </div>
                                            <Badge variant="outline" className="capitalize text-[10px]">{tr.status}</Badge>
                                        </div>
                                    ))
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
