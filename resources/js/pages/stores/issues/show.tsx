import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { usePermissions } from '@/hooks/use-permissions';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Clock, Send, ShieldCheck } from 'lucide-react';

interface IssueItem {
    id: number;
    procurement_item_id: number;
    quantity_issued: number;
    unit_of_measure: string;
    unit_cost: number;
    total_cost: number;
    procurement_item?: { code: string; name: string };
    stock_batch?: { id: number; batch_number: string; expiry_date: string };
}

interface StoreIssue {
    id: number;
    issue_number: string;
    from_store: { id: number; name: string };
    department: { id: number; name: string };
    request?: { id: number; request_number: string } | null;
    issue_date: string;
    received_at: string | null;
    issued_by?: { name: string };
    received_by?: { name: string };
    status: 'draft' | 'issued' | 'received' | 'cancelled';
    total_cost: number;
    notes: string | null;
    items: IssueItem[];
    approval_histories?: any[];
}

interface Props {
    issue: StoreIssue;
}

export default function ShowStoreIssue({ issue }: Props) {
    const { can } = usePermissions();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Store & Inventory', href: '/stores' },
        { title: 'Stock Issues', href: '/stores/issues' },
        { title: issue.issue_number, href: `/stores/issues/${issue.id}` },
    ];

    const { post: postReceive, processing: receiveProcessing } = useForm({});

    const handleConfirmReceipt = () => {
        postReceive(`/stores/issues/${issue.id}/receive`);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Issue ${issue.issue_number}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div className="flex items-center gap-3">
                            <PageHeader
                                title={`Stock Issue: ${issue.issue_number}`}
                                description={`Issued from ${issue.from_store?.name} to ${issue.department?.name}`}
                            />
                            <Badge
                                variant={issue.status === 'received' ? 'default' : 'secondary'}
                                className={issue.status === 'received' ? 'bg-green-600 text-white' : 'bg-blue-100 text-blue-800'}
                            >
                                {issue.status === 'received' ? 'Confirmed Received' : 'Issued (In Transit)'}
                            </Badge>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Link href="/stores/issues">
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="mr-2 h-4 w-4" /> Back to Issues
                            </Button>
                        </Link>
                        {issue.status === 'issued' && can('stores-issues.receive') && (
                            <Button size="sm" onClick={handleConfirmReceipt} disabled={receiveProcessing} className="bg-emerald-600 hover:bg-emerald-700">
                                <CheckCircle2 className="mr-2 h-4 w-4" /> Confirm Department Receipt
                            </Button>
                        )}
                    </div>
                </div>

                {/* Details Cards */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground uppercase">Issuing Facility</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm space-y-1">
                            <p className="font-semibold">{issue.from_store?.name}</p>
                            <p className="text-muted-foreground">Issued By: {issue.issued_by?.name || 'Store Officer'}</p>
                            <p className="text-muted-foreground">Issue Date: {issue.issue_date}</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground uppercase">Receiving Unit</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm space-y-1">
                            <p className="font-semibold">{issue.department?.name}</p>
                            {issue.request && (
                                <p className="text-muted-foreground">
                                    Requisition Ref: <Link href={`/stores/requests/${issue.request.id}`} className="text-blue-600 hover:underline">{issue.request.request_number}</Link>
                                </p>
                            )}
                            <p className="text-muted-foreground">
                                Receipt Status: {issue.received_by ? `Confirmed by ${issue.received_by.name} on ${issue.received_at?.split('T')[0]}` : 'Pending Ward Confirmation'}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground uppercase">Inventory Valuation</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm space-y-1">
                            <p className="text-2xl font-bold text-primary">
                                TZS {Number(issue.total_cost).toLocaleString()}
                            </p>
                            <p className="text-xs text-muted-foreground">Cost allocated against department consumption</p>
                        </CardContent>
                    </Card>
                </div>

                {/* Line Items Table */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base font-semibold">Allocated FEFO Stock Items ({issue.items.length})</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3">Item Description</th>
                                        <th className="px-4 py-3">Qty Issued</th>
                                        <th className="px-4 py-3">UOM</th>
                                        <th className="px-4 py-3">Allocated Batch</th>
                                        <th className="px-4 py-3">Batch Expiry</th>
                                        <th className="px-4 py-3">Unit Cost (TZS)</th>
                                        <th className="px-4 py-3 text-right">Total Cost (TZS)</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {issue.items.map((it) => (
                                        <tr key={it.id}>
                                            <td className="px-4 py-3 font-medium">
                                                {it.procurement_item ? `${it.procurement_item.name} (${it.procurement_item.code})` : 'Item'}
                                            </td>
                                            <td className="px-4 py-3 font-semibold">{Number(it.quantity_issued)}</td>
                                            <td className="px-4 py-3 text-muted-foreground">{it.unit_of_measure}</td>
                                            <td className="px-4 py-3 font-mono text-xs text-blue-700">
                                                {it.stock_batch?.batch_number || 'General Batch'}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {it.stock_batch?.expiry_date ? it.stock_batch.expiry_date.split('T')[0] : '—'}
                                            </td>
                                            <td className="px-4 py-3">TZS {Number(it.unit_cost).toLocaleString()}</td>
                                            <td className="px-4 py-3 text-right font-semibold">
                                                TZS {Number(it.total_cost).toLocaleString()}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="bg-muted/40 font-semibold text-primary">
                                        <td colSpan={6} className="px-4 py-3 text-right text-base">Total Issue Value:</td>
                                        <td className="px-4 py-3 text-right text-base">TZS {Number(issue.total_cost).toLocaleString()}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
