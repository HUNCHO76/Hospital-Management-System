import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Edit3, Eye, Filter, Plus, Search } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Procurement', href: '/procurement' },
    { title: 'Vendors', href: '/procurement/vendors' },
];

interface Vendor {
    id: number;
    code: string;
    name: string;
    contact_person: string | null;
    phone: string | null;
    email: string | null;
    rating: number | null;
    status: 'active' | 'inactive' | 'blacklisted';
}

interface Props {
    vendors: {
        data: Vendor[];
        links: any[];
        total: number;
    };
    filters: {
        search: string;
        status: string;
    };
}

export default function VendorsIndex({ vendors, filters }: Props) {
    const { can } = usePermissions();
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || '');

    const handleFilter = () => {
        router.get('/procurement/vendors', {
            search: search || undefined,
            status: status || undefined,
        }, { preserveState: true });
    };

    const getStatusBadge = (st: string) => {
        switch (st) {
            case 'active':
                return <Badge variant="secondary" className="bg-green-100 text-green-800">Active</Badge>;
            case 'inactive':
                return <Badge variant="outline">Inactive</Badge>;
            case 'blacklisted':
                return <Badge variant="destructive">Blacklisted</Badge>;
            default:
                return <Badge variant="outline">{st}</Badge>;
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Vendor Registry - Procurement" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <PageHeader
                        title="Certified Vendor Registry"
                        description="Manage hospital supplier master data, bank details, tax identification, and performance records."
                    />
                    {can('procurement-vendors.create') && (
                        <Link href="/procurement/vendors/create">
                            <Button>
                                <Plus className="mr-2 h-4 w-4" /> Register New Vendor
                            </Button>
                        </Link>
                    )}
                </div>

                <Card>
                    <CardContent className="p-4 flex flex-col md:flex-row gap-3">
                        <div className="relative flex-1">
                            <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
                            <Input
                                placeholder="Search by vendor name, code, contact, phone..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && handleFilter()}
                                className="pl-9"
                            />
                        </div>
                        <select
                            value={status}
                            onChange={(e) => setStatus(e.target.value)}
                            className="rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option value="">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="blacklisted">Blacklisted</option>
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
                                        <th className="px-4 py-3">Vendor Code</th>
                                        <th className="px-4 py-3">Vendor Name</th>
                                        <th className="px-4 py-3">Contact Person</th>
                                        <th className="px-4 py-3">Phone</th>
                                        <th className="px-4 py-3">Email</th>
                                        <th className="px-4 py-3">Rating</th>
                                        <th className="px-4 py-3">Status</th>
                                        <th className="px-4 py-3 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {vendors.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={8} className="py-8 text-center text-muted-foreground">
                                                No vendors found.
                                            </td>
                                        </tr>
                                    ) : (
                                        vendors.data.map((v) => (
                                            <tr key={v.id} className="hover:bg-muted/50 transition">
                                                <td className="px-4 py-3 font-semibold text-primary">
                                                    <Link href={`/procurement/vendors/${v.id}`} className="hover:underline">
                                                        {v.code}
                                                    </Link>
                                                </td>
                                                <td className="px-4 py-3 font-medium">{v.name}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{v.contact_person || '—'}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{v.phone || '—'}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{v.email || '—'}</td>
                                                <td className="px-4 py-3">
                                                    {v.rating ? `${v.rating} / 5.0` : '—'}
                                                </td>
                                                <td className="px-4 py-3">{getStatusBadge(v.status)}</td>
                                                <td className="px-4 py-3 text-right">
                                                    <div className="flex items-center justify-end gap-1">
                                                        <Link href={`/procurement/vendors/${v.id}`}>
                                                            <Button size="sm" variant="ghost">
                                                                <Eye className="mr-1 h-3.5 w-3.5" /> View
                                                            </Button>
                                                        </Link>
                                                        {can('procurement-vendors.update') && (
                                                            <Link href={`/procurement/vendors/${v.id}/edit`}>
                                                                <Button size="sm" variant="ghost">
                                                                    <Edit3 className="mr-1 h-3.5 w-3.5" /> Edit
                                                                </Button>
                                                            </Link>
                                                        )}
                                                    </div>
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
