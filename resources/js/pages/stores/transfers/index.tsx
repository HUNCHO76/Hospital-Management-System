import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { usePermissions } from '@/hooks/use-permissions';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowRightLeft, CheckCircle2, Eye, Filter, Plus, Search, Truck } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Store & Inventory', href: '/stores' },
    { title: 'Inter-Store Transfers', href: '/stores/transfers' },
];

interface StoreTransfer {
    id: number;
    transfer_number: string;
    from_store: { id: number; name: string };
    to_store: { id: number; name: string };
    transfer_date: string;
    requested_by?: { name: string };
    status: 'draft' | 'submitted' | 'approved' | 'in_transit' | 'received' | 'cancelled';
}

interface Props {
    transfers: {
        data: StoreTransfer[];
        links: any[];
        total: number;
    };
    stores: { id: number; name: string }[];
    filters: {
        status: string;
        from_store_id: number | null;
        to_store_id: number | null;
        search: string;
    };
}

export default function StoreTransfersIndex({ transfers, stores, filters }: Props) {
    const { can } = usePermissions();
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || '');
    const [fromStoreId, setFromStoreId] = useState(filters.from_store_id ? String(filters.from_store_id) : '');
    const [toStoreId, setToStoreId] = useState(filters.to_store_id ? String(filters.to_store_id) : '');

    const handleFilter = () => {
        router.get('/stores/transfers', {
            search: search || undefined,
            status: status || undefined,
            from_store_id: fromStoreId || undefined,
            to_store_id: toStoreId || undefined,
        }, { preserveState: true });
    };

    const getStatusBadge = (st: string) => {
        switch (st) {
            case 'draft':
                return <Badge variant="outline">Draft</Badge>;
            case 'submitted':
                return <Badge variant="secondary" className="bg-yellow-100 text-yellow-800">Submitted</Badge>;
            case 'approved':
                return <Badge variant="secondary" className="bg-blue-100 text-blue-800">Approved</Badge>;
            case 'in_transit':
                return <Badge variant="secondary" className="bg-indigo-100 text-indigo-800 flex items-center gap-1"><Truck className="h-3 w-3" /> In Transit</Badge>;
            case 'received':
                return <Badge variant="secondary" className="bg-green-100 text-green-800 font-semibold flex items-center gap-1"><CheckCircle2 className="h-3 w-3" /> Completed</Badge>;
            case 'cancelled':
                return <Badge variant="secondary" className="bg-red-100 text-red-800">Cancelled</Badge>;
            default:
                return <Badge variant="outline">{st}</Badge>;
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Inter-Store Transfers - Store" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <PageHeader
                        title="Inter-Store Stock Transfers"
                        description="Move stock batches between hospital storage units (e.g. Main Central Store to ICU Sub-Store) with transit auditing."
                    />
                    {can('stores-transfers.create') && (
                        <Link href="/stores/transfers/create">
                            <Button>
                                <Plus className="mr-2 h-4 w-4" /> New Store Transfer
                            </Button>
                        </Link>
                    )}
                </div>

                <Card>
                    <CardContent className="p-4 flex flex-col md:flex-row gap-3">
                        <div className="relative flex-1">
                            <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
                            <Input
                                placeholder="Search transfer number..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && handleFilter()}
                                className="pl-9"
                            />
                        </div>

                        <select
                            value={fromStoreId}
                            onChange={(e) => setFromStoreId(e.target.value)}
                            className="rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option value="">All Source Stores</option>
                            {stores.map((s) => (
                                <option key={s.id} value={s.id}>{s.name}</option>
                            ))}
                        </select>

                        <select
                            value={toStoreId}
                            onChange={(e) => setToStoreId(e.target.value)}
                            className="rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option value="">All Destination Stores</option>
                            {stores.map((s) => (
                                <option key={s.id} value={s.id}>{s.name}</option>
                            ))}
                        </select>

                        <select
                            value={status}
                            onChange={(e) => setStatus(e.target.value)}
                            className="rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option value="">All Statuses</option>
                            <option value="draft">Draft</option>
                            <option value="submitted">Submitted</option>
                            <option value="in_transit">In Transit</option>
                            <option value="received">Completed</option>
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
                                        <th className="px-4 py-3">Transfer #</th>
                                        <th className="px-4 py-3">From Store</th>
                                        <th className="px-4 py-3">To Store</th>
                                        <th className="px-4 py-3">Date</th>
                                        <th className="px-4 py-3">Initiated By</th>
                                        <th className="px-4 py-3">Status</th>
                                        <th className="px-4 py-3 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {transfers.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={7} className="py-8 text-center text-muted-foreground">
                                                No inter-store transfers found.
                                            </td>
                                        </tr>
                                    ) : (
                                        transfers.data.map((tr) => (
                                            <tr key={tr.id} className="hover:bg-muted/50 transition">
                                                <td className="px-4 py-3 font-semibold text-primary">
                                                    <Link href={`/stores/transfers/${tr.id}`} className="hover:underline">
                                                        {tr.transfer_number}
                                                    </Link>
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">{tr.from_store?.name}</td>
                                                <td className="px-4 py-3 font-medium text-blue-700">{tr.to_store?.name}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{tr.transfer_date}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{tr.requested_by?.name || 'Staff'}</td>
                                                <td className="px-4 py-3">{getStatusBadge(tr.status)}</td>
                                                <td className="px-4 py-3 text-right">
                                                    <Link href={`/stores/transfers/${tr.id}`}>
                                                        <Button size="sm" variant="ghost">
                                                            <Eye className="mr-1 h-3.5 w-3.5" /> View
                                                        </Button>
                                                    </Link>
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
