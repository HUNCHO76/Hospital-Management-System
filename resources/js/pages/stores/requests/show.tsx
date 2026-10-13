import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Clock, Send, Share2 } from 'lucide-react';
import { useState } from 'react';

interface RequestItem {
    id: number;
    procurement_item_id: number;
    quantity_requested: number;
    quantity_approved: number;
    quantity_issued: number;
    purpose: string | null;
    procurement_item?: { code: string; name: string; unit_of_measure: string };
}

interface StockRequest {
    id: number;
    request_number: string;
    department: { id: number; name: string };
    from_store: { id: number; name: string };
    requested_by?: { name: string };
    approved_by?: { name: string };
    approved_at: string | null;
    request_date: string;
    required_by_date: string | null;
    priority: string;
    status: 'draft' | 'submitted' | 'approved' | 'partially_issued' | 'issued' | 'rejected' | 'cancelled';
    notes: string | null;
    items: RequestItem[];
    issues?: any[];
    approval_histories?: any[];
}

interface Props {
    stock_request: StockRequest;
}

export default function ShowStockRequest({ stock_request }: Props) {
    const { can } = usePermissions();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Store & Inventory', href: '/stores' },
        { title: 'Department Requests', href: '/stores/requests' },
        { title: stock_request.request_number, href: `/stores/requests/${stock_request.id}` },
    ];

    const [isApproveModalOpen, setIsApproveModalOpen] = useState(false);

    const initialApprovalLines = stock_request.items.map((it) => ({
        id: it.id,
        quantity_approved: it.quantity_approved || it.quantity_requested,
    }));

    const { data: approveData, setData: setApproveData, post: postApprove, processing: approveProcessing } = useForm({
        comments: '',
        items: initialApprovalLines,
    });

    const handleApproveSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        postApprove(`/stores/requests/${stock_request.id}/approve`, {
            onSuccess: () => setIsApproveModalOpen(false),
        });
    };

    const updateApprovedQty = (index: number, val: number) => {
        const next = [...approveData.items];
        next[index].quantity_approved = val;
        setApproveData('items', next);
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
            default:
                return <Badge variant="outline">{st}</Badge>;
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Request ${stock_request.request_number}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div className="flex items-center gap-3">
                            <PageHeader
                                title={`Stock Request: ${stock_request.request_number}`}
                                description={`Requested by ${stock_request.department?.name} from ${stock_request.from_store?.name}`}
                            />
                            {getStatusBadge(stock_request.status)}
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Link href="/stores/requests">
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="mr-2 h-4 w-4" /> Back to Requests
                            </Button>
                        </Link>

                        {stock_request.status === 'submitted' && can('stores-requests.approve') && (
                            <Button size="sm" onClick={() => setIsApproveModalOpen(true)} className="bg-green-600 hover:bg-green-700">
                                <CheckCircle2 className="mr-1 h-4 w-4" /> Approve Requisition
                            </Button>
                        )}

                        {(stock_request.status === 'approved' || stock_request.status === 'partially_issued') && can('stores-issues.create') && (
                            <Link href={`/stores/issues/create?request_id=${stock_request.id}`}>
                                <Button size="sm">
                                    <Send className="mr-1 h-4 w-4" /> Allocate & Issue Stock (FEFO)
                                </Button>
                            </Link>
                        )}
                    </div>
                </div>

                {/* Details Cards */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground uppercase">Clinical Unit</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm space-y-1">
                            <p className="font-semibold">{stock_request.department?.name}</p>
                            <p className="text-muted-foreground">Requested By: {stock_request.requested_by?.name || 'Staff'}</p>
                            <p className="text-muted-foreground">Priority: <span className="capitalize font-medium text-foreground">{stock_request.priority}</span></p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground uppercase">Fulfillment Store</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm space-y-1">
                            <p className="font-semibold">{stock_request.from_store?.name}</p>
                            <p className="text-muted-foreground">Request Date: {stock_request.request_date}</p>
                            <p className="text-muted-foreground">Required By: {stock_request.required_by_date || 'Standard'}</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground uppercase">Approval & Notes</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm space-y-1">
                            {stock_request.approved_by ? (
                                <p><span className="text-muted-foreground">Approved By:</span> {stock_request.approved_by.name} ({stock_request.approved_at?.split('T')[0]})</p>
                            ) : (
                                <p className="text-muted-foreground">Awaiting Store Manager Approval</p>
                            )}
                            {stock_request.notes && (
                                <p className="text-xs text-muted-foreground mt-2 italic bg-muted p-2 rounded">
                                    "{stock_request.notes}"
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Items Table */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base font-semibold">Requested Items ({stock_request.items.length})</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3">Item Description</th>
                                        <th className="px-4 py-3">UOM</th>
                                        <th className="px-4 py-3">Qty Requested</th>
                                        <th className="px-4 py-3">Qty Approved</th>
                                        <th className="px-4 py-3">Qty Issued</th>
                                        <th className="px-4 py-3">Purpose</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {stock_request.items.map((it) => (
                                        <tr key={it.id}>
                                            <td className="px-4 py-3 font-medium">
                                                {it.procurement_item ? `${it.procurement_item.name} (${it.procurement_item.code})` : 'Item'}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {it.procurement_item?.unit_of_measure}
                                            </td>
                                            <td className="px-4 py-3 font-semibold">{Number(it.quantity_requested)}</td>
                                            <td className="px-4 py-3 font-semibold text-blue-700">
                                                {Number(it.quantity_approved)}
                                            </td>
                                            <td className="px-4 py-3 font-semibold text-green-700">
                                                {Number(it.quantity_issued)}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">{it.purpose || '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {/* Linked Issues */}
                {stock_request.issues && stock_request.issues.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base font-semibold">Dispatched Stock Issues</CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="divide-y text-xs">
                                {stock_request.issues.map((iss: any) => (
                                    <div key={iss.id} className="p-3 flex items-center justify-between hover:bg-muted/40">
                                        <div>
                                            <Link href={`/stores/issues/${iss.id}`} className="font-semibold text-primary hover:underline">
                                                {iss.issue_number}
                                            </Link>
                                            <div className="text-muted-foreground">Issued on {iss.issue_date}</div>
                                        </div>
                                        <Badge variant="outline" className="capitalize">{iss.status}</Badge>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Approval Dialog */}
                <Dialog open={isApproveModalOpen} onOpenChange={setIsApproveModalOpen}>
                    <DialogContent className="max-w-2xl">
                        <form onSubmit={handleApproveSubmit}>
                            <DialogHeader>
                                <DialogTitle>Approve Department Stock Request</DialogTitle>
                                <DialogDescription>
                                    Review quantities requested by clinical staff and approve allowable stock allocations.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="py-4 space-y-4">
                                <div className="space-y-1">
                                    <Label htmlFor="appr_comments">Approval Comments</Label>
                                    <Textarea
                                        id="appr_comments"
                                        placeholder="Enter approval authorization notes..."
                                        value={approveData.comments}
                                        onChange={(e) => setApproveData('comments', e.target.value)}
                                        rows={2}
                                    />
                                </div>

                                <div className="space-y-2">
                                    <h4 className="font-semibold text-xs uppercase text-muted-foreground">Quantity Allocations</h4>
                                    {stock_request.items.map((item, idx) => (
                                        <div key={item.id} className="p-2 border rounded flex items-center justify-between text-sm">
                                            <div className="font-medium">
                                                {item.procurement_item?.name}
                                                <div className="text-xs text-muted-foreground">Requested: {item.quantity_requested}</div>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <Label className="text-xs">Approved Qty:</Label>
                                                <Input
                                                    type="number"
                                                    min="0"
                                                    max={item.quantity_requested}
                                                    value={approveData.items[idx]?.quantity_approved}
                                                    onChange={(e) => updateApprovedQty(idx, parseInt(e.target.value) || 0)}
                                                    className="h-8 w-24"
                                                    required
                                                />
                                            </div>
                                        </div>
                                    ))}
                                </div>
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
            </div>
        </AppLayout>
    );
}
