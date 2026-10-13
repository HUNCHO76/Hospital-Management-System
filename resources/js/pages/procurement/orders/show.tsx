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
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    Clock,
    Edit3,
    PackageCheck,
    Send,
    ShieldAlert,
    Trash2,
    Truck,
} from 'lucide-react';
import { useState } from 'react';

interface PurchaseOrderItem {
    id: number;
    procurement_item_id: number;
    item_description: string;
    quantity_ordered: number;
    quantity_received: number;
    unit_of_measure: string;
    unit_price: number;
    tax_percent: number;
    total_price: number;
    notes: string | null;
    procurement_item?: { code: string; name: string };
}

interface PurchaseOrder {
    id: number;
    po_number: string;
    vendor: { id: number; name: string; contact_person: string; phone: string; email: string };
    receiving_store: { id: number; name: string; type: string };
    created_by?: { id: number; name: string };
    approved_by?: { name: string };
    approved_at: string | null;
    po_date: string;
    expected_delivery_date: string | null;
    payment_terms: string | null;
    shipping_terms: string | null;
    subtotal: number;
    tax_amount: number;
    freight_amount: number;
    total_amount: number;
    status: 'draft' | 'submitted' | 'approved' | 'sent' | 'partially_received' | 'received' | 'cancelled';
    notes: string | null;
    items: PurchaseOrderItem[];
    goods_receipt_notes?: any[];
    invoices?: any[];
    approval_histories?: any[];
}

interface Props {
    order: PurchaseOrder;
}

export default function ShowPurchaseOrder({ order }: Props) {
    const { can } = usePermissions();
    const { auth } = usePage<any>().props;
    const currentUserId = auth?.user?.id;
    const canApprove = currentUserId !== order.created_by?.id; // Separation of duties principle!

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Procurement', href: '/procurement' },
        { title: 'Purchase Orders', href: '/procurement/orders' },
        { title: order.po_number, href: `/procurement/orders/${order.id}` },
    ];

    const [isApproveModalOpen, setIsApproveModalOpen] = useState(false);
    const [isCancelModalOpen, setIsCancelModalOpen] = useState(false);

    const { post: postAction, processing: actionProcessing } = useForm({});
    const { data: approveData, setData: setApproveData, post: postApprove, processing: approveProcessing } = useForm({
        comments: '',
    });
    const { data: cancelData, setData: setCancelData, post: postCancel, processing: cancelProcessing } = useForm({
        reason: '',
    });

    const handleSubmitForApproval = () => {
        postAction(`/procurement/orders/${order.id}/submit`);
    };

    const handleSendToVendor = () => {
        postAction(`/procurement/orders/${order.id}/send`);
    };

    const handleApproveSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        postApprove(`/procurement/orders/${order.id}/approve`, {
            onSuccess: () => setIsApproveModalOpen(false),
        });
    };

    const handleCancelSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        postCancel(`/procurement/orders/${order.id}/cancel`, {
            onSuccess: () => setIsCancelModalOpen(false),
        });
    };

    const getStatusBadge = (st: string) => {
        switch (st) {
            case 'draft':
                return <Badge variant="outline">Draft</Badge>;
            case 'submitted':
                return <Badge variant="secondary" className="bg-yellow-100 text-yellow-800">Submitted for Approval</Badge>;
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
            <Head title={`PO ${order.po_number}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div className="flex items-center gap-3">
                            <PageHeader
                                title={`Purchase Order: ${order.po_number}`}
                                description={`Issued to ${order.vendor?.name} for delivery to ${order.receiving_store?.name}`}
                            />
                            {getStatusBadge(order.status)}
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Link href="/procurement/orders">
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="mr-2 h-4 w-4" /> Back to Orders
                            </Button>
                        </Link>

                        {/* Action Buttons depending on status */}
                        {order.status === 'draft' && (
                            <>
                                {can('procurement-purchase-orders.update') && (
                                    <Link href={`/procurement/orders/${order.id}/edit`}>
                                        <Button variant="outline" size="sm">
                                            <Edit3 className="mr-1 h-4 w-4" /> Edit
                                        </Button>
                                    </Link>
                                )}
                                {can('procurement-purchase-orders.submit') && (
                                    <Button size="sm" onClick={handleSubmitForApproval} disabled={actionProcessing}>
                                        <Send className="mr-1 h-4 w-4" /> Submit for Approval
                                    </Button>
                                )}
                            </>
                        )}

                        {order.status === 'submitted' && (
                            <>
                                {can('procurement-purchase-orders.approve') && canApprove && (
                                    <Button
                                        size="sm"
                                        onClick={() => setIsApproveModalOpen(true)}
                                        className="bg-green-600 hover:bg-green-700"
                                    >
                                        <CheckCircle2 className="mr-1 h-4 w-4" /> Approve PO
                                    </Button>
                                )}
                                {can('procurement-purchase-orders.cancel') && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setIsCancelModalOpen(true)}
                                        className="text-red-600 border-red-200 hover:bg-red-50"
                                    >
                                        Cancel Order
                                    </Button>
                                )}
                            </>
                        )}

                        {order.status === 'approved' && (
                            <>
                                {can('procurement-purchase-orders.send') && (
                                    <Button size="sm" onClick={handleSendToVendor} disabled={actionProcessing}>
                                        <Truck className="mr-1 h-4 w-4" /> Dispatch to Vendor
                                    </Button>
                                )}
                                {can('procurement-purchase-orders.cancel') && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setIsCancelModalOpen(true)}
                                        className="text-red-600 border-red-200 hover:bg-red-50"
                                    >
                                        Cancel Order
                                    </Button>
                                )}
                            </>
                        )}

                        {(order.status === 'sent' || order.status === 'partially_received') && can('stores-grn.create') && (
                            <Link href={`/stores/grn/create?purchase_order_id=${order.id}`}>
                                <Button size="sm" className="bg-emerald-600 hover:bg-emerald-700">
                                    <PackageCheck className="mr-1 h-4 w-4" /> Create GRN (Receive Goods)
                                </Button>
                            </Link>
                        )}
                    </div>
                </div>

                {/* Details Cards */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground uppercase">Vendor Details</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm space-y-1">
                            <p className="font-semibold text-base">{order.vendor?.name}</p>
                            <p className="text-muted-foreground">Contact: {order.vendor?.contact_person || 'N/A'}</p>
                            <p className="text-muted-foreground">Phone: {order.vendor?.phone || 'N/A'}</p>
                            <p className="text-muted-foreground">Email: {order.vendor?.email || 'N/A'}</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground uppercase">Order & Delivery Terms</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm space-y-1">
                            <p><span className="text-muted-foreground">PO Date:</span> {order.po_date}</p>
                            <p><span className="text-muted-foreground">Receiving Store:</span> {order.receiving_store?.name}</p>
                            <p><span className="text-muted-foreground">Expected Delivery:</span> {order.expected_delivery_date || 'Standard'}</p>
                            <p><span className="text-muted-foreground">Payment Terms:</span> {order.payment_terms || 'Standard'}</p>
                            <p><span className="text-muted-foreground">Incoterms:</span> {order.shipping_terms || 'DDP Hospital Dock'}</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground uppercase">Audit & Governance</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm space-y-1">
                            <p><span className="text-muted-foreground">Created By:</span> {order.created_by?.name || 'Officer'}</p>
                            {order.approved_by && (
                                <p><span className="text-muted-foreground">Approved By:</span> {order.approved_by?.name} ({order.approved_at?.split('T')[0]})</p>
                            )}
                            {order.notes && (
                                <p className="text-xs text-muted-foreground mt-2 italic bg-muted p-2 rounded">
                                    "{order.notes}"
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Ordered Items Table */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Ordered Line Items ({order.items.length})</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3">Item Description</th>
                                        <th className="px-4 py-3">Qty Ordered</th>
                                        <th className="px-4 py-3">Qty Received</th>
                                        <th className="px-4 py-3">UOM</th>
                                        <th className="px-4 py-3">Unit Price (TZS)</th>
                                        <th className="px-4 py-3">VAT %</th>
                                        <th className="px-4 py-3 text-right">Total Price (TZS)</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {order.items.map((it) => (
                                        <tr key={it.id}>
                                            <td className="px-4 py-3 font-medium">
                                                {it.procurement_item ? `${it.procurement_item.name} (${it.procurement_item.code})` : it.item_description}
                                            </td>
                                            <td className="px-4 py-3 font-semibold">{Number(it.quantity_ordered)}</td>
                                            <td className="px-4 py-3">
                                                <span className={Number(it.quantity_received) >= Number(it.quantity_ordered) ? 'text-green-600 font-semibold' : 'text-amber-600'}>
                                                    {Number(it.quantity_received)}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">{it.unit_of_measure}</td>
                                            <td className="px-4 py-3">TZS {Number(it.unit_price).toLocaleString()}</td>
                                            <td className="px-4 py-3">{Number(it.tax_percent) || 0}%</td>
                                            <td className="px-4 py-3 text-right font-semibold">
                                                TZS {Number(it.total_price).toLocaleString()}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="bg-muted/40 font-medium">
                                        <td colSpan={6} className="px-4 py-2 text-right">Subtotal:</td>
                                        <td className="px-4 py-2 text-right">TZS {Number(order.subtotal).toLocaleString()}</td>
                                    </tr>
                                    <tr className="bg-muted/40 font-medium">
                                        <td colSpan={6} className="px-4 py-2 text-right">VAT:</td>
                                        <td className="px-4 py-2 text-right">TZS {Number(order.tax_amount).toLocaleString()}</td>
                                    </tr>
                                    <tr className="bg-muted/60 font-semibold text-primary">
                                        <td colSpan={6} className="px-4 py-3 text-right text-base">Grand Total:</td>
                                        <td className="px-4 py-3 text-right text-base">TZS {Number(order.total_amount).toLocaleString()}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {/* Linked Goods Receipt Notes */}
                {order.goods_receipt_notes && order.goods_receipt_notes.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Linked Goods Receipt Notes (GRN)</CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                        <tr>
                                            <th className="px-4 py-2">GRN Number</th>
                                            <th className="px-4 py-2">Receipt Date</th>
                                            <th className="px-4 py-2">Status</th>
                                            <th className="px-4 py-2 text-right">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {order.goods_receipt_notes.map((grn: any) => (
                                            <tr key={grn.id}>
                                                <td className="px-4 py-2 font-medium text-primary">{grn.grn_number}</td>
                                                <td className="px-4 py-2 text-muted-foreground">{grn.received_date}</td>
                                                <td className="px-4 py-2">
                                                    <Badge variant="outline">{grn.status}</Badge>
                                                </td>
                                                <td className="px-4 py-2 text-right">
                                                    <Link href={`/stores/grn/${grn.id}`}>
                                                        <Button variant="ghost" size="sm">View GRN</Button>
                                                    </Link>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Approve Modal */}
                <Dialog open={isApproveModalOpen} onOpenChange={setIsApproveModalOpen}>
                    <DialogContent>
                        <form onSubmit={handleApproveSubmit}>
                            <DialogHeader>
                                <DialogTitle>Approve Purchase Order</DialogTitle>
                                <DialogDescription>
                                    Verify commitment amount and approve this purchase order. Note: System enforces separation of duties; creator cannot self-approve.
                                </DialogDescription>
                            </DialogHeader>
                            <div className="py-4 space-y-2">
                                <Label htmlFor="comments">Approval Remarks / Authorization Notes</Label>
                                <Textarea
                                    id="comments"
                                    value={approveData.comments}
                                    onChange={(e) => setApproveData('comments', e.target.value)}
                                    placeholder="Order verified against procurement budget and approved..."
                                    rows={3}
                                />
                            </div>
                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setIsApproveModalOpen(false)}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={approveProcessing} className="bg-green-600 hover:bg-green-700">
                                    {approveProcessing ? 'Approving...' : 'Confirm Approval'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>

                {/* Cancel Modal */}
                <Dialog open={isCancelModalOpen} onOpenChange={setIsCancelModalOpen}>
                    <DialogContent>
                        <form onSubmit={handleCancelSubmit}>
                            <DialogHeader>
                                <DialogTitle>Cancel Purchase Order</DialogTitle>
                                <DialogDescription>
                                    Please enter the reason for cancelling this purchase order. This action will be audited.
                                </DialogDescription>
                            </DialogHeader>
                            <div className="py-4 space-y-2">
                                <Label htmlFor="cancel_reason">Cancellation Reason *</Label>
                                <Textarea
                                    id="cancel_reason"
                                    required
                                    value={cancelData.reason}
                                    onChange={(e) => setCancelData('reason', e.target.value)}
                                    placeholder="Explain why this order is being cancelled..."
                                    rows={3}
                                />
                            </div>
                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setIsCancelModalOpen(false)}>
                                    Back
                                </Button>
                                <Button type="submit" variant="destructive" disabled={cancelProcessing || !cancelData.reason}>
                                    {cancelProcessing ? 'Cancelling...' : 'Confirm Cancellation'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
