import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, Eye, Printer, ShieldAlert } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Store & Inventory', href: '/stores' },
    { title: 'Reports', href: '/stores/reports' },
    { title: '3-Way Discrepancies', href: '/stores/reports/discrepancies' },
];

interface DiscrepantInvoice {
    id: number;
    system_invoice_number: string;
    invoice_number: string;
    invoice_date: string;
    grand_total?: number;
    total_amount?: number;
    discrepancy_notes: string | null;
    status: string;
    vendor?: { name: string };
    purchase_order?: { id: number; po_number: string };
    goods_receipt_note?: { id: number; grn_number: string };
}

interface Props {
    report: {
        invoices: DiscrepantInvoice[];
        count: number;
        total_amount: number;
    };
}

export default function DiscrepanciesReport({ report }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="3-Way Matching Discrepancies - Audit Report" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <PageHeader
                        title="3-Way Matching Discrepancy Register"
                        description="Audit exceptions where supplier invoice prices or quantities diverge from agreed Purchase Orders and physical Goods Receipts."
                    />
                    <div className="flex items-center gap-2">
                        <Link href="/stores/reports">
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="mr-2 h-4 w-4" /> Back to Reports
                            </Button>
                        </Link>
                        <Button size="sm" variant="outline" onClick={() => window.print()}>
                            <Printer className="mr-2 h-4 w-4" /> Print
                        </Button>
                    </div>
                </div>

                {/* Summary */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Card className="border-red-200 bg-red-50/40">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs uppercase text-red-700">Flagged Discrepant Invoices</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold text-red-600">
                                {report.count} invoices
                            </div>
                            <p className="text-xs text-red-700 mt-1">Blocked from payment pending finance resolution</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs uppercase text-muted-foreground">Total Disputed Value</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold text-foreground">
                                TZS {Number(report.total_amount).toLocaleString()}
                            </div>
                            <p className="text-xs text-muted-foreground mt-1">Total invoice amounts under variance audit</p>
                        </CardContent>
                    </Card>
                </div>

                {/* Invoices Table */}
                <Card>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3">Invoice Ref</th>
                                        <th className="px-4 py-3">Vendor</th>
                                        <th className="px-4 py-3">PO Number</th>
                                        <th className="px-4 py-3">GRN Number</th>
                                        <th className="px-4 py-3">Invoice Date</th>
                                        <th className="px-4 py-3">Discrepancy Variance Notes</th>
                                        <th className="px-4 py-3">Amount (TZS)</th>
                                        <th className="px-4 py-3 text-right">Audit</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {report.invoices.length === 0 ? (
                                        <tr>
                                            <td colSpan={8} className="py-8 text-center text-muted-foreground">
                                                Zero audit discrepancies. All vendor invoices have matched agreed POs and GRNs.
                                            </td>
                                        </tr>
                                    ) : (
                                        report.invoices.map((inv) => (
                                            <tr key={inv.id} className="hover:bg-muted/40">
                                                <td className="px-4 py-3 font-semibold text-primary">
                                                    <Link href={`/procurement/invoices/${inv.id}`} className="hover:underline">
                                                        {inv.invoice_number}
                                                    </Link>
                                                    <div className="text-xs text-muted-foreground">{inv.system_invoice_number}</div>
                                                </td>
                                                <td className="px-4 py-3 font-medium">{inv.vendor?.name}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{inv.purchase_order?.po_number || '—'}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{inv.goods_receipt_note?.grn_number || '—'}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{inv.invoice_date}</td>
                                                <td className="px-4 py-3 text-xs text-red-600 font-mono">
                                                    {inv.discrepancy_notes || 'Price / quantity variance detected'}
                                                </td>
                                                <td className="px-4 py-3 font-bold">
                                                    TZS {Number(inv.grand_total || inv.total_amount).toLocaleString()}
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <Link href={`/procurement/invoices/${inv.id}`}>
                                                        <Button size="sm" variant="ghost">
                                                            <Eye className="mr-1 h-3.5 w-3.5" /> Resolve
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
