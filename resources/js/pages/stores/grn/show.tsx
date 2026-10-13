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
import {
    AlertCircle,
    ArrowLeft,
    CheckCircle2,
    Clock,
    FileCheck,
    Layers,
    PackageCheck,
    ShieldAlert,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';

interface GRNItem {
    id: number;
    procurement_item_id: number;
    item_description: string;
    quantity_received: number;
    quantity_accepted: number;
    quantity_rejected: number;
    rejection_reason: string | null;
    unit_of_measure: string;
    unit_cost: number;
    total_cost: number;
    batch_number: string | null;
    expiry_date: string | null;
    storage_location: string | null;
    procurement_item?: { code: string; name: string };
    stock_batch?: { id: number; batch_number: string; quantity: number };
}

interface GRN {
    id: number;
    grn_number: string;
    delivery_note_number: string | null;
    invoice_reference: string | null;
    purchase_order?: { id: number; po_number: string } | null;
    vendor?: { id: number; name: string; phone: string } | null;
    store: { id: number; name: string; type: string };
    received_date: string;
    inspected_date: string | null;
    posted_at: string | null;
    status: 'inspection_pending' | 'partially_accepted' | 'accepted' | 'rejected' | 'posted';
    total_amount: number;
    notes: string | null;
    inspection_notes: string | null;
    received_by?: { name: string };
    inspected_by?: { name: string };
    posted_by?: { name: string };
    items: GRNItem[];
    approval_histories?: any[];
}

interface Props {
    grn: GRN;
}

export default function ShowGRN({ grn }: Props) {
    const { can } = usePermissions();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Store & Inventory', href: '/stores' },
        { title: 'Goods Receipt Notes', href: '/stores/grn' },
        { title: grn.grn_number, href: `/stores/grn/${grn.id}` },
    ];

    const [isInspectModalOpen, setIsInspectModalOpen] = useState(false);

    // Prepare inspect form lines
    const initialInspectLines = grn.items.map((it) => ({
        id: it.id,
        quantity_accepted: it.quantity_accepted || it.quantity_received,
        quantity_rejected: it.quantity_rejected || 0,
        rejection_reason: it.rejection_reason || '',
        batch_number: it.batch_number || '',
        expiry_date: it.expiry_date ? it.expiry_date.split('T')[0] : '',
        storage_location: it.storage_location || 'Bay A-1',
    }));

    const { data: inspectData, setData: setInspectData, post: postInspect, processing: inspectProcessing } = useForm({
        inspection_notes: grn.inspection_notes || '',
        items: initialInspectLines,
    });

    const { post: postForm, processing: postProcessing } = useForm({});

    const handleInspectSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        postInspect(`/stores/grn/${grn.id}/inspect`, {
            onSuccess: () => setIsInspectModalOpen(false),
        });
    };

    const handlePostToStock = () => {
        postForm(`/stores/grn/${grn.id}/post`);
    };

    const updateInspectItem = (index: number, field: string, value: any) => {
        const next = [...inspectData.items];
        (next[index] as any)[field] = value;
        if (field === 'quantity_accepted') {
            const accepted = parseInt(value) || 0;
            const total = grn.items[index].quantity_received;
            next[index].quantity_rejected = Math.max(0, total - accepted);
        }
        setInspectData('items', next);
    };

    const getStatusBadge = (st: string) => {
        switch (st) {
            case 'inspection_pending':
                return <Badge variant="secondary" className="bg-yellow-100 text-yellow-800">Inspection Pending</Badge>;
            case 'partially_accepted':
                return <Badge variant="secondary" className="bg-amber-100 text-amber-800">Partially Accepted</Badge>;
            case 'accepted':
                return <Badge variant="secondary" className="bg-blue-100 text-blue-800">Accepted</Badge>;
            case 'posted':
                return <Badge variant="secondary" className="bg-green-100 text-green-800 font-semibold flex items-center gap-1"><CheckCircle2 className="h-3 w-3" /> Posted to Stock</Badge>;
            case 'rejected':
                return <Badge variant="secondary" className="bg-red-100 text-red-800">Rejected</Badge>;
            default:
                return <Badge variant="outline">{st}</Badge>;
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`GRN ${grn.grn_number}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div className="flex items-center gap-3">
                            <PageHeader
                                title={`GRN: ${grn.grn_number}`}
                                description={`Received at ${grn.store?.name} on ${grn.received_date}`}
                            />
                            {getStatusBadge(grn.status)}
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Link href="/stores/grn">
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="mr-2 h-4 w-4" /> Back to GRNs
                            </Button>
                        </Link>

                        {/* Action Buttons */}
                        {grn.status === 'inspection_pending' && can('stores-grn.inspect') && (
                            <Button size="sm" onClick={() => setIsInspectModalOpen(true)}>
                                <ShieldCheck className="mr-2 h-4 w-4" /> Perform Quality Inspection
                            </Button>
                        )}

                        {(grn.status === 'accepted' || grn.status === 'partially_accepted') && (
                            <>
                                {can('stores-grn.inspect') && (
                                    <Button size="sm" variant="outline" onClick={() => setIsInspectModalOpen(true)}>
                                        Re-inspect
                                    </Button>
                                )}
                                {can('stores-grn.post') && (
                                    <Button
                                        size="sm"
                                        onClick={handlePostToStock}
                                        disabled={postProcessing}
                                        className="bg-emerald-600 hover:bg-emerald-700"
                                    >
                                        <PackageCheck className="mr-2 h-4 w-4" /> Post Stock to Ledger
                                    </Button>
                                )}
                            </>
                        )}
                    </div>
                </div>

                {/* Status Notice if posted */}
                {grn.status === 'posted' && (
                    <Card className="border-green-200 bg-green-50/50">
                        <CardContent className="p-4 flex items-center gap-3">
                            <CheckCircle2 className="h-5 w-5 text-green-600" />
                            <div>
                                <h4 className="font-semibold text-green-900 text-sm">Stock Ledger Updated Atomically</h4>
                                <p className="text-xs text-green-800">
                                    Posted to store inventory batches by {grn.posted_by?.name || 'Store Officer'} on {grn.posted_at?.split('T')[0]}. Items are now live and dispensable.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Details Cards */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground uppercase">Supplier & Order</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm space-y-1">
                            <p className="font-semibold">{grn.vendor?.name || 'Direct Receipt'}</p>
                            {grn.purchase_order && (
                                <p className="text-muted-foreground">
                                    PO Ref: <Link href={`/procurement/orders/${grn.purchase_order.id}`} className="text-blue-600 hover:underline">{grn.purchase_order.po_number}</Link>
                                </p>
                            )}
                            <p className="text-muted-foreground">Delivery Note: {grn.delivery_note_number || 'N/A'}</p>
                            <p className="text-muted-foreground">Invoice Ref: {grn.invoice_reference || 'N/A'}</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground uppercase">Receiving Facility</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm space-y-1">
                            <p className="font-semibold">{grn.store?.name} ({grn.store?.type})</p>
                            <p className="text-muted-foreground">Received Date: {grn.received_date}</p>
                            <p className="text-muted-foreground">Receiving Officer: {grn.received_by?.name || 'Staff'}</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground uppercase">Quality Inspection Audit</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm space-y-1">
                            <p><span className="text-muted-foreground">Inspector:</span> {grn.inspected_by?.name || 'Pending Inspection'}</p>
                            {grn.inspected_date && <p><span className="text-muted-foreground">Inspected On:</span> {grn.inspected_date}</p>}
                            {grn.inspection_notes && (
                                <p className="text-xs text-muted-foreground mt-2 italic bg-muted p-2 rounded">
                                    "{grn.inspection_notes}"
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Items Table */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base font-semibold">Physical Inspection & Batch Details ({grn.items.length})</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3">Item Description</th>
                                        <th className="px-4 py-3">Qty Arrived</th>
                                        <th className="px-4 py-3">Qty Accepted</th>
                                        <th className="px-4 py-3">Qty Rejected</th>
                                        <th className="px-4 py-3">Batch Number</th>
                                        <th className="px-4 py-3">Expiry Date</th>
                                        <th className="px-4 py-3">Store Bin</th>
                                        <th className="px-4 py-3 text-right">Value (TZS)</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {grn.items.map((it) => (
                                        <tr key={it.id}>
                                            <td className="px-4 py-3 font-medium">
                                                {it.procurement_item ? `${it.procurement_item.name} (${it.procurement_item.code})` : it.item_description}
                                            </td>
                                            <td className="px-4 py-3">{Number(it.quantity_received)} {it.unit_of_measure}</td>
                                            <td className="px-4 py-3 font-semibold text-green-700">
                                                {Number(it.quantity_accepted)}
                                            </td>
                                            <td className="px-4 py-3 font-semibold text-red-600">
                                                {Number(it.quantity_rejected) > 0 ? (
                                                    <span>{it.quantity_rejected} {it.rejection_reason ? `(${it.rejection_reason})` : ''}</span>
                                                ) : (
                                                    '0'
                                                )}
                                            </td>
                                            <td className="px-4 py-3 font-mono text-xs">{it.batch_number || '—'}</td>
                                            <td className="px-4 py-3 text-muted-foreground">{it.expiry_date?.split('T')[0] || '—'}</td>
                                            <td className="px-4 py-3 text-muted-foreground">{it.storage_location || '—'}</td>
                                            <td className="px-4 py-3 text-right font-semibold">
                                                TZS {Number(it.total_cost).toLocaleString()}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="bg-muted/40 font-semibold text-primary">
                                        <td colSpan={7} className="px-4 py-3 text-right text-base">Total Received Value:</td>
                                        <td className="px-4 py-3 text-right text-base">TZS {Number(grn.total_amount).toLocaleString()}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {/* Inspection Dialog */}
                <Dialog open={isInspectModalOpen} onOpenChange={setIsInspectModalOpen}>
                    <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
                        <form onSubmit={handleInspectSubmit}>
                            <DialogHeader>
                                <DialogTitle>Quality Control Physical Inspection</DialogTitle>
                                <DialogDescription>
                                    Verify physical package seals, record batch numbers, expiry dates, and separate accepted stock from damaged/rejected items.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="py-4 space-y-4">
                                <div className="space-y-1">
                                    <Label htmlFor="insp_notes">Inspection Summary & Quality Remarks</Label>
                                    <Textarea
                                        id="insp_notes"
                                        placeholder="Physical packages intact, batch certificates verified, temperature chain unbroken..."
                                        value={inspectData.inspection_notes}
                                        onChange={(e) => setInspectData('inspection_notes', e.target.value)}
                                        rows={2}
                                    />
                                </div>

                                <div className="space-y-3">
                                    <h4 className="font-semibold text-sm">Line-by-Line Inspection</h4>
                                    {grn.items.map((item, idx) => (
                                        <div key={item.id} className="p-3 border rounded-lg bg-muted/20 space-y-2">
                                            <div className="font-medium text-sm flex items-center justify-between">
                                                <span>{item.item_description}</span>
                                                <span className="text-xs text-muted-foreground">Arrived: {item.quantity_received} {item.unit_of_measure}</span>
                                            </div>

                                            <div className="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">
                                                <div>
                                                    <Label className="text-xs">Qty Accepted</Label>
                                                    <Input
                                                        type="number"
                                                        min="0"
                                                        max={item.quantity_received}
                                                        value={inspectData.items[idx]?.quantity_accepted}
                                                        onChange={(e) => updateInspectItem(idx, 'quantity_accepted', e.target.value)}
                                                        className="h-8"
                                                        required
                                                    />
                                                </div>
                                                <div>
                                                    <Label className="text-xs">Qty Rejected</Label>
                                                    <Input
                                                        type="number"
                                                        min="0"
                                                        value={inspectData.items[idx]?.quantity_rejected}
                                                        onChange={(e) => updateInspectItem(idx, 'quantity_rejected', e.target.value)}
                                                        className="h-8"
                                                    />
                                                </div>
                                                <div>
                                                    <Label className="text-xs">Batch Number</Label>
                                                    <Input
                                                        placeholder="Lot #..."
                                                        value={inspectData.items[idx]?.batch_number}
                                                        onChange={(e) => updateInspectItem(idx, 'batch_number', e.target.value)}
                                                        className="h-8 font-mono"
                                                    />
                                                </div>
                                                <div>
                                                    <Label className="text-xs">Expiry Date</Label>
                                                    <Input
                                                        type="date"
                                                        value={inspectData.items[idx]?.expiry_date}
                                                        onChange={(e) => updateInspectItem(idx, 'expiry_date', e.target.value)}
                                                        className="h-8"
                                                    />
                                                </div>
                                            </div>

                                            {Number(inspectData.items[idx]?.quantity_rejected) > 0 && (
                                                <div>
                                                    <Label className="text-xs text-red-600">Rejection Reason</Label>
                                                    <Input
                                                        placeholder="e.g. Broken ampoules, broken seal, expired upon arrival..."
                                                        value={inspectData.items[idx]?.rejection_reason}
                                                        onChange={(e) => updateInspectItem(idx, 'rejection_reason', e.target.value)}
                                                        className="h-8 text-xs border-red-200"
                                                    />
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            </div>

                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setIsInspectModalOpen(false)}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={inspectProcessing} className="bg-primary">
                                    {inspectProcessing ? 'Saving Inspection...' : 'Confirm Inspection Result'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
