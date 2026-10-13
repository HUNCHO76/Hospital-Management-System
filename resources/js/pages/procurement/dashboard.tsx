import { PageHeader } from '@/components/page-header';
import { StatCard } from '@/components/dashboard/stat-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    FileCheck2,
    FilePlus2,
    FileSpreadsheet,
    FileText,
    Plus,
    Receipt,
    Scale,
    ShoppingCart,
    Truck,
} from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Procurement', href: '/procurement' },
];

interface MetricProps {
    pending_indents: number;
    active_pos: number;
    pending_grns: number;
    invoice_discrepancies: number;
    monthly_spend: number;
}

interface RecentIndent {
    id: number;
    indent_number: string;
    department?: { name: string };
    requested_by?: { UserName: string };
    priority: string;
    status: string;
    indent_date: string;
}

interface RecentOrder {
    id: number;
    po_number: string;
    vendor?: { name: string };
    receiving_store?: { name: string };
    grand_total: number;
    status: string;
    order_date: string;
}

interface RecentQuotation {
    id: number;
    quotation_number: string;
    vendor?: { name: string };
    total_amount: number;
    status: string;
    quotation_date: string;
}

interface Props {
    metrics: MetricProps;
    recent_indents: RecentIndent[];
    recent_orders: RecentOrder[];
    recent_quotations: RecentQuotation[];
}

function getStatusBadge(status: string) {
    switch (status.toUpperCase()) {
        case 'APPROVED':
        case 'SELECTED':
        case 'POSTED':
        case 'MATCHED':
            return <Badge variant="default" className="bg-emerald-600 text-white">{status}</Badge>;
        case 'SUBMITTED':
        case 'INSPECTION_PENDING':
        case 'SENT_TO_VENDOR':
            return <Badge variant="secondary" className="bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300">{status}</Badge>;
        case 'PARTIALLY_RECEIVED':
        case 'PARTIALLY_ACCEPTED':
            return <Badge variant="secondary" className="bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300">{status}</Badge>;
        case 'DISCREPANCY':
        case 'REJECTED':
        case 'CANCELLED':
            return <Badge variant="destructive">{status}</Badge>;
        default:
            return <Badge variant="outline">{status}</Badge>;
    }
}

export default function ProcurementDashboard({ metrics, recent_indents, recent_orders, recent_quotations }: Props) {
    const { can } = usePermissions();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Procurement Management" />

            <div className="flex flex-col gap-6 p-4">
                <PageHeader
                    title="Hospital Purchase & Procurement"
                    description="Requisitions, vendor quotation matrices, purchase orders, and 3-way matching financial controls."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {can('procurement-indents.create') && (
                                <Button asChild size="sm">
                                    <Link href="/procurement/indents/create">
                                        <Plus className="mr-1.5 h-4 w-4" /> New Requisition Indent
                                    </Link>
                                </Button>
                            )}
                            {can('procurement-quotations.create') && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href="/procurement/quotations/create">
                                        <FileSpreadsheet className="mr-1.5 h-4 w-4" /> Record Quote
                                    </Link>
                                </Button>
                            )}
                            {can('procurement-purchase-orders.create') && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href="/procurement/orders/create">
                                        <ShoppingCart className="mr-1.5 h-4 w-4" /> Issue PO
                                    </Link>
                                </Button>
                            )}
                            {can('procurement-invoices.create') && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href="/procurement/invoices/create">
                                        <Scale className="mr-1.5 h-4 w-4" /> 3-Way Match
                                    </Link>
                                </Button>
                            )}
                        </div>
                    }
                />

                {/* Key Metrics */}
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <StatCard
                        label="Pending Indents"
                        value={metrics.pending_indents.toString()}
                        description="Awaiting review & approval"
                        icon={Clock}
                    />
                    <StatCard
                        label="Active Purchase Orders"
                        value={metrics.active_pos.toString()}
                        description="Approved or in delivery"
                        icon={ShoppingCart}
                    />
                    <StatCard
                        label="GRN Inspections Pending"
                        value={metrics.pending_grns.toString()}
                        description="Awaiting store verification"
                        icon={Truck}
                    />
                    <StatCard
                        label="3-Way Match Discrepancies"
                        value={metrics.invoice_discrepancies.toString()}
                        description="Price or quantity variance"
                        icon={AlertTriangle}
                    />
                    <StatCard
                        label="Current Month Spend"
                        value={`TZS ${metrics.monthly_spend.toLocaleString('en-US', { minimumFractionDigits: 2 })}`}
                        description="Approved purchases this month"
                        icon={Receipt}
                    />
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Recent Indents */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-base font-semibold">Recent Department Indents</CardTitle>
                            <Button asChild variant="ghost" size="sm">
                                <Link href="/procurement/indents">View all</Link>
                            </Button>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Indent No</TableHead>
                                        <TableHead>Department</TableHead>
                                        <TableHead>Priority</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {recent_indents.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={4} className="text-center text-sm text-muted-foreground py-6">
                                                No requisitions created yet.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        recent_indents.map((indent) => (
                                            <TableRow key={indent.id}>
                                                <TableCell className="font-medium">
                                                    <Link href={`/procurement/indents/${indent.id}`} className="text-primary hover:underline">
                                                        {indent.indent_number}
                                                    </Link>
                                                </TableCell>
                                                <TableCell>{indent.department?.name || '—'}</TableCell>
                                                <TableCell className="capitalize text-xs">{indent.priority}</TableCell>
                                                <TableCell>{getStatusBadge(indent.status)}</TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    {/* Recent Purchase Orders */}
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-base font-semibold">Active & Recent Purchase Orders</CardTitle>
                            <Button asChild variant="ghost" size="sm">
                                <Link href="/procurement/orders">View all</Link>
                            </Button>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>PO No</TableHead>
                                        <TableHead>Vendor</TableHead>
                                        <TableHead className="text-right">Total (TZS)</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {recent_orders.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={4} className="text-center text-sm text-muted-foreground py-6">
                                                No purchase orders generated yet.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        recent_orders.map((po) => (
                                            <TableRow key={po.id}>
                                                <TableCell className="font-medium">
                                                    <Link href={`/procurement/orders/${po.id}`} className="text-primary hover:underline">
                                                        {po.po_number}
                                                    </Link>
                                                </TableCell>
                                                <TableCell>{po.vendor?.name || '—'}</TableCell>
                                                <TableCell className="text-right font-mono text-sm">
                                                    {Number(po.grand_total).toLocaleString('en-US', { minimumFractionDigits: 2 })}
                                                </TableCell>
                                                <TableCell>{getStatusBadge(po.status)}</TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </div>

                {/* Quotation Matrix Snippet */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between pb-2">
                        <div>
                            <CardTitle className="text-base font-semibold">Vendor Quotations & Price Comparison</CardTitle>
                            <p className="text-xs text-muted-foreground mt-0.5">Commercial quotations registered for requisitions</p>
                        </div>
                        <div className="flex gap-2">
                            {can('procurement-quotations.compare') && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href="/procurement/quotations/compare">
                                        <Scale className="mr-1.5 h-4 w-4" /> Side-by-Side Comparison Matrix
                                    </Link>
                                </Button>
                            )}
                            <Button asChild variant="ghost" size="sm">
                                <Link href="/procurement/quotations">All Quotes</Link>
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Quote No</TableHead>
                                    <TableHead>Vendor</TableHead>
                                    <TableHead>Quotation Date</TableHead>
                                    <TableHead className="text-right">Total Amount (TZS)</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {recent_quotations.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={5} className="text-center text-sm text-muted-foreground py-6">
                                            No vendor quotes submitted yet.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    recent_quotations.map((q) => (
                                        <TableRow key={q.id}>
                                            <TableCell className="font-medium">
                                                <Link href={`/procurement/quotations/${q.id}`} className="text-primary hover:underline">
                                                    {q.quotation_number}
                                                </Link>
                                            </TableCell>
                                            <TableCell>{q.vendor?.name || '—'}</TableCell>
                                            <TableCell>{q.quotation_date}</TableCell>
                                            <TableCell className="text-right font-mono text-sm">
                                                {Number(q.total_amount).toLocaleString('en-US', { minimumFractionDigits: 2 })}
                                            </TableCell>
                                            <TableCell>{getStatusBadge(q.status)}</TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
