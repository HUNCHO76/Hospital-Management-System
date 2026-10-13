import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, Eye, Filter, Plus, Search, ShieldAlert } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Procurement', href: '/procurement' },
    { title: 'Vendor Invoices', href: '/procurement/invoices' },
];

interface VendorInvoice {
    id: number;
    system_invoice_number: string;
    invoice_number: string;
    vendor: { id: number; name: string };
    purchase_order?: { id: number; po_number: string } | null;
    invoice_date: string;
    due_date: string | null;
    total_amount: number;
    match_status: 'matched' | 'discrepancy' | 'manual_review' | 'pending';
    status: 'draft' | 'under_review' | 'approved' | 'paid' | 'rejected';
}

interface Props {
    invoices: {
        data: VendorInvoice[];
        links: any[];
        total: number;
    };
    vendors: { id: number; name: string }[];
    filters: {
        match_status: string;
        status: string;
        vendor_id: number | null;
        search: string;
    };
}

export default function VendorInvoicesIndex({ invoices, vendors, filters }: Props) {
    const { can } = usePermissions();
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || '');
    const [matchStatus, setMatchStatus] = useState(filters.match_status || '');
    const [vendorId, setVendorId] = useState(filters.vendor_id ? String(filters.vendor_id) : '');

    const handleFilter = () => {
        router.get('/procurement/invoices', {
            search: search || undefined,
            status: status || undefined,
            match_status: matchStatus || undefined,
            vendor_id: vendorId || undefined,
        }, { preserveState: true });
    };

    const getMatchBadge = (ms: string) => {
        switch (ms) {
            case 'matched':
                return (
                    <Badge variant="secondary" className="bg-green-100 text-green-800 flex items-center gap-1 font-semibold">
                        <CheckCircle2 className="h-3 w-3 text-green-600" /> Matched 3-Way
                    </Badge>
                );
            case 'discrepancy':
                return (
                    <Badge variant="secondary" className="bg-red-100 text-red-800 flex items-center gap-1 font-semibold">
                        <ShieldAlert className="h-3 w-3 text-red-600" /> Discrepancy Found
                    </Badge>
                );
            case 'manual_review':
                return (
                    <Badge variant="secondary" className="bg-yellow-100 text-yellow-800 flex items-center gap-1">
                        <AlertCircle className="h-3 w-3 text-yellow-600" /> Manual Review
                    </Badge>
                );
            default:
                return <Badge variant="outline">Pending Match</Badge>;
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Vendor Invoices & 3-Way Matching - Procurement" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <PageHeader
                        title="Vendor Invoices (3-Way Matching)"
                        description="Audit invoice pricing and quantities against Purchase Orders and Goods Receipt Notes before approving payment."
                    />
                    {can('procurement-invoices.create') && (
                        <Link href="/procurement/invoices/create">
                            <Button>
                                <Plus className="mr-2 h-4 w-4" /> Enter Vendor Invoice
                            </Button>
                        </Link>
                    )}
                </div>

                {/* Filters */}
                <Card>
                    <CardContent className="p-4">
                        <div className="flex flex-col gap-3 md:flex-row md:items-center">
                            <div className="relative flex-1">
                                <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search invoice number or reference..."
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
                                value={matchStatus}
                                onChange={(e) => setMatchStatus(e.target.value)}
                                className="rounded-md border border-input bg-background px-3 py-2 text-sm"
                            >
                                <option value="">All Match Statuses</option>
                                <option value="matched">Matched 3-Way</option>
                                <option value="discrepancy">Discrepancy</option>
                                <option value="manual_review">Manual Review</option>
                            </select>

                            <select
                                value={status}
                                onChange={(e) => setStatus(e.target.value)}
                                className="rounded-md border border-input bg-background px-3 py-2 text-sm"
                            >
                                <option value="">All Workflow Statuses</option>
                                <option value="draft">Draft</option>
                                <option value="under_review">Under Review</option>
                                <option value="approved">Approved for Payment</option>
                                <option value="paid">Paid</option>
                                <option value="rejected">Rejected</option>
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
                                        <th className="px-4 py-3">Internal Ref</th>
                                        <th className="px-4 py-3">Vendor Invoice #</th>
                                        <th className="px-4 py-3">Vendor</th>
                                        <th className="px-4 py-3">PO Number</th>
                                        <th className="px-4 py-3">Invoice Date</th>
                                        <th className="px-4 py-3">Amount (TZS)</th>
                                        <th className="px-4 py-3">3-Way Match</th>
                                        <th className="px-4 py-3">Approval</th>
                                        <th className="px-4 py-3 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {invoices.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={9} className="py-8 text-center text-muted-foreground">
                                                No vendor invoices found.
                                            </td>
                                        </tr>
                                    ) : (
                                        invoices.data.map((inv) => (
                                            <tr key={inv.id} className="hover:bg-muted/50 transition">
                                                <td className="px-4 py-3 font-semibold text-primary">
                                                    <Link href={`/procurement/invoices/${inv.id}`} className="hover:underline">
                                                        {inv.system_invoice_number}
                                                    </Link>
                                                </td>
                                                <td className="px-4 py-3 font-mono font-medium">{inv.invoice_number}</td>
                                                <td className="px-4 py-3 font-medium">{inv.vendor?.name}</td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {inv.purchase_order ? (
                                                        <Link href={`/procurement/orders/${inv.purchase_order.id}`} className="text-blue-600 hover:underline">
                                                            {inv.purchase_order.po_number}
                                                        </Link>
                                                    ) : (
                                                        '—'
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">{inv.invoice_date}</td>
                                                <td className="px-4 py-3 font-semibold">
                                                    TZS {Number(inv.total_amount).toLocaleString()}
                                                </td>
                                                <td className="px-4 py-3">{getMatchBadge(inv.match_status)}</td>
                                                <td className="px-4 py-3">
                                                    <Badge variant={inv.status === 'approved' ? 'default' : 'outline'} className="capitalize">
                                                        {inv.status}
                                                    </Badge>
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <Link href={`/procurement/invoices/${inv.id}`}>
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
