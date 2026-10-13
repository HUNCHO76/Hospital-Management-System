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
    { title: 'Store & Inventory', href: '/stores' },
    { title: 'Department Requests', href: '/stores/requests' },
];

interface StockRequest {
    id: number;
    request_number: string;
    department: { id: number; name: string };
    from_store: { id: number; name: string };
    requested_by?: { name: string };
    request_date: string;
    priority: string;
    status: 'draft' | 'submitted' | 'approved' | 'partially_issued' | 'issued' | 'rejected' | 'cancelled';
}

interface Props {
    requests: {
        data: StockRequest[];
        links: any[];
        total: number;
    };
    departments: { id: number; name: string }[];
    stores: { id: number; name: string }[];
    filters: {
        status: string;
        department_id: number | null;
        store_id: number | null;
        search: string;
    };
}

export default function StockRequestsIndex({ requests, departments, stores, filters }: Props) {
    const { can } = usePermissions();
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || '');
    const [deptId, setDeptId] = useState(filters.department_id ? String(filters.department_id) : '');
    const [storeId, setStoreId] = useState(filters.store_id ? String(filters.store_id) : '');

    const handleFilter = () => {
        router.get('/stores/requests', {
            search: search || undefined,
            status: status || undefined,
            department_id: deptId || undefined,
            store_id: storeId || undefined,
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
            case 'partially_issued':
                return <Badge variant="secondary" className="bg-purple-100 text-purple-800">Partially Issued</Badge>;
            case 'issued':
                return <Badge variant="secondary" className="bg-green-100 text-green-800 font-semibold">Fully Issued</Badge>;
            case 'rejected':
                return <Badge variant="secondary" className="bg-red-100 text-red-800">Rejected</Badge>;
            default:
                return <Badge variant="outline">{st}</Badge>;
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Department Stock Requests - Store" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <PageHeader
                        title="Department Stock Requests"
                        description="Internal requisition of medicines and supplies from clinical units (ICU, Wards, OPD) to main stores."
                    />
                    {can('stores-requests.create') && (
                        <Link href="/stores/requests/create">
                            <Button>
                                <Plus className="mr-2 h-4 w-4" /> New Department Request
                            </Button>
                        </Link>
                    )}
                </div>

                <Card>
                    <CardContent className="p-4 flex flex-col md:flex-row gap-3">
                        <div className="relative flex-1">
                            <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
                            <Input
                                placeholder="Search request # or notes..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && handleFilter()}
                                className="pl-9"
                            />
                        </div>

                        <select
                            value={deptId}
                            onChange={(e) => setDeptId(e.target.value)}
                            className="rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option value="">All Departments</option>
                            {departments.map((d) => (
                                <option key={d.id} value={d.id}>{d.name}</option>
                            ))}
                        </select>

                        <select
                            value={storeId}
                            onChange={(e) => setStoreId(e.target.value)}
                            className="rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option value="">All Stores</option>
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
                            <option value="submitted">Submitted</option>
                            <option value="approved">Approved</option>
                            <option value="partially_issued">Partially Issued</option>
                            <option value="issued">Issued</option>
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
                                        <th className="px-4 py-3">Request #</th>
                                        <th className="px-4 py-3">Department</th>
                                        <th className="px-4 py-3">Source Store</th>
                                        <th className="px-4 py-3">Date</th>
                                        <th className="px-4 py-3">Priority</th>
                                        <th className="px-4 py-3">Status</th>
                                        <th className="px-4 py-3 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {requests.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={7} className="py-8 text-center text-muted-foreground">
                                                No department stock requests found.
                                            </td>
                                        </tr>
                                    ) : (
                                        requests.data.map((req) => (
                                            <tr key={req.id} className="hover:bg-muted/50 transition">
                                                <td className="px-4 py-3 font-semibold text-primary">
                                                    <Link href={`/stores/requests/${req.id}`} className="hover:underline">
                                                        {req.request_number}
                                                    </Link>
                                                </td>
                                                <td className="px-4 py-3 font-medium">{req.department?.name}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{req.from_store?.name}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{req.request_date}</td>
                                                <td className="px-4 py-3 capitalize">
                                                    <Badge variant={req.priority === 'urgent' ? 'destructive' : 'outline'}>
                                                        {req.priority}
                                                    </Badge>
                                                </td>
                                                <td className="px-4 py-3">{getStatusBadge(req.status)}</td>
                                                <td className="px-4 py-3 text-right">
                                                    <Link href={`/stores/requests/${req.id}`}>
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
