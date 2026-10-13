import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    Clock,
    CreditCard,
    DollarSign,
    FileText,
    ShieldAlert,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';

interface InvoiceItem {
    id: number;
    procurement_item_id: number;
    item_description: string;
    quantity_billed: number;
    unit_of_measure: string;
    unit_price: number;
    tax_percent: number;
    total_price: number;
    price_variance: number;
    quantity_variance: number;
    has_discrepancy: boolean;
    discrepancy_details?: string;
    procurement_item?: { code: string; name: string };
}

interface VendorInvoice {
    id: number;
    system_invoice_number: string;
    invoice_number: string;
    vendor: { id: number; name: string; contact_person: string; phone: string };
    purchase_order?: { id: number; po_number: string; total_amount: number; items: any[] } | null;
    goods_receipt_note?: { id: number; grn_number: string; items: any[] } | null;
    invoice_date: string;
    due_date: string | null;
    subtotal: number;
    tax_amount: number;
    freight_amount: number;
    total_amount: number;
    match_status: 'matched' | 'discrepancy' | 'manual_review' | 'pending';
    status: 'draft' | 'under_review' | 'approved' | 'paid' | 'rejected';
    discrepancy_notes: string | null;
    verified_by?: { name: string };
    verified_at: string | null;
    approved_by?: { name: string };
    approved_at: string | null;
    resolution_notes: string | null;
    notes: string | null;
    items: InvoiceItem[];
    approval_histories?: any[];
}

interface Props {
    invoice: VendorInvoice;
}

export default function ShowVendorInvoice({ invoice }: Props) {
    const { can } = usePermissions();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Procurement', href: '/procurement' },
        { title: 'Vendor Invoices', href: '/procurement/invoices' },
        { title: invoice.system_invoice_number, href: `/procurement/invoices/${invoice.id}` },
    ];

    const [isApproveModalOpen, setIsApproveModalOpen] = useState(false);

    const { data: approveData, setData: setApproveData, post: postApprove, processing: approveProcessing } = useForm({
        resolution_notes: '',
    });

    const handleApproveSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        postApprove(`/procurement/invoices/${invoice.id}/approve`, {
            onSuccess: () => setIsApproveModalOpen(false),
        });
    };

    const hasAnyDiscrepancy = invoice.match_status === 'discrepancy' || invoice.items.some((i) => i.has_discrepancy);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Invoice ${invoice.invoice_number}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div className="flex items-center gap-3">
                            <PageHeader
                                title={`Invoice: ${invoice.invoice_number}`}
                                description={`System Ref: ${invoice.system_invoice_number} • Vendor: ${invoice.vendor?.name}`}
                            />
                            {invoice.match_status === 'matched' ? (
                                <Badge className="bg-green-600 text-white flex items-center gap-1">
                                    <ShieldCheck className="h-3.5 w-3.5" /> 3-Way Matched
                                </Badge>
                            ) : (
                                <Badge variant="destructive" className="flex items-center gap-1">
                                    <ShieldAlert className="h-3.5 w-3.5" /> Discrepancy Found
                                </Badge>
                            )}
                            <Badge variant="outline" className="capitalize">
                                Status: {invoice.status}
                            </Badge>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Link href="/procurement/invoices">
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="mr-2 h-4 w-4" /> Back to Invoices
                            </Button>
                        </Link>
                        {invoice.status !== 'approved' && invoice.status !== 'paid' && can('procurement-invoices.approve') && (
                            <Button
                                size="sm"
                                onClick={() => setIsApproveModalOpen(true)}
                                className={hasAnyDiscrepancy ? 'bg-amber-600 hover:bg-amber-700' : 'bg-green-600 hover:bg-green-700'}
                            >
                                <CheckCircle2 className="mr-1 h-4 w-4" /> Approve for Payment
                            </Button>
                        )}
                    </div>
                </div>

                {/* Discrepancy Alert Banner */}
                {hasAnyDiscrepancy && (
                    <Card className="border-red-300 bg-red-50/70">
                        <CardContent className="p-4 flex items-start gap-3">
                            <AlertTriangle className="h-5 w-5 text-red-600 mt-0.5 shrink-0" />
                            <div className="space-y-1">
                                <h4 className="font-semibold text-red-900 text-sm">Automated 3-Way Audit Warning</h4>
                                <p className="text-xs text-red-800">
                                    Variances were detected between the Purchase Order agreed unit prices, Goods Receipt Note physically accepted quantities, and this invoice.
                                </p>
                                {invoice.discrepancy_notes && (
                                    <p className="text-xs font-mono text-red-700 bg-white/70 p-2 rounded border border-red-200 mt-2">
                                        {invoice.discrepancy_notes}
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* 3-Way Pillars Cards */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    {/* Pillar 1: PO */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground uppercase flex items-center justify-between">
                                <span>Pillar 1: Purchase Order</span>
                                <FileText className="h-4 w-4 text-blue-600" />
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm space-y-1">
                            {invoice.purchase_order ? (
                                <>
                                    <p className="font-semibold">
                                        <Link href={`/procurement/orders/${invoice.purchase_order.id}`} className="text-blue-600 hover:underline">
                                            {invoice.purchase_order.po_number}
                                        </Link>
                                    </p>
                                    <p className="text-muted-foreground">
                                        Total Commitment: <span className="font-semibold text-foreground">TZS {Number(invoice.purchase_order.total_amount).toLocaleString()}</span>
                                    </p>
                                </>
                            ) : (
                                <p className="text-muted-foreground">No PO reference</p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Pillar 2: GRN */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground uppercase flex items-center justify-between">
                                <span>Pillar 2: Goods Receipt</span>
                                <ShieldCheck className="h-4 w-4 text-emerald-600" />
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm space-y-1">
                            {invoice.goods_receipt_note ? (
                                <>
                                    <p className="font-semibold">
                                        <Link href={`/stores/grn/${invoice.goods_receipt_note.id}`} className="text-emerald-600 hover:underline">
                                            {invoice.goods_receipt_note.grn_number}
                                        </Link>
                                    </p>
                                    <p className="text-xs text-muted-foreground">Stores Physical Receipt Inspected & Posted</p>
                                </>
                            ) : (
                                <p className="text-muted-foreground">Auto-associated via PO</p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Pillar 3: Invoice */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground uppercase flex items-center justify-between">
                                <span>Pillar 3: Vendor Invoice</span>
                                <CreditCard className="h-4 w-4 text-primary" />
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm space-y-1">
                            <p className="font-semibold">Invoice #: {invoice.invoice_number}</p>
                            <p className="text-muted-foreground">Invoice Date: {invoice.invoice_date}</p>
                            <p className="text-muted-foreground">Due Date: {invoice.due_date || 'Standard'}</p>
                            <p className="font-bold text-primary text-base">
                                TZS {Number(invoice.total_amount).toLocaleString()}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Audit Comparison Line Items */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base font-semibold">3-Way Line Item Audit Comparison</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3">Item Description</th>
                                        <th className="px-4 py-3">Qty Billed</th>
                                        <th className="px-4 py-3">Qty Variance</th>
                                        <th className="px-4 py-3">Billed Unit Price (TZS)</th>
                                        <th className="px-4 py-3">Price Variance</th>
                                        <th className="px-4 py-3">Total (TZS)</th>
                                        <th className="px-4 py-3 text-right">Audit Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {invoice.items.map((it) => (
                                        <tr key={it.id} className={it.has_discrepancy ? 'bg-red-50/40' : ''}>
                                            <td className="px-4 py-3 font-medium">
                                                {it.procurement_item ? `${it.procurement_item.name} (${it.procurement_item.code})` : it.item_description}
                                            </td>
                                            <td className="px-4 py-3">{Number(it.quantity_billed)} {it.unit_of_measure}</td>
                                            <td className="px-4 py-3">
                                                {Number(it.quantity_variance) !== 0 ? (
                                                    <span className="font-semibold text-red-600">
                                                        {Number(it.quantity_variance) > 0 ? `+${it.quantity_variance}` : it.quantity_variance} (Overbill)
                                                    </span>
                                                ) : (
                                                    <span className="text-green-600 font-medium">0 (Match)</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">TZS {Number(it.unit_price).toLocaleString()}</td>
                                            <td className="px-4 py-3">
                                                {Number(it.price_variance) !== 0 ? (
                                                    <span className="font-semibold text-red-600">
                                                        {Number(it.price_variance) > 0 ? `+TZS ${Number(it.price_variance).toLocaleString()}` : `-TZS ${Math.abs(Number(it.price_variance)).toLocaleString()}`}
                                                    </span>
                                                ) : (
                                                    <span className="text-green-600 font-medium">Match</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 font-semibold">
                                                TZS {Number(it.total_price).toLocaleString()}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                {it.has_discrepancy ? (
                                                    <Badge variant="destructive">Discrepancy</Badge>
                                                ) : (
                                                    <Badge className="bg-green-600 text-white">Verified</Badge>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="bg-muted/40 font-semibold text-primary">
                                        <td colSpan={5} className="px-4 py-3 text-right text-base">Grand Total Billed:</td>
                                        <td colSpan={2} className="px-4 py-3 text-base">TZS {Number(invoice.total_amount).toLocaleString()}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {/* Resolution Notes If Approved */}
                {invoice.status === 'approved' && invoice.resolution_notes && (
                    <Card className="border-green-200 bg-green-50/40">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-semibold text-green-900">Payment Authorization & Resolution Notes</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm text-green-800">
                            Approved by {invoice.approved_by?.name || 'Finance Officer'} on {invoice.approved_at?.split('T')[0]}:
                            <blockquote className="mt-1 pl-3 border-l-2 border-green-600 italic">
                                "{invoice.resolution_notes}"
                            </blockquote>
                        </CardContent>
                    </Card>
                )}

                {/* Approve Modal */}
                <Dialog open={isApproveModalOpen} onOpenChange={setIsApproveModalOpen}>
                    <DialogContent>
                        <form onSubmit={handleApproveSubmit}>
                            <DialogHeader>
                                <DialogTitle>Approve Invoice for Payment</DialogTitle>
                                <DialogDescription>
                                    Authorize accounts payable payout to vendor. If discrepancies were identified, explain resolution rationale (e.g. credit note agreed, price revised).
                                </DialogDescription>
                            </DialogHeader>
                            <div className="py-4 space-y-2">
                                <Label htmlFor="resolution_notes">Resolution / Finance Authorization Notes</Label>
                                <Textarea
                                    id="resolution_notes"
                                    value={approveData.resolution_notes}
                                    onChange={(e) => setApproveData('resolution_notes', e.target.value)}
                                    placeholder="Enter approval comments or explain how price/quantity variances were resolved..."
                                    rows={3}
                                />
                            </div>
                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setIsApproveModalOpen(false)}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={approveProcessing} className="bg-green-600 hover:bg-green-700">
                                    {approveProcessing ? 'Authorizing...' : 'Authorize Payment'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
