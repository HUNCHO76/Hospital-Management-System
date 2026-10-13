import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Ban, CheckCircle2, Edit, FileSpreadsheet, Send, ShoppingCart, XCircle } from 'lucide-react';
import { useState } from 'react';

interface IndentItem {
    id: number;
    procurement_item_id: number;
    item_name: string;
    unit_of_measure: string;
    quantity_requested: number;
    quantity_approved: number;
    estimated_unit_cost: number | null;
    estimated_total_cost: number | null;
    purpose: string | null;
}

interface ApprovalRecord {
    id: number;
    action: string;
    decision: string;
    comments: string | null;
    created_at: string;
    user?: { UserName: string };
}

interface Indent {
    id: number;
    indent_number: string;
    department?: { id: number; name: string };
    requested_by?: { UserName: string };
    target_store?: { id: number; name: string };
    approved_by?: { UserName: string };
    approved_at: string | null;
    indent_date: string;
    required_by_date: string | null;
    priority: string;
    status: string;
    rejection_reason: string | null;
    notes: string | null;
    items: IndentItem[];
    approval_histories: ApprovalRecord[];
}

interface Props {
    indent: Indent;
}

function getStatusBadge(status: string) {
    switch (status.toUpperCase()) {
        case 'APPROVED':
        case 'FULFILLED':
            return <Badge className="bg-emerald-600 text-white">{status}</Badge>;
        case 'SUBMITTED':
            return <Badge className="bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300">{status}</Badge>;
        case 'REJECTED':
        case 'CANCELLED':
            return <Badge variant="destructive">{status}</Badge>;
        default:
            return <Badge variant="outline">{status}</Badge>;
    }
}

export default function ShowIndent({ indent }: Props) {
    const { can } = usePermissions();

    const [isApproveOpen, setIsApproveOpen] = useState(false);
    const [isRejectOpen, setIsRejectOpen] = useState(false);
    const [isCancelOpen, setIsCancelOpen] = useState(false);

    const [rejectReason, setRejectReason] = useState('');
    const [cancelReason, setCancelReason] = useState('');
    const [approveComments, setApproveComments] = useState('');
    const [itemApprovals, setItemApprovals] = useState<{ id: number; quantity_approved: number }[]>(
        indent.items.map((i) => ({ id: i.id, quantity_approved: i.quantity_requested }))
    );

    const handleSubmit = () => {
        router.post(`/procurement/indents/${indent.id}/submit`);
    };

    const handleApprove = () => {
        router.post(`/procurement/indents/${indent.id}/approve`, {
            comments: approveComments,
            items: itemApprovals,
        }, {
            onSuccess: () => setIsApproveOpen(false),
        });
    };

    const handleReject = () => {
        router.post(`/procurement/indents/${indent.id}/reject`, {
            reason: rejectReason,
        }, {
            onSuccess: () => setIsRejectOpen(false),
        });
    };

    const handleCancel = () => {
        router.post(`/procurement/indents/${indent.id}/cancel`, {
            reason: cancelReason,
        }, {
            onSuccess: () => setIsCancelOpen(false),
        });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Procurement', href: '/procurement' },
        { title: 'Purchase Indents', href: '/procurement/indents' },
        { title: indent.indent_number, href: `/procurement/indents/${indent.id}` },
    ];

    const isDraft = indent.status === 'DRAFT';
    const isSubmitted = indent.status === 'SUBMITTED';
    const isApproved = indent.status === 'APPROVED';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Indent ${indent.indent_number}`} />

            <div className="flex flex-col gap-6 p-4 max-w-6xl mx-auto w-full">
                <PageHeader
                    title={`Indent ${indent.indent_number}`}
                    description={`Requisition from ${indent.department?.name || 'Department'} created on ${indent.indent_date}`}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Button asChild variant="outline" size="sm">
                                <Link href="/procurement/indents">
                                    <ArrowLeft className="mr-1.5 h-4 w-4" /> All Indents
                                </Link>
                            </Button>

                            {isDraft && can('procurement-indents.update') && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={`/procurement/indents/${indent.id}/edit`}>
                                        <Edit className="mr-1.5 h-4 w-4" /> Edit
                                    </Link>
                                </Button>
                            )}

                            {isDraft && can('procurement-indents.submit') && (
                                <Button onClick={handleSubmit} size="sm">
                                    <Send className="mr-1.5 h-4 w-4" /> Submit for Approval
                                </Button>
                            )}

                            {isSubmitted && can('procurement-indents.approve') && (
                                <Button onClick={() => setIsApproveOpen(true)} size="sm" className="bg-emerald-600 hover:bg-emerald-700 text-white">
                                    <CheckCircle2 className="mr-1.5 h-4 w-4" /> Approve Indent
                                </Button>
                            )}

                            {isSubmitted && can('procurement-indents.reject') && (
                                <Button onClick={() => setIsRejectOpen(true)} variant="outline" size="sm" className="text-destructive border-destructive/30 hover:bg-destructive/10">
                                    <XCircle className="mr-1.5 h-4 w-4" /> Reject
                                </Button>
                            )}

                            {(isDraft || isSubmitted) && can('procurement-indents.cancel') && (
                                <Button onClick={() => setIsCancelOpen(true)} variant="ghost" size="sm" className="text-muted-foreground">
                                    <Ban className="mr-1.5 h-4 w-4" /> Cancel
                                </Button>
                            )}

                            {isApproved && (
                                <>
                                    {can('procurement-quotations.create') && (
                                        <Button asChild variant="outline" size="sm">
                                            <Link href={`/procurement/quotations/create?indent_id=${indent.id}`}>
                                                <FileSpreadsheet className="mr-1.5 h-4 w-4" /> Solicit Quotes
                                            </Link>
                                        </Button>
                                    )}
                                    {can('procurement-purchase-orders.create') && (
                                        <Button asChild size="sm">
                                            <Link href={`/procurement/orders/create?indent_id=${indent.id}`}>
                                                <ShoppingCart className="mr-1.5 h-4 w-4" /> Create Purchase Order
                                            </Link>
                                        </Button>
                                    )}
                                </>
                            )}
                        </div>
                    }
                />

                {/* Status bar */}
                <div className="grid gap-4 sm:grid-cols-4">
                    <Card>
                        <CardContent className="pt-5">
                            <p className="text-xs text-muted-foreground font-medium">Requisition Status</p>
                            <div className="mt-1">{getStatusBadge(indent.status)}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-5">
                            <p className="text-xs text-muted-foreground font-medium">Requesting Department</p>
                            <p className="text-sm font-semibold mt-1">{indent.department?.name || '—'}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-5">
                            <p className="text-xs text-muted-foreground font-medium">Requested By</p>
                            <p className="text-sm font-semibold mt-1">{indent.requested_by?.UserName || '—'}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-5">
                            <p className="text-xs text-muted-foreground font-medium">Target Store / Destination</p>
                            <p className="text-sm font-semibold mt-1">{indent.target_store?.name || 'Central Store'}</p>
                        </CardContent>
                    </Card>
                </div>

                {indent.rejection_reason && (
                    <Card className="border-destructive/40 bg-destructive/5">
                        <CardContent className="pt-4 text-sm text-destructive">
                            <span className="font-semibold">Rejection / Cancellation Reason:</span> {indent.rejection_reason}
                        </CardContent>
                    </Card>
                )}

                {/* Items Table */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base font-semibold">Requested Supplies List</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Item Name</TableHead>
                                    <TableHead>Unit</TableHead>
                                    <TableHead className="text-center">Qty Requested</TableHead>
                                    <TableHead className="text-center">Qty Approved</TableHead>
                                    <TableHead className="text-right">Est. Unit Price</TableHead>
                                    <TableHead className="text-right">Est. Total</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {indent.items.map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell>
                                            <p className="font-medium text-sm">{item.item_name}</p>
                                            {item.purpose && <p className="text-xs text-muted-foreground">{item.purpose}</p>}
                                        </TableCell>
                                        <TableCell>{item.unit_of_measure}</TableCell>
                                        <TableCell className="text-center font-semibold">{item.quantity_requested}</TableCell>
                                        <TableCell className="text-center font-semibold text-emerald-600">
                                            {item.quantity_approved}
                                        </TableCell>
                                        <TableCell className="text-right font-mono text-sm">
                                            {item.estimated_unit_cost ? Number(item.estimated_unit_cost).toLocaleString() : '—'}
                                        </TableCell>
                                        <TableCell className="text-right font-mono text-sm">
                                            {item.estimated_total_cost ? Number(item.estimated_total_cost).toLocaleString() : '—'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {/* Audit & Approval History */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base font-semibold">Approval & Audit Trail</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {indent.approval_histories.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No workflow events recorded.</p>
                        ) : (
                            <div className="space-y-3">
                                {indent.approval_histories.map((hist) => (
                                    <div key={hist.id} className="flex items-start justify-between border-b pb-2 text-sm">
                                        <div>
                                            <span className="font-semibold capitalize">{hist.action}</span> by{' '}
                                            <span className="text-muted-foreground">{hist.user?.UserName || 'System'}</span>
                                            {hist.comments && <p className="text-xs text-muted-foreground mt-0.5">{hist.comments}</p>}
                                        </div>
                                        <span className="text-xs text-muted-foreground">
                                            {new Date(hist.created_at).toLocaleString()}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Approve Dialog */}
                <Dialog open={isApproveOpen} onOpenChange={setIsApproveOpen}>
                    <DialogContent className="max-w-xl">
                        <DialogHeader>
                            <DialogTitle>Approve Purchase Requisition Indent</DialogTitle>
                            <DialogDescription>
                                Confirm or adjust the approved quantities for each requested supply item.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-4 py-2">
                            <div className="space-y-2">
                                <Label>Adjust Approved Quantities</Label>
                                {indent.items.map((it, idx) => (
                                    <div key={it.id} className="flex items-center justify-between gap-4 p-2 bg-muted/40 rounded">
                                        <span className="text-sm font-medium">{it.item_name} ({it.quantity_requested} requested)</span>
                                        <Input
                                            type="number"
                                            min="0"
                                            className="w-24 text-right"
                                            value={itemApprovals[idx]?.quantity_approved ?? it.quantity_requested}
                                            onChange={(e) => {
                                                const next = [...itemApprovals];
                                                next[idx] = { id: it.id, quantity_approved: parseInt(e.target.value) || 0 };
                                                setItemApprovals(next);
                                            }}
                                        />
                                    </div>
                                ))}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="approveComments">Approval Comments / Note</Label>
                                <Textarea
                                    id="approveComments"
                                    rows={2}
                                    placeholder="Optional approval notes..."
                                    value={approveComments}
                                    onChange={(e) => setApproveComments(e.target.value)}
                                />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setIsApproveOpen(false)}>Cancel</Button>
                            <Button onClick={handleApprove} className="bg-emerald-600 hover:bg-emerald-700 text-white">
                                Confirm Approval
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* Reject Dialog */}
                <Dialog open={isRejectOpen} onOpenChange={setIsRejectOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Reject Requisition Indent</DialogTitle>
                            <DialogDescription>
                                Please specify the justification reason for rejecting this requisition.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-3 py-2">
                            <Label htmlFor="rejectReason">Rejection Reason *</Label>
                            <Textarea
                                id="rejectReason"
                                rows={3}
                                placeholder="Reason for rejection..."
                                value={rejectReason}
                                onChange={(e) => setRejectReason(e.target.value)}
                                required
                            />
                        </div>
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setIsRejectOpen(false)}>Cancel</Button>
                            <Button onClick={handleReject} variant="destructive" disabled={!rejectReason.trim()}>
                                Confirm Rejection
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* Cancel Dialog */}
                <Dialog open={isCancelOpen} onOpenChange={setIsCancelOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Cancel Requisition Indent</DialogTitle>
                            <DialogDescription>
                                State the reason for cancelling this requisition.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-3 py-2">
                            <Label htmlFor="cancelReason">Cancellation Reason *</Label>
                            <Textarea
                                id="cancelReason"
                                rows={3}
                                placeholder="Reason for cancellation..."
                                value={cancelReason}
                                onChange={(e) => setCancelReason(e.target.value)}
                                required
                            />
                        </div>
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setIsCancelOpen(false)}>Close</Button>
                            <Button onClick={handleCancel} variant="destructive" disabled={!cancelReason.trim()}>
                                Confirm Cancellation
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
