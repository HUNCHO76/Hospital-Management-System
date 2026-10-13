import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Boxes, Eye, Filter, Layers, Search } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Store & Inventory', href: '/stores' },
    { title: 'Stock Ledger', href: '/stores/inventory' },
];

interface StockItemRow {
    id: number;
    current_stock: number;
    total_value: number;
    reorder_level?: number | null;
    store?: { id: number; name: string };
    procurement_item?: { id: number; name: string; code: string; category: string; unit_of_measure: string; reorder_level: number };
    medication?: { id: number; generic_name: string; brand_name: string };
}

interface Props {
    items: {
        data: StockItemRow[];
        links: any[];
        total: number;
    };
    stores: { id: number; name: string }[];
    categories: string[];
    filters: {
        store_id: number | null;
        category: string;
        search: string;
    };
}

export default function StoreInventoryIndex({ items, stores, categories, filters }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [storeId, setStoreId] = useState(filters.store_id ? String(filters.store_id) : '');
    const [category, setCategory] = useState(filters.category || '');

    const handleFilter = () => {
        router.get('/stores/inventory', {
            search: search || undefined,
            store_id: storeId || undefined,
            category: category || undefined,
        }, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Store Stock Balances - Store" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <PageHeader
                        title="Inventory Stock Balances"
                        description="Real-time stock valuation and quantities by store location, integrated with hospital pharmacy dispensary."
                    />
                    <div className="flex items-center gap-2">
                        <Link href="/stores/inventory/batches">
                            <Button variant="outline" size="sm">
                                <Layers className="mr-2 h-4 w-4" /> View Individual Batches
                            </Button>
                        </Link>
                    </div>
                </div>

                <Card>
                    <CardContent className="p-4 flex flex-col md:flex-row gap-3">
                        <div className="relative flex-1">
                            <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
                            <Input
                                placeholder="Search by item name or code..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && handleFilter()}
                                className="pl-9"
                            />
                        </div>

                        <select
                            value={storeId}
                            onChange={(e) => setStoreId(e.target.value)}
                            className="rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option value="">All Storage Facilities</option>
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
                            <Filter className="mr-2 h-4 w-4" /> Filter
                        </Button>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3">Item Code</th>
                                        <th className="px-4 py-3">Item Name</th>
                                        <th className="px-4 py-3">Store Location</th>
                                        <th className="px-4 py-3">Category</th>
                                        <th className="px-4 py-3">Current Stock</th>
                                        <th className="px-4 py-3">Valuation (TZS)</th>
                                        <th className="px-4 py-3 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {items.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={7} className="py-8 text-center text-muted-foreground">
                                                No stock inventory records found.
                                            </td>
                                        </tr>
                                    ) : (
                                        items.data.map((row) => {
                                            const itemName = row.procurement_item?.name || row.medication?.generic_name || 'Stock Item';
                                            const itemCode = row.procurement_item?.code || `SI-${row.id}`;
                                            const categoryName = row.procurement_item?.category || 'medicines';
                                            const uom = row.procurement_item?.unit_of_measure || 'units';
                                            const reorder = row.procurement_item?.reorder_level || 10;
                                            const isLow = Number(row.current_stock) <= reorder;

                                            return (
                                                <tr key={row.id} className="hover:bg-muted/50 transition">
                                                    <td className="px-4 py-3 font-mono font-medium text-primary">{itemCode}</td>
                                                    <td className="px-4 py-3 font-semibold">{itemName}</td>
                                                    <td className="px-4 py-3 text-muted-foreground">{row.store?.name}</td>
                                                    <td className="px-4 py-3 capitalize text-muted-foreground">{categoryName.replace('_', ' ')}</td>
                                                    <td className="px-4 py-3">
                                                        <div className="flex items-center gap-1 font-bold">
                                                            <span className={isLow ? 'text-red-600' : 'text-emerald-700'}>
                                                                {Number(row.current_stock).toLocaleString()} {uom}
                                                            </span>
                                                            {isLow && (
                                                                <Badge variant="destructive" className="text-[10px] py-0 px-1">
                                                                    Low Stock
                                                                </Badge>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="px-4 py-3 font-semibold">
                                                        TZS {Number(row.total_value).toLocaleString()}
                                                    </td>
                                                    <td className="px-4 py-3 text-right">
                                                        <Link href={`/stores/inventory/batches?search=${encodeURIComponent(itemCode)}`}>
                                                            <Button size="sm" variant="ghost">
                                                                <Eye className="mr-1 h-3.5 w-3.5" /> Batches
                                                            </Button>
                                                        </Link>
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
