import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, DollarSign, Filter, Printer } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Store & Inventory', href: '/stores' },
    { title: 'Reports', href: '/stores/reports' },
    { title: 'Stock Valuation', href: '/stores/reports/stock-valuation' },
];

interface ValuationItem {
    store_id: number;
    store_name: string;
    stock_item_id: number;
    item_name: string;
    category: string;
    unit_of_measure: string;
    batch_number: string;
    expiry_date: string;
    quantity: number;
    unit_cost: number;
    total_valuation: number;
}

interface Props {
    report: {
        items: ValuationItem[];
        summary: {
            total_quantity: number;
            total_valuation: number;
            batch_count: number;
        };
    };
    stores: { id: number; name: string }[];
    categories: string[];
    filters: {
        store_id: number | null;
        category: string | null;
    };
}

export default function StockValuationReport({ report, stores, categories, filters }: Props) {
    const [storeId, setStoreId] = useState(filters.store_id ? String(filters.store_id) : '');
    const [category, setCategory] = useState(filters.category || '');

    const handleFilter = () => {
        router.get('/stores/reports/stock-valuation', {
            store_id: storeId || undefined,
            category: category || undefined,
        }, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stock Valuation Report - Stores" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <PageHeader
                        title="Inventory Stock Valuation Report"
                        description="Comprehensive stock assessment based on active batch quantities and unit purchase acquisition costs."
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

                {/* Summary Cards */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs uppercase text-muted-foreground">Total Stock Valuation</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-emerald-600">
                                TZS {Number(report.summary.total_valuation).toLocaleString()}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs uppercase text-muted-foreground">Total Physical Units</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">
                                {Number(report.summary.total_quantity).toLocaleString()}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs uppercase text-muted-foreground">Active Monitored Batches</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-blue-600">
                                {report.summary.batch_count}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filter Toolbar */}
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

                        <select
                            value={category}
                            onChange={(e) => setCategory(e.target.value)}
                            className="rounded-md border border-input bg-background px-3 py-2 text-sm capitalize"
                        >
                            <option value="">All Categories</option>
                            {categories.map((c) => (
                                <option key={c} value={c}>{c.replace('_', ' ')}</option>
                            ))}
                        </select>

                        <Button onClick={handleFilter} variant="secondary">
                            <Filter className="mr-2 h-4 w-4" /> Apply Filters
                        </Button>
                    </CardContent>
                </Card>

                {/* Valuation Table */}
                <Card>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3">Storage Location</th>
                                        <th className="px-4 py-3">Item Description</th>
                                        <th className="px-4 py-3">Category</th>
                                        <th className="px-4 py-3">Batch #</th>
                                        <th className="px-4 py-3">Expiry Date</th>
                                        <th className="px-4 py-3">Quantity</th>
                                        <th className="px-4 py-3">Unit Cost (TZS)</th>
                                        <th className="px-4 py-3 text-right">Total Valuation (TZS)</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {report.items.length === 0 ? (
                                        <tr>
                                            <td colSpan={8} className="py-8 text-center text-muted-foreground">
                                                No stock valuation data found.
                                            </td>
                                        </tr>
                                    ) : (
                                        report.items.map((it, idx) => (
                                            <tr key={idx} className="hover:bg-muted/40">
                                                <td className="px-4 py-3 font-medium">{it.store_name}</td>
                                                <td className="px-4 py-3 font-semibold text-primary">{it.item_name}</td>
                                                <td className="px-4 py-3 capitalize text-muted-foreground">{it.category.replace('_', ' ')}</td>
                                                <td className="px-4 py-3 font-mono text-xs">{it.batch_number}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{it.expiry_date ? it.expiry_date.split('T')[0] : '—'}</td>
                                                <td className="px-4 py-3 font-bold">{Number(it.quantity).toLocaleString()} {it.unit_of_measure}</td>
                                                <td className="px-4 py-3">TZS {Number(it.unit_cost).toLocaleString()}</td>
                                                <td className="px-4 py-3 text-right font-semibold text-emerald-700">
                                                    TZS {Number(it.total_valuation).toLocaleString()}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                                <tfoot>
                                    <tr className="bg-muted/50 font-bold text-primary">
                                        <td colSpan={7} className="px-4 py-3 text-right text-base">Grand Inventory Valuation:</td>
                                        <td className="px-4 py-3 text-right text-base text-emerald-800">
                                            TZS {Number(report.summary.total_valuation).toLocaleString()}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
