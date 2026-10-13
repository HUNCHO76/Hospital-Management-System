import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { usePermissions } from '@/hooks/use-permissions';
import { Head, Link, router } from '@inertiajs/react';
import { AlertCircle, AlertTriangle, ArrowLeft, Filter, Plus, Printer } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Store & Inventory', href: '/stores' },
    { title: 'Reports', href: '/stores/reports' },
    { title: 'Reorder Alerts', href: '/stores/reports/reorder-alerts' },
];

interface AlertItem {
    stock_item_id: number;
    store_name: string;
    item_name: string;
    category: string;
    unit_of_measure: string;
    reorder_level: number;
    current_stock: number;
    deficit: number;
}

interface Props {
    report: {
        alerts: AlertItem[];
        critical_count: number;
        reorder_count: number;
    };
    stores: { id: number; name: string }[];
    filters: {
        store_id: number | null;
    };
}

export default function ReorderAlertsReport({ report, stores, filters }: Props) {
    const { can } = usePermissions();
    const [storeId, setStoreId] = useState(filters.store_id ? String(filters.store_id) : '');

    const handleFilter = () => {
        router.get('/stores/reports/reorder-alerts', {
            store_id: storeId || undefined,
        }, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Reorder Level Alerts - Stores" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <PageHeader
                        title="Reorder Level & Stockout Alerts"
                        description="Proactive inventory replenishing warnings identifying items at or below critical safety buffer levels."
                    />
                    <div className="flex items-center gap-2">
                        <Link href="/stores/reports">
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="mr-2 h-4 w-4" /> Back to Reports
                            </Button>
                        </Link>
                        {can('procurement-indents.create') && (
                            <Link href="/procurement/indents/create">
                                <Button size="sm">
                                    <Plus className="mr-2 h-4 w-4" /> Create Requisition Indent
                                </Button>
                            </Link>
                        )}
                        <Button size="sm" variant="outline" onClick={() => window.print()}>
                            <Printer className="mr-2 h-4 w-4" /> Print
                        </Button>
                    </div>
                </div>

                {/* KPI */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Card className="border-red-200 bg-red-50/40">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs uppercase text-red-700">Completely Out of Stock (Zero Balance)</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold text-red-600">
                                {report.critical_count} items
                            </div>
                            <p className="text-xs text-red-800 mt-1">Immediate stockout risk for clinical services</p>
                        </CardContent>
                    </Card>

                    <Card className="border-amber-200 bg-amber-50/40">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs uppercase text-amber-700">Below Reorder Threshold</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold text-amber-600">
                                {report.reorder_count} items
                            </div>
                            <p className="text-xs text-amber-800 mt-1">Requires purchase indent requisition</p>
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

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3">Item Description</th>
                                        <th className="px-4 py-3">Store</th>
                                        <th className="px-4 py-3">Category</th>
                                        <th className="px-4 py-3">Current Stock</th>
                                        <th className="px-4 py-3">Reorder Threshold</th>
                                        <th className="px-4 py-3">Replenishment Deficit</th>
                                        <th className="px-4 py-3 text-right">Stock Alert</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {report.alerts.length === 0 ? (
                                        <tr>
                                            <td colSpan={7} className="py-8 text-center text-muted-foreground">
                                                All items are sufficiently stocked above their reorder buffers.
                                            </td>
                                        </tr>
                                    ) : (
                                        report.alerts.map((al, idx) => {
                                            const isZero = Number(al.current_stock) === 0;

                                            return (
                                                <tr key={idx} className={isZero ? 'bg-red-50/40 hover:bg-red-50/60' : 'hover:bg-muted/40'}>
                                                    <td className="px-4 py-3 font-semibold text-primary">{al.item_name}</td>
                                                    <td className="px-4 py-3 text-muted-foreground">{al.store_name}</td>
                                                    <td className="px-4 py-3 capitalize text-muted-foreground">{al.category.replace('_', ' ')}</td>
                                                    <td className="px-4 py-3 font-bold">
                                                        <span className={isZero ? 'text-red-600' : 'text-amber-700'}>
                                                            {Number(al.current_stock).toLocaleString()} {al.unit_of_measure}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3 text-muted-foreground">{Number(al.reorder_level).toLocaleString()} {al.unit_of_measure}</td>
                                                    <td className="px-4 py-3 font-semibold text-red-600">
                                                        -{Number(al.deficit).toLocaleString()} {al.unit_of_measure}
                                                    </td>
                                                    <td className="px-4 py-3 text-right">
                                                        {isZero ? (
                                                            <Badge variant="destructive">OUT OF STOCK</Badge>
                                                        ) : (
                                                            <Badge className="bg-amber-600 text-white">REORDER NEEDED</Badge>
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })
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
