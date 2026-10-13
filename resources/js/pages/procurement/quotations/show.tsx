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
import { ArrowLeft, CheckCircle2, Clock, FileCheck, ShoppingCart } from 'lucide-react';
import { useState } from 'react';

interface QuotationItem {
    id: number;
    procurement_item_id: number;
    item_description: string;
    quantity: number;
    unit_of_measure: string;
    unit_price: number;
    discount_percent: number;
    tax_percent: number;
    total_price: number;
    delivery_days: number;
    warranty_period: string | null;
    remarks: string | null;
    procurement_item?: { code: string; name: string };
}

interface Quotation {
    id: number;
    quotation_number: string;
    vendor: { id: number; name: string; contact_person: string; phone: string; email: string };
    indent?: { id: number; indent_number: string; department?: { name: string } } | null;
    quotation_date: string;
    valid_until: string | null;
    payment_terms: string | null;
    delivery_lead_time_days: number | null;
    subtotal: number;
    tax_amount: number;
    discount_amount: number;
    freight_amount: number;
    total_amount: number;
    status: 'pending' | 'under_review' | 'selected' | 'rejected';
    selected_by?: { name: string } | null;
    selected_at: string | null;
    selection_justification: string | null;
    notes: string | null;
    items: QuotationItem[];
    approval_histories?: any[];
}

interface Props {
    quotation: Quotation;
}

export default function ShowQuotation({ quotation }: Props) {
    const { can } = usePermissions();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Procurement', href: '/procurement' },
        { title: 'Vendor Quotations', href: '/procurement/quotations' },
        { title: quotation.quotation_number, href: `/procurement/quotations/${quotation.id}` },
    ];

    const [isSelectModalOpen, setIsSelectModalOpen] = useState(false);

    const { data: selectData, setData: setSelectData, post: postSelect, processing: selectProcessing } = useForm({
        justification: '',
    });

    const handleSelectSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        postSelect(`/procurement/quotations/${quotation.id}/select`, {
            onSuccess: () => setIsSelectModalOpen(false),
        });
    };

    const getStatusBadge = (st: string) => {
        switch (st) {
            case 'pending':
                return <Badge variant="secondary" className="bg-yellow-100 text-yellow-800">Pending</Badge>;
            case 'under_review':
                return <Badge variant="secondary" className="bg-blue-100 text-blue-800">Under Review</Badge>;
            case 'selected':
                return <Badge variant="secondary" className="bg-green-100 text-green-800 font-semibold">Selected (Winning Bid)</Badge>;
            case 'rejected':
                return <Badge variant="secondary" className="bg-red-100 text-red-800">Rejected</Badge>;
            default:
                return <Badge variant="outline">{st}</Badge>;
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Quotation ${quotation.quotation_number}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <div>
                        <div className="flex items-center gap-3">
                            <PageHeader
                                title={`Quotation: ${quotation.quotation_number}`}
                                description={`Vendor proposal from ${quotation.vendor?.name}`}
                            />
                            {getStatusBadge(quotation.status)}
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Link href="/procurement/quotations">
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="mr-2 h-4 w-4" /> Back to List
                            </Button>
                        </Link>
                        {quotation.indent && can('procurement-quotations.compare') && (
                            <Link href={`/procurement/quotations/compare?indent_id=${quotation.indent.id}`}>
                                <Button variant="outline" size="sm">
                                    Compare Matrix
                                </Button>
                            </Link>
                        )}
                        {quotation.status !== 'selected' && quotation.status !== 'rejected' && can('procurement-quotations.select') && (
                            <Button size="sm" onClick={() => setIsSelectModalOpen(true)}>
                                <CheckCircle2 className="mr-2 h-4 w-4" /> Select as Winning Bid
                            </Button>
                        )}
                        {quotation.status === 'selected' && can('procurement-purchase-orders.create') && (
                            <Link href={`/procurement/orders/create?quotation_id=${quotation.id}`}>
                                <Button size="sm">
                                    <ShoppingCart className="mr-2 h-4 w-4" /> Generate Purchase Order
                                </Button>
                            </Link>
                        )}
                    </div>
                </div>

                {/* Selection Award Banner */}
                {quotation.status === 'selected' && (
                    <Card className="border-green-200 bg-green-50/50">
                        <CardContent className="p-4 flex items-start gap-3">
                            <CheckCircle2 className="h-5 w-5 text-green-600 mt-0.5" />
                            <div>
                                <h4 className="font-semibold text-green-900">Winning Proposal Selected</h4>
                                <p className="text-sm text-green-800 mt-1">
                                    Selected by {quotation.selected_by?.name || 'Authorized Officer'} on {quotation.selected_at?.split('T')[0]}.
                                </p>
                                {quotation.selection_justification && (
                                    <p className="text-xs text-green-700 mt-1 italic">
                                        Justification: "{quotation.selection_justification}"
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Info Cards */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground uppercase">Vendor Information</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm space-y-1">
                            <p className="font-semibold text-base">{quotation.vendor?.name}</p>
                            <p className="text-muted-foreground">Contact: {quotation.vendor?.contact_person || 'N/A'}</p>
                            <p className="text-muted-foreground">Phone: {quotation.vendor?.phone || 'N/A'}</p>
                            <p className="text-muted-foreground">Email: {quotation.vendor?.email || 'N/A'}</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground uppercase">Proposal Terms</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm space-y-1">
                            <p><span className="text-muted-foreground">Quotation Date:</span> {quotation.quotation_date}</p>
                            <p><span className="text-muted-foreground">Valid Until:</span> {quotation.valid_until || 'Not Specified'}</p>
                            <p><span className="text-muted-foreground">Payment Terms:</span> {quotation.payment_terms || 'Standard'}</p>
                            <p><span className="text-muted-foreground">Lead Time:</span> {quotation.delivery_lead_time_days ? `${quotation.delivery_lead_time_days} Days` : 'N/A'}</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground uppercase">Linked Requisition</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm space-y-1">
                            {quotation.indent ? (
                                <>
                                    <p className="font-semibold">
                                        <Link href={`/procurement/indents/${quotation.indent.id}`} className="text-blue-600 hover:underline">
                                            {quotation.indent.indent_number}
                                        </Link>
                                    </p>
                                    <p className="text-muted-foreground">Department: {quotation.indent.department?.name || 'Hospital'}</p>
                                </>
                            ) : (
                                <p className="text-muted-foreground">Direct / Open Quote</p>
                            )}
                            {quotation.notes && (
                                <p className="text-xs text-muted-foreground mt-2 italic bg-muted p-2 rounded">
                                    "{quotation.notes}"
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Line Items Table */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Quoted Line Items ({quotation.items.length})</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3">Item Description</th>
                                        <th className="px-4 py-3">Qty</th>
                                        <th className="px-4 py-3">UOM</th>
                                        <th className="px-4 py-3">Unit Price (TZS)</th>
                                        <th className="px-4 py-3">Disc %</th>
                                        <th className="px-4 py-3">VAT %</th>
                                        <th className="px-4 py-3">Lead Days</th>
                                        <th className="px-4 py-3 text-right">Total Price (TZS)</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {quotation.items.map((it) => (
                                        <tr key={it.id}>
                                            <td className="px-4 py-3 font-medium">
                                                {it.procurement_item ? `${it.procurement_item.name} (${it.procurement_item.code})` : it.item_description}
                                            </td>
                                            <td className="px-4 py-3">{Number(it.quantity)}</td>
                                            <td className="px-4 py-3 text-muted-foreground">{it.unit_of_measure}</td>
                                            <td className="px-4 py-3">TZS {Number(it.unit_price).toLocaleString()}</td>
                                            <td className="px-4 py-3">{Number(it.discount_percent) || 0}%</td>
                                            <td className="px-4 py-3">{Number(it.tax_percent) || 0}%</td>
                                            <td className="px-4 py-3">{it.delivery_days || '—'}</td>
                                            <td className="px-4 py-3 text-right font-semibold">
                                                TZS {Number(it.total_price).toLocaleString()}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="bg-muted/40 font-medium">
                                        <td colSpan={7} className="px-4 py-2 text-right">Subtotal:</td>
                                        <td className="px-4 py-2 text-right">TZS {Number(quotation.subtotal).toLocaleString()}</td>
                                    </tr>
                                    <tr className="bg-muted/40 font-medium">
                                        <td colSpan={7} className="px-4 py-2 text-right">Tax Amount:</td>
                                        <td className="px-4 py-2 text-right">TZS {Number(quotation.tax_amount).toLocaleString()}</td>
                                    </tr>
                                    {Number(quotation.freight_amount) > 0 && (
                                        <tr className="bg-muted/40 font-medium">
                                            <td colSpan={7} className="px-4 py-2 text-right">Freight / Shipping:</td>
                                            <td className="px-4 py-2 text-right">TZS {Number(quotation.freight_amount).toLocaleString()}</td>
                                        </tr>
                                    )}
                                    <tr className="bg-muted/60 font-semibold text-primary">
                                        <td colSpan={7} className="px-4 py-3 text-right text-base">Grand Total:</td>
                                        <td className="px-4 py-3 text-right text-base">TZS {Number(quotation.total_amount).toLocaleString()}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                {/* Select Modal */}
                <Dialog open={isSelectModalOpen} onOpenChange={setIsSelectModalOpen}>
                    <DialogContent>
                        <form onSubmit={handleSelectSubmit}>
                            <DialogHeader>
                                <DialogTitle>Select as Winning Vendor Quotation</DialogTitle>
                                <DialogDescription>
                                    Mark this quotation as the winning proposal for purchase order award.
                                </DialogDescription>
                            </DialogHeader>
                            <div className="py-4 space-y-2">
                                <Label htmlFor="justification">Selection Justification / Procurement Committee Rationale *</Label>
                                <Textarea
                                    id="justification"
                                    required
                                    value={selectData.justification}
                                    onChange={(e) => setSelectData('justification', e.target.value)}
                                    placeholder="Explain why this quote was selected (e.g. lowest compliant price, verified warranty, shortest lead time)..."
                                    rows={3}
                                />
                            </div>
                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setIsSelectModalOpen(false)}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={selectProcessing || !selectData.justification}>
                                    {selectProcessing ? 'Awarding...' : 'Confirm Selection'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
