import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Eye, FileCheck, Filter, GitCompare, Plus, Search } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Procurement', href: '/procurement' },
    { title: 'Vendor Quotations', href: '/procurement/quotations' },
];

interface Quotation {
    id: number;
    quotation_number: string;
    vendor: { id: number; name: string };
    indent?: { id: number; indent_number: string } | null;
    quotation_date: string;
    valid_until: string | null;
    total_amount: number;
    status: 'pending' | 'under_review' | 'selected' | 'rejected';
    created_at: string;
}

interface Props {
    quotations: {
        data: Quotation[];
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

export default function QuotationsIndex({ quotations, vendors, filters }: Props) {
    const { can } = usePermissions();
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || '');
    const [vendorId, setVendorId] = useState(filters.vendor_id ? String(filters.vendor_id) : '');

    const handleFilter = () => {
        router.get('/procurement/quotations', {
            search: search || undefined,
            status: status || undefined,
            vendor_id: vendorId || undefined,
        }, { preserveState: true });
    };

    const getStatusBadge = (st: string) => {
        switch (st) {
            case 'pending':
                return <Badge variant="secondary" className="capitalize bg-yellow-100 text-yellow-800">Pending</Badge>;
            case 'under_review':
                return <Badge variant="secondary" className="capitalize bg-blue-100 text-blue-800">Under Review</Badge>;
            case 'selected':
                return <Badge variant="secondary" className="capitalize bg-green-100 text-green-800 font-semibold">Selected (Won)</Badge>;
            case 'rejected':
                return <Badge variant="secondary" className="capitalize bg-red-100 text-red-800">Rejected</Badge>;
            default:
                return <Badge variant="outline">{st}</Badge>;
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Vendor Quotations - Procurement" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <PageHeader
                        title="Vendor Quotations"
                        description="Record and evaluate vendor price quotes, compare multi-vendor proposals, and select winning bids."
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        {can('procurement-quotations.compare') && (
                            <Link href="/procurement/quotations/compare">
                                <Button variant="outline">
                                    <GitCompare className="mr-2 h-4 w-4" /> Compare Matrix
                                </Button>
                            </Link>
                        )}
                        {can('procurement-quotations.create') && (
                            <Link href="/procurement/quotations/create">
                                <Button>
                                    <Plus className="mr-2 h-4 w-4" /> Record New Quotation
                                </Button>
                            </Link>
                        )}
                    </div>
                </div>

                {/* Filter Toolbar */}
                <Card>
                    <CardContent className="p-4">
                        <div className="flex flex-col gap-3 md:flex-row md:items-center">
                            <div className="relative flex-1">
                                <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search quote number..."
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
                                <option value="pending">Pending</option>
                                <option value="under_review">Under Review</option>
                                <option value="selected">Selected</option>
                                <option value="rejected">Rejected</option>
                            </select>

                            <Button onClick={handleFilter} variant="secondary">
                                <Filter className="mr-2 h-4 w-4" /> Filter
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Quotations Table */}
                <Card>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3">Quote #</th>
                                        <th className="px-4 py-3">Vendor</th>
                                        <th className="px-4 py-3">Linked Indent</th>
                                        <th className="px-4 py-3">Date</th>
                                        <th className="px-4 py-3">Valid Until</th>
                                        <th className="px-4 py-3">Total (TZS)</th>
                                        <th className="px-4 py-3">Status</th>
                                        <th className="px-4 py-3 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {quotations.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={8} className="py-8 text-center text-muted-foreground">
                                                No vendor quotations found.
                                            </td>
                                        </tr>
                                    ) : (
                                        quotations.data.map((q) => (
                                            <tr key={q.id} className="hover:bg-muted/50 transition">
                                                <td className="px-4 py-3 font-semibold text-primary">
                                                    <Link href={`/procurement/quotations/${q.id}`} className="hover:underline">
                                                        {q.quotation_number}
                                                    </Link>
                                                </td>
                                                <td className="px-4 py-3 font-medium">{q.vendor?.name}</td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {q.indent ? (
                                                        <Link href={`/procurement/indents/${q.indent.id}`} className="hover:underline text-blue-600">
                                                            {q.indent.indent_number}
                                                        </Link>
                                                    ) : (
                                                        'Direct Quote'
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">{q.quotation_date}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{q.valid_until || '—'}</td>
                                                <td className="px-4 py-3 font-semibold">
                                                    TZS {Number(q.total_amount).toLocaleString()}
                                                </td>
                                                <td className="px-4 py-3">{getStatusBadge(q.status)}</td>
                                                <td className="px-4 py-3 text-right">
                                                    <div className="flex items-center justify-end gap-1">
                                                        <Link href={`/procurement/quotations/${q.id}`}>
                                                            <Button size="sm" variant="ghost">
                                                                <Eye className="mr-1 h-3.5 w-3.5" /> View
                                                            </Button>
                                                        </Link>
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
