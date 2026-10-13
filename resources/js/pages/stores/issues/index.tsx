import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Eye, Filter, Plus, Search, Send } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Store & Inventory', href: '/stores' },
    { title: 'Stock Issues', href: '/stores/issues' },
];

interface StoreIssue {
    id: number;
    issue_number: string;
    from_store: { id: number; name: string };
    department: { id: number; name: string };
    issued_by?: { name: string };
    received_by?: { name: string };
    issue_date: string;
    status: 'draft' | 'issued' | 'received' | 'cancelled';
    total_cost: number;
}

interface Props {
    issues: {
        data: StoreIssue[];
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

export default function StoreIssuesIndex({ issues, departments, stores, filters }: Props) {
    const { can } = usePermissions();
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || '');
    const [deptId, setDeptId] = useState(filters.department_id ? String(filters.department_id) : '');
    const [storeId, setStoreId] = useState(filters.store_id ? String(filters.store_id) : '');

    const handleFilter = () => {
        router.get('/stores/issues', {
            search: search || undefined,
            status: status || undefined,
            department_id: deptId || undefined,
            store_id: storeId || undefined,
        }, { preserveState: true });
    };

    const getStatusBadge = (st: string) => {
        switch (st) {
            case 'issued':
                return <Badge variant="secondary" className="bg-blue-100 text-blue-800">Issued (In Transit)</Badge>;
            case 'received':
                return <Badge variant="secondary" className="bg-green-100 text-green-800 font-semibold flex items-center gap-1"><CheckCircle2 className="h-3 w-3" /> Confirmed Received</Badge>;
            case 'cancelled':
                return <Badge variant="secondary" className="bg-red-100 text-red-800">Cancelled</Badge>;
            default:
                return <Badge variant="outline">{st}</Badge>;
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stock Issues - Store" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <PageHeader
                        title="Store Stock Issues (Outward)"
                        description="FEFO batch allocation of stock items to hospital wards, theaters, and clinical units with delivery confirmation."
                    />
                    {can('stores-issues.create') && (
                        <Link href="/stores/issues/create">
                            <Button>
                                <Plus className="mr-2 h-4 w-4" /> Issue Stock to Department
                            </Button>
                        </Link>
                    )}
                </div>

                <Card>
                    <CardContent className="p-4 flex flex-col md:flex-row gap-3">
                        <div className="relative flex-1">
                            <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
                            <Input
                                placeholder="Search issue number..."
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
                            <option value="">All Receiving Depts</option>
                            {departments.map((d) => (
                                <option key={d.id} value={d.id}>{d.name}</option>
                            ))}
                        </select>

                        <select
                            value={storeId}
                            onChange={(e) => setStoreId(e.target.value)}
                            className="rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option value="">All Issuing Stores</option>
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
                            <option value="issued">Issued</option>
                            <option value="received">Confirmed Received</option>
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
                                        <th className="px-4 py-3">Issue #</th>
                                        <th className="px-4 py-3">From Store</th>
                                        <th className="px-4 py-3">To Department</th>
                                        <th className="px-4 py-3">Issue Date</th>
                                        <th className="px-4 py-3">Issued By</th>
                                        <th className="px-4 py-3">Status</th>
                                        <th className="px-4 py-3 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {issues.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={7} className="py-8 text-center text-muted-foreground">
                                                No stock issue records found.
                                            </td>
                                        </tr>
                                    ) : (
                                        issues.data.map((iss) => (
                                            <tr key={iss.id} className="hover:bg-muted/50 transition">
                                                <td className="px-4 py-3 font-semibold text-primary">
                                                    <Link href={`/stores/issues/${iss.id}`} className="hover:underline">
                                                        {iss.issue_number}
                                                    </Link>
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">{iss.from_store?.name}</td>
                                                <td className="px-4 py-3 font-medium">{iss.department?.name}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{iss.issue_date}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{iss.issued_by?.name || 'Staff'}</td>
                                                <td className="px-4 py-3">{getStatusBadge(iss.status)}</td>
                                                <td className="px-4 py-3 text-right">
                                                    <Link href={`/stores/issues/${iss.id}`}>
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
