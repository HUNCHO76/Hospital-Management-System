import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Eye, Filter, Plus, Search } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Procurement', href: '/procurement' },
    { title: 'Purchase Orders', href: '/procurement/orders' },
];

interface PurchaseOrder {
    id: number;
    po_number: string;
    vendor: { id: number; name: string };
    receiving_store: { id: number; name: string };
    created_by?: { name: string };
    po_date: string;
    expected_delivery_date: string | null;
    total_amount: number;
    status: 'draft' | 'submitted' | 'approved' | 'sent' | 'partially_received' | 'received' | 'cancelled';
}

interface Props {
    orders: {
        data: PurchaseOrder[];
        links: any[];
        total: number;
    };
    vendors: { id: number; name: string }[];
    filters: {
        status: string;
        vendor_id: number | null;
        search: string;
    };
}

export default function PurchaseOrdersIndex({ orders, vendors, filters }: Props) {
    const { can } = usePermissions();
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || '');
    const [vendorId, setVendorId] = useState(filters.vendor_id ? String(filters.vendor_id) : '');

    const handleFilter = () => {
        router.get('/procurement/orders', {
            search: search || undefined,
            status: status || undefined,
            vendor_id: vendorId || undefined,
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
            case 'sent':
                return <Badge variant="secondary" className="bg-indigo-100 text-indigo-800">Dispatched to Vendor</Badge>;
            case 'partially_received':
                return <Badge variant="secondary" className="bg-purple-100 text-purple-800">Partially Received</Badge>;
            case 'received':
                return <Badge variant="secondary" className="bg-green-100 text-green-800 font-semibold">Fully Received</Badge>;
            case 'cancelled':
                return <Badge variant="secondary" className="bg-red-100 text-red-800">Cancelled</Badge>;
            default:
                return <Badge variant="outline">{st}</Badge>;
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Purchase Orders - Procurement" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <PageHeader
                        title="Purchase Orders (PO)"
                        description="Manage hospital purchase commitments, approval threshold workflows, and vendor dispatches."
                    />
                    {can('procurement-purchase-orders.create') && (
                        <Link href="/procurement/orders/create">
                            <Button>
                                <Plus className="mr-2 h-4 w-4" /> Create Purchase Order
                            </Button>
                        </Link>
                    )}
                </div>

                {/* Filter Toolbar */}
                <Card>
                    <CardContent className="p-4">
                        <div className="flex flex-col gap-3 md:flex-row md:items-center">
                            <div className="relative flex-1">
                                <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search PO number or notes..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && handleFilter()}
                                    className="pl-9"
                                />
                            </div>

                            <select
                                value={vendorId}
                                onChange={(e) => setVendorId(e.target.value)}
                                className="rounded-md border border-input bg-background px-3 py-2 text-sm"
                            >
                                <option value="">All Vendors</option>
                                {vendors.map((v) => (
                                    <option key={v.id} value={v.id}>{v.name}</option>
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
                                <option value="approved">Approved</option>
                                <option value="sent">Sent to Vendor</option>
                                <option value="partially_received">Partially Received</option>
                                <option value="received">Received</option>
                                <option value="cancelled">Cancelled</option>
                            </select>

                            <Button onClick={handleFilter} variant="secondary">
                                <Filter className="mr-2 h-4 w-4" /> Filter
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3">PO Number</th>
                                        <th className="px-4 py-3">Vendor</th>
                                        <th className="px-4 py-3">Receiving Store</th>
                                        <th className="px-4 py-3">Date</th>
                                        <th className="px-4 py-3">Expected Delivery</th>
                                        <th className="px-4 py-3">Total Amount</th>
                                        <th className="px-4 py-3">Status</th>
                                        <th className="px-4 py-3 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {orders.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={8} className="py-8 text-center text-muted-foreground">
                                                No purchase orders found.
                                            </td>
                                        </tr>
                                    ) : (
                                        orders.data.map((po) => (
                                            <tr key={po.id} className="hover:bg-muted/50 transition">
                                                <td className="px-4 py-3 font-semibold text-primary">
                                                    <Link href={`/procurement/orders/${po.id}`} className="hover:underline">
                                                        {po.po_number}
                                                    </Link>
                                                </td>
                                                <td className="px-4 py-3 font-medium">{po.vendor?.name}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{po.receiving_store?.name}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{po.po_date}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{po.expected_delivery_date || '—'}</td>
                                                <td className="px-4 py-3 font-semibold">
                                                    TZS {Number(po.total_amount).toLocaleString()}
                                                </td>
                                                <td className="px-4 py-3">{getStatusBadge(po.status)}</td>
                                                <td className="px-4 py-3 text-right">
                                                    <Link href={`/procurement/orders/${po.id}`}>
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
