import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Edit3, Filter, Plus, Search } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Procurement', href: '/procurement' },
    { title: 'Catalog Items', href: '/procurement/items' },
];

interface ProcurementItem {
    id: number;
    code: string;
    name: string;
    category: string;
    unit_of_measure: string;
    estimated_unit_cost: number | null;
    reorder_level: number | null;
    minimum_stock: number | null;
    status: 'active' | 'inactive';
}

interface Props {
    items: {
        data: ProcurementItem[];
        links: any[];
        total: number;
    };
    categories: string[];
    filters: {
        category: string;
        status: string;
        search: string;
    };
}

export default function ItemsIndex({ items, categories, filters }: Props) {
    const { can } = usePermissions();
    const [search, setSearch] = useState(filters.search || '');
    const [category, setCategory] = useState(filters.category || '');
    const [status, setStatus] = useState(filters.status || '');

    const handleFilter = () => {
        router.get('/procurement/items', {
            search: search || undefined,
            category: category || undefined,
            status: status || undefined,
        }, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Procurement Master Catalog" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <PageHeader
                        title="Master Item Catalog"
                        description="Central hospital item registry across medicines, surgical consumables, lab reagents, radiology, and general stores."
                    />
                    {can('procurement-items.create') && (
                        <Link href="/procurement/items/create">
                            <Button>
                                <Plus className="mr-2 h-4 w-4" /> Add Catalog Item
                            </Button>
                        </Link>
                    )}
                </div>

                <Card>
                    <CardContent className="p-4 flex flex-col md:flex-row gap-3">
                        <div className="relative flex-1">
                            <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
                            <Input
                                placeholder="Search by item name, code, specification..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && handleFilter()}
                                className="pl-9"
                            />
                        </div>

                        <select
                            value={category}
                            onChange={(e) => setCategory(e.target.value)}
                            className="rounded-md border border-input bg-background px-3 py-2 text-sm capitalize"
                        >
                            <option value="">All Categories</option>
                            {categories.map((c) => (
                                <option key={c} value={c}>
                                    {c.replace('_', ' ')}
                                </option>
                            ))}
                        </select>

                        <select
                            value={status}
                            onChange={(e) => setStatus(e.target.value)}
                            className="rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option value="">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
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
                                        <th className="px-4 py-3">Code</th>
                                        <th className="px-4 py-3">Item Name</th>
                                        <th className="px-4 py-3">Category</th>
                                        <th className="px-4 py-3">UOM</th>
                                        <th className="px-4 py-3">Est. Unit Cost</th>
                                        <th className="px-4 py-3">Reorder Level</th>
                                        <th className="px-4 py-3">Status</th>
                                        <th className="px-4 py-3 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {items.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={8} className="py-8 text-center text-muted-foreground">
                                                No catalog items found.
                                            </td>
                                        </tr>
                                    ) : (
                                        items.data.map((item) => (
                                            <tr key={item.id} className="hover:bg-muted/50 transition">
                                                <td className="px-4 py-3 font-mono font-medium text-primary">{item.code}</td>
                                                <td className="px-4 py-3 font-semibold">{item.name}</td>
                                                <td className="px-4 py-3 capitalize text-muted-foreground">
                                                    {item.category.replace('_', ' ')}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">{item.unit_of_measure}</td>
                                                <td className="px-4 py-3 font-medium">
                                                    {item.estimated_unit_cost ? `TZS ${Number(item.estimated_unit_cost).toLocaleString()}` : '—'}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">{item.reorder_level || '—'}</td>
                                                <td className="px-4 py-3">
                                                    <Badge variant={item.status === 'active' ? 'default' : 'outline'} className="capitalize">
                                                        {item.status}
                                                    </Badge>
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    {can('procurement-items.update') && (
                                                        <Link href={`/procurement/items/${item.id}/edit`}>
                                                            <Button size="sm" variant="ghost">
                                                                <Edit3 className="mr-1 h-3.5 w-3.5" /> Edit
                                                            </Button>
                                                        </Link>
                                                    )}
                                                </td>
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
