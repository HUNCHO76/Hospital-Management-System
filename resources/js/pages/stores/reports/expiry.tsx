import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { AlertOctagon, AlertTriangle, ArrowLeft, Clock, Filter, Printer } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Store & Inventory', href: '/stores' },
    { title: 'Reports', href: '/stores/reports' },
    { title: 'Stock Expiry Tracking', href: '/stores/reports/expiry' },
];

interface ExpiryBatch {
    id: number;
    batch_number: string;
    expiry_date: string;
    quantity: number;
    unit_cost: number | null;
    status: string;
    store_name: string;
    item_name: string;
    category: string;
    expiry_risk: 'EXPIRED' | 'EXPIRING_30_DAYS' | 'EXPIRING_60_DAYS' | 'EXPIRING_90_DAYS';
}

interface Props {
    report: {
        batches: ExpiryBatch[];
        summary: {
            expired_count: number;
            expired_qty: number;
            expiring_30_days_count: number;
            expiring_60_days_count: number;
            expiring_90_days_count: number;
        };
    };
    stores: { id: number; name: string }[];
    filters: {
        store_id: number | null;
    };
}

export default function StockExpiryReport({ report, stores, filters }: Props) {
    const [storeId, setStoreId] = useState(filters.store_id ? String(filters.store_id) : '');

    const handleFilter = () => {
        router.get('/stores/reports/expiry', {
            store_id: storeId || undefined,
        }, { preserveState: true });
    };

    const getRiskBadge = (risk: string) => {
        switch (risk) {
            case 'EXPIRED':
                return <Badge variant="destructive" className="flex items-center gap-1"><AlertOctagon className="h-3 w-3" /> EXPIRED</Badge>;
            case 'EXPIRING_30_DAYS':
                return <Badge className="bg-amber-600 text-white flex items-center gap-1"><AlertTriangle className="h-3 w-3" /> &lt; 30 Days</Badge>;
            case 'EXPIRING_60_DAYS':
                return <Badge className="bg-yellow-500 text-white flex items-center gap-1"><Clock className="h-3 w-3" /> &lt; 60 Days</Badge>;
            case 'EXPIRING_90_DAYS':
                return <Badge variant="secondary" className="bg-blue-100 text-blue-800">&lt; 90 Days</Badge>;
            default:
                return <Badge variant="outline">{risk}</Badge>;
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stock Expiry Report - Stores" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <PageHeader
                        title="Stock Expiry & Shelf-Life Report (FEFO)"
                        description="Proactive tracking of expiring medicine batches to enforce First-Expiry-First-Out dispensing and prevent stock waste."
                    />
                    <div className="flex items-center gap-2">
                        <Link href="/stores/reports">
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="mr-2 h-4 w-4" /> Back to Reports
                            </Button>
                        </Link>
                        <Button size="sm" onClick={() => window.print()}>
                            <Printer className="mr-2 h-4 w-4" /> Print / Export
                        </Button>
                    </div>
                </div>

                {/* KPI Breakdown */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <Card className="border-red-200 bg-red-50/30">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs uppercase text-red-700">Already Expired</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-red-600">
                                {report.summary.expired_count} batches
                            </div>
                            <p className="text-xs text-red-700 mt-1">({report.summary.expired_qty} units require disposal)</p>
                        </CardContent>
                    </Card>

                    <Card className="border-amber-200 bg-amber-50/30">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs uppercase text-amber-700">Expiring &lt; 30 Days</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-amber-600">
                                {report.summary.expiring_30_days_count} batches
                            </div>
                            <p className="text-xs text-muted-foreground mt-1">Urgent dispensary prioritization</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs uppercase text-muted-foreground">Expiring &lt; 60 Days</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-yellow-600">
                                {report.summary.expiring_60_days_count} batches
                            </div>
                            <p className="text-xs text-muted-foreground mt-1">FEFO active monitoring</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs uppercase text-muted-foreground">Expiring &lt; 90 Days</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-blue-600">
                                {report.summary.expiring_90_days_count} batches
                            </div>
                            <p className="text-xs text-muted-foreground mt-1">Approaching quarterly threshold</p>
                        </CardContent>
                    </Card>
                </div>

                {/* Filter */}
                <Card>
                    <CardContent className="p-4 flex flex-col md:flex-row gap-3">
                        <select
                            value={storeId}
                            onChange={(e) => setStoreId(e.target.value)}
                            className="rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option value="">All Storage Locations</option>
                            {stores.map((s) => (
                                <option key={s.id} value={s.id}>{s.name}</option>
                            ))}
                        </select>

                        <Button onClick={handleFilter} variant="secondary">
                            <Filter className="mr-2 h-4 w-4" /> Filter Store
                        </Button>
                    </CardContent>
                </Card>

                {/* Batches Table */}
                <Card>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3">Item Description</th>
                                        <th className="px-4 py-3">Store</th>
                                        <th className="px-4 py-3">Batch Number</th>
                                        <th className="px-4 py-3">Expiry Date</th>
                                        <th className="px-4 py-3">Quantity</th>
                                        <th className="px-4 py-3">Unit Valuation</th>
                                        <th className="px-4 py-3 text-right">Risk Level</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {report.batches.length === 0 ? (
                                        <tr>
                                            <td colSpan={7} className="py-8 text-center text-muted-foreground">
                                                No expiring batches within 90 days. Stock shelf-life is optimal.
                                            </td>
                                        </tr>
                                    ) : (
                                        report.batches.map((b) => (
                                            <tr key={b.id} className="hover:bg-muted/40">
                                                <td className="px-4 py-3 font-semibold text-primary">{b.item_name}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{b.store_name}</td>
                                                <td className="px-4 py-3 font-mono text-xs">{b.batch_number}</td>
                                                <td className="px-4 py-3 font-medium">{b.expiry_date ? b.expiry_date.split('T')[0] : '—'}</td>
                                                <td className="px-4 py-3 font-bold">{Number(b.quantity).toLocaleString()}</td>
                                                <td className="px-4 py-3">
                                                    {b.unit_cost ? `TZS ${Number(b.unit_cost).toLocaleString()}` : '—'}
                                                </td>
                                                <td className="px-4 py-3 text-right">{getRiskBadge(b.expiry_risk)}</td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
