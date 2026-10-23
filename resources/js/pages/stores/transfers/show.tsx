import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { usePermissions } from '@/hooks/use-permissions';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, ArrowRightLeft, CheckCircle2, Send, Truck } from 'lucide-react';

interface TransferItem {
    id: number;
    procurement_item_id: number;
    quantity?: number;
    quantity_transferred?: number;
    item_name?: string;
    batch_number?: string;
    expiry_date?: string | null;
    notes?: string | null;
    remarks?: string | null;
    procurement_item?: { code: string; name: string; unit_of_measure: string };
    from_stock_batch?: { batch_number: string; expiry_date: string };
    to_stock_batch?: { batch_number: string };
}

interface StoreTransfer {
    id: number;
    transfer_number: string;
    from_store: { id: number; name: string };
    to_store: { id: number; name: string };
    transfer_date: string;
    dispatched_at: string | null;
    received_at: string | null;
    requested_by?: { name: string };
    approved_by?: { name: string };
    received_by?: { name: string };
    status: 'draft' | 'submitted' | 'approved' | 'in_transit' | 'received' | 'cancelled';
    reason: string | null;
    notes: string | null;
    items: TransferItem[];
    approval_histories?: any[];
}

interface Props {
    transfer: StoreTransfer;
}

export default function ShowStoreTransfer({ transfer }: Props) {
    const { can } = usePermissions();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Store & Inventory', href: '/stores' },
        { title: 'Inter-Store Transfers', href: '/stores/transfers' },
        { title: transfer.transfer_number, href: `/stores/transfers/${transfer.id}` },
    ];

    const { post, processing } = useForm({});

    const handleSubmit = () => {
        post(`/stores/transfers/${transfer.id}/submit`);
    };

    const handleDispatch = () => {
        post(`/stores/transfers/${transfer.id}/dispatch`);
    };

    const handleReceive = () => {
        post(`/stores/transfers/${transfer.id}/receive`);
    };

    const getStatusBadge = (st: string) => {
        switch (st) {
            case 'draft':
                return <Badge variant="outline">Draft</Badge>;
            case 'submitted':
                return <Badge variant="secondary" className="bg-yellow-100 text-yellow-800">Submitted</Badge>;
            case 'approved':
                return <Badge variant="secondary" className="bg-blue-100 text-blue-800">Approved</Badge>;
            case 'in_transit':
                return <Badge variant="secondary" className="bg-indigo-100 text-indigo-800 flex items-center gap-1"><Truck className="h-3 w-3" /> In Transit</Badge>;
            case 'received':
                return <Badge variant="secondary" className="bg-green-100 text-green-800 font-semibold flex items-center gap-1"><CheckCircle2 className="h-3 w-3" /> Completed</Badge>;
            case 'cancelled':
                return <Badge variant="secondary" className="bg-red-100 text-red-800">Cancelled</Badge>;
            default:
                return <Badge variant="outline">{st}</Badge>;
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Transfer ${transfer.transfer_number}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div className="flex items-center gap-3">
                            <PageHeader
                                title={`Transfer: ${transfer.transfer_number}`}
                                description={`${transfer.from_store?.name} \u2192 ${transfer.to_store?.name}`}
                            />
                            {getStatusBadge(transfer.status)}
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Link href="/stores/transfers">
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="mr-2 h-4 w-4" /> Back to Transfers
                            </Button>
                        </Link>

                        {transfer.status === 'draft' && can('stores-transfers.submit') && (
                            <Button size="sm" onClick={handleSubmit} disabled={processing}>
                                <Send className="mr-1 h-4 w-4" /> Submit for Approval
                            </Button>
                        )}

                        {(transfer.status === 'submitted' || transfer.status === 'approved') && can('stores-transfers.dispatch') && (
                            <Button size="sm" onClick={handleDispatch} disabled={processing} className="bg-indigo-600 hover:bg-indigo-700">
                                <Truck className="mr-1 h-4 w-4" /> Dispatch (Mark In Transit)
                            </Button>
                        )}

                        {transfer.status === 'in_transit' && can('stores-transfers.receive') && (
                            <Button size="sm" onClick={handleReceive} disabled={processing} className="bg-emerald-600 hover:bg-emerald-700">
                                <CheckCircle2 className="mr-1 h-4 w-4" /> Confirm Arrival & Receive Stock
                            </Button>
                        )}
                    </div>
                </div>

                {/* Details Cards */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground uppercase">Source Location</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm space-y-1">
                            <p className="font-semibold">{transfer.from_store?.name}</p>
                            <p className="text-muted-foreground">Transfer Date: {transfer.transfer_date}</p>
                            <p className="text-muted-foreground">Initiated By: {transfer.requested_by?.name || 'Staff'}</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground uppercase">Destination Location</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm space-y-1">
                            <p className="font-semibold text-blue-700">{transfer.to_store?.name}</p>
                            <p className="text-muted-foreground">
                                Dispatched: {transfer.dispatched_at ? transfer.dispatched_at.split('T')[0] : 'Pending Dispatch'}
                            </p>
                            <p className="text-muted-foreground">
                                Received: {transfer.received_by ? `${transfer.received_by.name} (${transfer.received_at?.split('T')[0]})` : 'Awaiting Arrival'}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground uppercase">Justification</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm">
                            {transfer.notes || transfer.reason ? (
                                <p className="text-muted-foreground italic bg-muted p-2 rounded">
                                    "{transfer.notes || transfer.reason}"
                                </p>
                            ) : (
                                <p className="text-muted-foreground">Standard stock rebalancing</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Items Table */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base font-semibold">Transferred Stock Batches ({transfer.items.length})</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3">Item Description</th>
                                        <th className="px-4 py-3">Source Batch #</th>
                                        <th className="px-4 py-3">Expiry Date</th>
                                        <th className="px-4 py-3">Qty Moved</th>
                                        <th className="px-4 py-3">UOM</th>
                                        <th className="px-4 py-3">Notes</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {transfer.items.map((it) => {
                                        const qty = it.quantity_transferred ?? it.quantity ?? 0;
                                        const itemName = it.item_name || (it.procurement_item ? `${it.procurement_item.name} (${it.procurement_item.code})` : 'Stock Item');
                                        const batchNum = it.batch_number || it.from_stock_batch?.batch_number || '—';
                                        const expiry = it.expiry_date || it.from_stock_batch?.expiry_date;
                                        const noteText = it.remarks || it.notes || '—';
                                        const uom = it.procurement_item?.unit_of_measure || 'Units';

                                        return (
                                            <tr key={it.id}>
                                                <td className="px-4 py-3 font-medium">{itemName}</td>
                                                <td className="px-4 py-3 font-mono text-xs text-blue-700">{batchNum}</td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {expiry ? String(expiry).split('T')[0] : '—'}
                                                </td>
                                                <td className="px-4 py-3 font-bold">{qty}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{uom}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{noteText}</td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
