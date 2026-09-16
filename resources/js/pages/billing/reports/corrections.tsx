import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/billing-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, router } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing', href: '/billing' },
    { title: 'Reports', href: '/billing/reports' },
    { title: 'Corrections', href: '/billing/reports/corrections' },
];

interface RefundRow {
    id: number;
    refund_number: string;
    amount: number;
    currency: string;
    refund_method: string;
    reason: string;
    processed_at: string | null;
    patient: { id: number; name: string } | null;
    processed_by: { id: number; name: string } | null;
}

interface ReversalRow {
    id: number;
    reversal_number: string;
    amount: number;
    currency: string;
    reason: string;
    reversed_at: string | null;
    patient: { id: number; name: string } | null;
    reversed_by: { id: number; name: string } | null;
}

interface AdjustmentRow {
    id: number;
    adjustment_number: string;
    type: string;
    direction: string;
    amount: number;
    currency: string;
    reason: string;
    adjusted_at: string | null;
    patient: { id: number; name: string } | null;
    adjusted_by: { id: number; name: string } | null;
}

interface Props {
    refunds: Paginated<RefundRow>;
    reversals: Paginated<ReversalRow>;
    adjustments: Paginated<AdjustmentRow>;
    totals: { refunds: number; reversals: number; creditAdjustments: number; debitAdjustments: number };
    filters: { date_from?: string; date_to?: string };
}

const CURRENCY = 'TZS';

export default function CorrectionsReport({ refunds, reversals, adjustments, totals, filters }: Props) {
    function applyFilters(overrides: Record<string, string>) {
        router.get(route('billing.reports.corrections'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Corrections Report" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Corrections Report</h1>
                    <p className="text-muted-foreground text-sm">Refunds, reversals, and billing adjustments.</p>
                </div>

                <div className="flex flex-wrap gap-3">
                    <Input
                        type="date"
                        value={filters.date_from ?? ''}
                        onChange={(e) => applyFilters({ date_from: e.target.value })}
                        className="w-40"
                        title="Date from"
                    />
                    <Input
                        type="date"
                        value={filters.date_to ?? ''}
                        onChange={(e) => applyFilters({ date_to: e.target.value })}
                        className="w-40"
                        title="Date to"
                    />
                </div>

                <div className="grid gap-4 sm:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Total Refunds</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-xl font-semibold">{formatMoney(totals.refunds, CURRENCY)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Total Reversals</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-xl font-semibold">{formatMoney(totals.reversals, CURRENCY)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Credit Adjustments</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-xl font-semibold text-green-600">{formatMoney(totals.creditAdjustments, CURRENCY)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Debit Adjustments</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-xl font-semibold text-destructive">{formatMoney(totals.debitAdjustments, CURRENCY)}</p>
                        </CardContent>
                    </Card>
                </div>

                <Tabs defaultValue="refunds">
                    <TabsList>
                        <TabsTrigger value="refunds">Refunds ({refunds.total})</TabsTrigger>
                        <TabsTrigger value="reversals">Reversals ({reversals.total})</TabsTrigger>
                        <TabsTrigger value="adjustments">Adjustments ({adjustments.total})</TabsTrigger>
                    </TabsList>

                    <TabsContent value="refunds">
                        <div className="rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Refund #</TableHead>
                                        <TableHead>Patient</TableHead>
                                        <TableHead>Method</TableHead>
                                        <TableHead>Reason</TableHead>
                                        <TableHead>Processed By</TableHead>
                                        <TableHead>Date</TableHead>
                                        <TableHead className="text-right">Amount</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {refunds.data.map((r) => (
                                        <TableRow key={r.id}>
                                            <TableCell className="font-mono text-sm">{r.refund_number}</TableCell>
                                            <TableCell>{r.patient?.name ?? '—'}</TableCell>
                                            <TableCell className="capitalize">{r.refund_method.replace('_', ' ')}</TableCell>
                                            <TableCell className="max-w-xs truncate">{r.reason}</TableCell>
                                            <TableCell>{r.processed_by?.name ?? '—'}</TableCell>
                                            <TableCell>{r.processed_at ? new Date(r.processed_at).toLocaleDateString() : '—'}</TableCell>
                                            <TableCell className="text-right">{formatMoney(r.amount, r.currency)}</TableCell>
                                        </TableRow>
                                    ))}
                                    {refunds.data.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={7} className="text-muted-foreground py-8 text-center text-sm">
                                                No refunds found.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </TabsContent>

                    <TabsContent value="reversals">
                        <div className="rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Reversal #</TableHead>
                                        <TableHead>Patient</TableHead>
                                        <TableHead>Reason</TableHead>
                                        <TableHead>Reversed By</TableHead>
                                        <TableHead>Date</TableHead>
                                        <TableHead className="text-right">Amount</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {reversals.data.map((r) => (
                                        <TableRow key={r.id}>
                                            <TableCell className="font-mono text-sm">{r.reversal_number}</TableCell>
                                            <TableCell>{r.patient?.name ?? '—'}</TableCell>
                                            <TableCell className="max-w-xs truncate">{r.reason}</TableCell>
                                            <TableCell>{r.reversed_by?.name ?? '—'}</TableCell>
                                            <TableCell>{r.reversed_at ? new Date(r.reversed_at).toLocaleDateString() : '—'}</TableCell>
                                            <TableCell className="text-right">{formatMoney(r.amount, r.currency)}</TableCell>
                                        </TableRow>
                                    ))}
                                    {reversals.data.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={6} className="text-muted-foreground py-8 text-center text-sm">
                                                No reversals found.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </TabsContent>

                    <TabsContent value="adjustments">
                        <div className="rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Adjustment #</TableHead>
                                        <TableHead>Patient</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Direction</TableHead>
                                        <TableHead>Reason</TableHead>
                                        <TableHead>Adjusted By</TableHead>
                                        <TableHead>Date</TableHead>
                                        <TableHead className="text-right">Amount</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {adjustments.data.map((a) => (
                                        <TableRow key={a.id}>
                                            <TableCell className="font-mono text-sm">{a.adjustment_number}</TableCell>
                                            <TableCell>{a.patient?.name ?? '—'}</TableCell>
                                            <TableCell className="capitalize">{a.type.replace('_', ' ')}</TableCell>
                                            <TableCell>
                                                <Badge variant={a.direction === 'credit' ? 'default' : 'destructive'}>
                                                    {a.direction}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="max-w-xs truncate">{a.reason}</TableCell>
                                            <TableCell>{a.adjusted_by?.name ?? '—'}</TableCell>
                                            <TableCell>{a.adjusted_at ? new Date(a.adjusted_at).toLocaleDateString() : '—'}</TableCell>
                                            <TableCell className="text-right">{formatMoney(a.amount, a.currency)}</TableCell>
                                        </TableRow>
                                    ))}
                                    {adjustments.data.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={8} className="text-muted-foreground py-8 text-center text-sm">
                                                No adjustments found.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}
