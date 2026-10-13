import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Eye, Filter, PackageCheck, Plus, Search } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Store & Inventory', href: '/stores' },
    { title: 'Goods Receipt Notes (GRN)', href: '/stores/grn' },
];

interface GRN {
    id: number;
    grn_number: string;
    delivery_note_number: string | null;
    invoice_reference: string | null;
    purchase_order?: { id: number; po_number: string } | null;
    vendor?: { id: number; name: string } | null;
    store: { id: number; name: string };
    received_date: string;
    status: 'inspection_pending' | 'partially_accepted' | 'accepted' | 'rejected' | 'posted';
    total_amount: number;
}

interface Props {
    grns: {
        data: GRN[];
        links: any[];
        total: number;
    };
    stores: { id: number; name: string }[];
    filters: {
        status: string;
        store_id: number | null;
        search: string;
    };
}

export default function GRNIndex({ grns, stores, filters }: Props) {
    const { can } = usePermissions();
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || '');
    const [storeId, setStoreId] = useState(filters.store_id ? String(filters.store_id) : '');

    const handleFilter = () => {
        router.get('/stores/grn', {
            search: search || undefined,
            status: status || undefined,
            store_id: storeId || undefined,
        }, { preserveState: true });
    };

    const getStatusBadge = (st: string) => {
        switch (st) {
            case 'inspection_pending':
                return <Badge variant="secondary" className="bg-yellow-100 text-yellow-800">Inspection Pending</Badge>;
            case 'partially_accepted':
                return <Badge variant="secondary" className="bg-amber-100 text-amber-800">Partially Accepted</Badge>;
            case 'accepted':
                return <Badge variant="secondary" className="bg-blue-100 text-blue-800">Accepted (Ready to Post)</Badge>;
            case 'posted':
                return <Badge variant="secondary" className="bg-green-100 text-green-800 font-semibold flex items-center gap-1"><CheckCircle2 className="h-3 w-3" /> Posted to Stock</Badge>;
            case 'rejected':
                return <Badge variant="secondary" className="bg-red-100 text-red-800">Rejected</Badge>;
            default:
                return <Badge variant="outline">{st}</Badge>;
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Goods Receipt Notes (GRN) - Store" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <PageHeader
                        title="Goods Receipt Notes (GRN)"
                        description="Physical inward stock delivery receipts, quality control inspection (accepted vs rejected), and atomic batch posting."
                    />
                    {can('stores-grn.create') && (
                        <Link href="/stores/grn/create">
                            <Button>
                                <Plus className="mr-2 h-4 w-4" /> Create New GRN
                            </Button>
                        </Link>
                    )}
                </div>

                <Card>
                    <CardContent className="p-4 flex flex-col md:flex-row gap-3">
                        <div className="relative flex-1">
                            <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
                            <Input
                                placeholder="Search GRN #, delivery note, or invoice ref..."
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
                            <option value="">All Receiving Stores</option>
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
                            <option value="inspection_pending">Inspection Pending</option>
                            <option value="partially_accepted">Partially Accepted</option>
                            <option value="accepted">Accepted</option>
                            <option value="posted">Posted to Stock</option>
                            <option value="rejected">Rejected</option>
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
                                        <th className="px-4 py-3">GRN #</th>
                                        <th className="px-4 py-3">PO Number</th>
                                        <th className="px-4 py-3">Supplier</th>
                                        <th className="px-4 py-3">Receiving Store</th>
                                        <th className="px-4 py-3">Received Date</th>
                                        <th className="px-4 py-3">Status</th>
                                        <th className="px-4 py-3 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {grns.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={7} className="py-8 text-center text-muted-foreground">
                                                No Goods Receipt Notes found.
                                            </td>
                                        </tr>
                                    ) : (
                                        grns.data.map((grn) => (
                                            <tr key={grn.id} className="hover:bg-muted/50 transition">
                                                <td className="px-4 py-3 font-semibold text-primary">
                                                    <Link href={`/stores/grn/${grn.id}`} className="hover:underline">
                                                        {grn.grn_number}
                                                    </Link>
                                                </td>
                                                <td className="px-4 py-3 font-medium text-muted-foreground">
                                                    {grn.purchase_order ? (
                                                        <Link href={`/procurement/orders/${grn.purchase_order.id}`} className="hover:underline text-blue-600">
                                                            {grn.purchase_order.po_number}
                                                        </Link>
                                                    ) : (
                                                        'Direct Receipt'
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 font-medium">{grn.vendor?.name || '—'}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{grn.store?.name}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{grn.received_date}</td>
                                                <td className="px-4 py-3">{getStatusBadge(grn.status)}</td>
                                                <td className="px-4 py-3 text-right">
                                                    <Link href={`/stores/grn/${grn.id}`}>
                                                        <Button size="sm" variant="ghost">
                                                            <Eye className="mr-1 h-3.5 w-3.5" /> View / Inspect
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
