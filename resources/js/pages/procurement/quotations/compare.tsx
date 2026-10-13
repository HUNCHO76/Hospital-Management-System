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
import { Head, Link, router, useForm } from '@inertiajs/react';
import { CheckCircle2, ChevronRight, Eye, GitCompare, Trophy } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Procurement', href: '/procurement' },
    { title: 'Vendor Quotations', href: '/procurement/quotations' },
    { title: 'Comparison Matrix', href: '/procurement/quotations/compare' },
];

interface IndentOption {
    id: number;
    indent_number: string;
    status: string;
}

interface VendorQuote {
    id: number;
    quotation_number: string;
    vendor_id: number;
    vendor?: { id: number; name: string };
    total_amount: number;
    lead_time_days?: number;
    payment_terms?: string;
    status: string;
}

interface ItemComparison {
    procurement_item_id: number;
    item_name: string;
    lowest_unit_price: number | null;
    best_quotation_id: number | null;
    quotes: Record<
        number,
        {
            vendor_id: number;
            vendor_name: string;
            unit_price: number;
            total_price: number;
            quantity: number;
        }
    >;
}

interface Props {
    indents: IndentOption[];
    selected_indent_id: number | null;
    selected_indent: any;
    comparison: {
        indent_id: number;
        quotations: VendorQuote[];
        items_comparison: ItemComparison[];
    } | null;
}

export default function CompareQuotations({ indents, selected_indent_id, selected_indent, comparison }: Props) {
    const { can } = usePermissions();
    const [selectedIndentId, setSelectedIndentId] = useState<string>(selected_indent_id ? String(selected_indent_id) : '');
    const [awardModalQuoteId, setAwardModalQuoteId] = useState<number | null>(null);

    const { data: awardData, setData: setAwardData, post: postAward, processing: awardProcessing } = useForm({
        justification: '',
    });

    const handleIndentSelect = (id: string) => {
        setSelectedIndentId(id);
        router.get('/procurement/quotations/compare', { indent_id: id || undefined }, { preserveState: true });
    };

    const handleAwardSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!awardModalQuoteId) return;
        postAward(`/procurement/quotations/${awardModalQuoteId}/select`, {
            onSuccess: () => setAwardModalQuoteId(null),
        });
    };

    const quotations = comparison?.quotations || [];
    const itemsComparison = comparison?.items_comparison || [];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Quotation Comparison Matrix - Procurement" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <PageHeader
                        title="Quotation Comparison Matrix"
                        description="Side-by-side vendor evaluation matrix showing pricing, lead times, and item-by-item lowest cost analysis."
                    />
                    <Link href="/procurement/quotations">
                        <Button variant="outline" size="sm">
                            Back to Quotations
                        </Button>
                    </Link>
                </div>

                {/* Indent Selector Card */}
                <Card>
                    <CardContent className="p-4 flex flex-col md:flex-row items-center gap-4">
                        <div className="font-medium text-sm">Select Requisition Indent to Compare:</div>
                        <select
                            value={selectedIndentId}
                            onChange={(e) => handleIndentSelect(e.target.value)}
                            className="flex-1 rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option value="">-- Choose an Indent to Evaluate --</option>
                            {indents.map((ind) => (
                                <option key={ind.id} value={ind.id}>
                                    {ind.indent_number} ({ind.status})
                                </option>
                            ))}
                        </select>
                    </CardContent>
                </Card>

                {!selectedIndentId ? (
                    <Card className="p-12 text-center text-muted-foreground">
                        <GitCompare className="mx-auto h-12 w-12 text-muted-foreground/40 mb-3" />
                        <h3 className="text-lg font-medium">No Indent Selected</h3>
                        <p className="text-sm">Please select a requisition indent above to compare multi-vendor bids.</p>
                    </Card>
                ) : quotations.length === 0 ? (
                    <Card className="p-12 text-center text-muted-foreground">
                        <h3 className="text-lg font-medium">No Quotations Found</h3>
                        <p className="text-sm mt-1">There are no vendor quotations linked to this indent yet.</p>
                        {can('procurement-quotations.create') && (
                            <div className="mt-4">
                                <Link href={`/procurement/quotations/create?indent_id=${selectedIndentId}`}>
                                    <Button size="sm">Record Quotation for this Indent</Button>
                                </Link>
                            </div>
                        )}
                    </Card>
                ) : (
                    <>
                        {/* Vendor Proposal Summary Cards */}
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            {quotations.map((q) => {
                                const isSelected = q.status === 'selected';
                                return (
                                    <Card key={q.id} className={isSelected ? 'border-green-500 bg-green-50/20' : ''}>
                                        <CardHeader className="pb-2 flex flex-row items-center justify-between">
                                            <CardTitle className="text-base font-bold">{q.vendor?.name}</CardTitle>
                                            {isSelected && (
                                                <Badge className="bg-green-600 text-white flex items-center gap-1">
                                                    <Trophy className="h-3 w-3" /> Winner
                                                </Badge>
                                            )}
                                        </CardHeader>
                                        <CardContent className="space-y-2 text-sm">
                                            <div className="text-2xl font-bold text-primary">
                                                TZS {Number(q.total_amount).toLocaleString()}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                Quote Ref: <span className="font-semibold text-foreground">{q.quotation_number}</span>
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                Lead Time: <span className="font-semibold text-foreground">{q.lead_time_days ? `${q.lead_time_days} Days` : 'N/A'}</span>
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                Payment: <span className="font-semibold text-foreground">{q.payment_terms || 'Standard'}</span>
                                            </div>

                                            <div className="pt-2 flex items-center gap-2">
                                                <Link href={`/procurement/quotations/${q.id}`} className="flex-1">
                                                    <Button variant="outline" size="sm" className="w-full text-xs">
                                                        <Eye className="mr-1 h-3.5 w-3.5" /> View Quote
                                                    </Button>
                                                </Link>
                                                {!isSelected && can('procurement-quotations.select') && (
                                                    <Button
                                                        size="sm"
                                                        className="flex-1 text-xs"
                                                        onClick={() => setAwardModalQuoteId(q.id)}
                                                    >
                                                        <CheckCircle2 className="mr-1 h-3.5 w-3.5" /> Award Bid
                                                    </Button>
                                                )}
                                            </div>
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>

                        {/* Side-by-Side Item Matrix */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Per-Item Price Comparison Matrix</CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-sm">
                                        <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                            <tr>
                                                <th className="px-4 py-3">Item Name</th>
                                                <th className="px-4 py-3">Qty</th>
                                                {quotations.map((q) => (
                                                    <th key={q.id} className="px-4 py-3 text-right">
                                                        {q.vendor?.name} (Unit / Total)
                                                    </th>
                                                ))}
                                                <th className="px-4 py-3 text-right">Lowest Price</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {itemsComparison.map((row, idx) => {
                                                const firstQuote = Object.values(row.quotes)[0];
                                                const qty = firstQuote ? firstQuote.quantity : 1;

                                                return (
                                                    <tr key={idx} className="hover:bg-muted/40">
                                                        <td className="px-4 py-3 font-medium">{row.item_name}</td>
                                                        <td className="px-4 py-3 text-muted-foreground">{qty}</td>
                                                        {quotations.map((q) => {
                                                            const quoteItem = row.quotes[q.id];
                                                            const isLowest = quoteItem && row.lowest_unit_price === quoteItem.unit_price;

                                                            return (
                                                                <td
                                                                    key={q.id}
                                                                    className={`px-4 py-3 text-right ${
                                                                        isLowest ? 'bg-green-50 font-semibold text-green-800' : ''
                                                                    }`}
                                                                >
                                                                    {quoteItem ? (
                                                                        <div>
                                                                            <div>TZS {quoteItem.unit_price.toLocaleString()}</div>
                                                                            <div className="text-xs text-muted-foreground">
                                                                                = TZS {quoteItem.total_price.toLocaleString()}
                                                                            </div>
                                                                        </div>
                                                                    ) : (
                                                                        <span className="text-muted-foreground">—</span>
                                                                    )}
                                                                </td>
                                                            );
                                                        })}
                                                        <td className="px-4 py-3 text-right font-bold text-green-700">
                                                            {row.lowest_unit_price !== null
                                                                ? `TZS ${row.lowest_unit_price.toLocaleString()}`
                                                                : '—'}
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                            </CardContent>
                        </Card>
                    </>
                )}

                {/* Award Modal */}
                <Dialog open={!!awardModalQuoteId} onOpenChange={() => setAwardModalQuoteId(null)}>
                    <DialogContent>
                        <form onSubmit={handleAwardSubmit}>
                            <DialogHeader>
                                <DialogTitle>Award Winning Bid</DialogTitle>
                                <DialogDescription>
                                    Confirm selection of this vendor quotation as the winning contract award for purchase order creation.
                                </DialogDescription>
                            </DialogHeader>
                            <div className="py-4 space-y-2">
                                <Label htmlFor="award_justification">Selection Justification / Procurement Committee Decision *</Label>
                                <Textarea
                                    id="award_justification"
                                    required
                                    value={awardData.justification}
                                    onChange={(e) => setAwardData('justification', e.target.value)}
                                    placeholder="State why this proposal was selected (e.g. lowest evaluated price, delivery terms)..."
                                    rows={3}
                                />
                            </div>
                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setAwardModalQuoteId(null)}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={awardProcessing || !awardData.justification}>
                                    {awardProcessing ? 'Awarding...' : 'Confirm Award'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
