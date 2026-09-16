import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/billing-status';
import { type BreadcrumbItem } from '@/types';
import { type CashierSession, type CloseSessionFormData, type CollectionSummary } from '@/types/cashier';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface Props {
    session: CashierSession;
    summary: CollectionSummary;
    canClose: boolean;
    paymentMethods: string[];
}

const methodLabel: Record<string, string> = {
    cash: 'Cash',
    card: 'Card',
    bank_transfer: 'Bank Transfer',
    mobile_money: 'Mobile Money',
    other: 'Other',
};

export default function CashierSessionShow({ session, summary, canClose, paymentMethods }: Props) {
    const [closeOpen, setCloseOpen] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Billing', href: '/billing' },
        { title: 'Cashier Sessions', href: route('billing.cashier.index') },
        { title: session.session_number, href: '#' },
    ];

    const { data, setData, post, processing, errors } = useForm<CloseSessionFormData>({
        actual_closing_balance: '',
        variance_reason: '',
        notes: '',
    });

    const openingBalance = Number(session.opening_balance);
    const expectedClosing = session.expected_closing_balance !== null
        ? Number(session.expected_closing_balance)
        : openingBalance + summary.netCashCollected;
    const actualClosing = data.actual_closing_balance !== '' ? Number(data.actual_closing_balance) : null;
    const liveVariance = actualClosing !== null ? actualClosing - expectedClosing : null;

    const submitClose = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('billing.cashier.close', session.id), {
            onSuccess: () => setCloseOpen(false),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={session.session_number} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{session.session_number}</h1>
                        <p className="text-muted-foreground text-sm">
                            {session.cashier?.name ?? '—'} · {session.hospital?.name ?? '—'}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Badge variant={session.status === 'open' ? 'default' : 'secondary'}>
                            {session.status === 'open' ? 'Open' : 'Closed'}
                        </Badge>
                        {canClose && session.status === 'open' && (
                            <Dialog open={closeOpen} onOpenChange={setCloseOpen}>
                                <DialogTrigger asChild>
                                    <Button size="sm">Close &amp; Reconcile</Button>
                                </DialogTrigger>
                                <DialogContent className="sm:max-w-md">
                                    <DialogHeader>
                                        <DialogTitle>Close Session</DialogTitle>
                                        <DialogDescription>
                                            Count the cash in the drawer and enter the actual closing balance to reconcile this session.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <form onSubmit={submitClose} className="flex flex-col gap-4 pt-2">
                                        <div className="rounded-md bg-muted p-3 text-sm">
                                            <p className="text-muted-foreground">Expected closing balance (cash)</p>
                                            <p className="text-xl font-semibold">{formatMoney(expectedClosing, 'TZS')}</p>
                                            <p className="text-muted-foreground mt-1 text-xs">
                                                Opening {formatMoney(openingBalance, 'TZS')} + Net cash collected{' '}
                                                {formatMoney(summary.netCashCollected, 'TZS')}
                                            </p>
                                        </div>

                                        <div className="flex flex-col gap-1.5">
                                            <Label htmlFor="actual_closing_balance">Actual Closing Balance</Label>
                                            <Input
                                                id="actual_closing_balance"
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                value={data.actual_closing_balance}
                                                onChange={(e) => setData('actual_closing_balance', e.target.value)}
                                                autoFocus
                                            />
                                            {errors.actual_closing_balance && (
                                                <p className="text-destructive text-sm">{errors.actual_closing_balance}</p>
                                            )}
                                        </div>

                                        {liveVariance !== null && liveVariance !== 0 && (
                                            <div className="flex flex-col gap-1.5">
                                                <p className="text-destructive text-sm font-medium">
                                                    Variance: {formatMoney(liveVariance, 'TZS')}
                                                </p>
                                                <Label htmlFor="variance_reason">Variance Reason (required)</Label>
                                                <Textarea
                                                    id="variance_reason"
                                                    value={data.variance_reason}
                                                    onChange={(e) => setData('variance_reason', e.target.value)}
                                                    rows={2}
                                                    placeholder="Explain the difference…"
                                                />
                                                {errors.variance_reason && (
                                                    <p className="text-destructive text-sm">{errors.variance_reason}</p>
                                                )}
                                            </div>
                                        )}

                                        <div className="flex flex-col gap-1.5">
                                            <Label htmlFor="close_notes">Notes (optional)</Label>
                                            <Textarea
                                                id="close_notes"
                                                value={data.notes}
                                                onChange={(e) => setData('notes', e.target.value)}
                                                rows={2}
                                            />
                                        </div>

                                        <div className="flex justify-end gap-2">
                                            <Button type="button" variant="outline" onClick={() => setCloseOpen(false)}>
                                                Cancel
                                            </Button>
                                            <Button type="submit" disabled={processing}>
                                                {processing ? 'Closing…' : 'Confirm Close'}
                                            </Button>
                                        </div>
                                    </form>
                                </DialogContent>
                            </Dialog>
                        )}
                    </div>
                </div>

                {/* Session overview */}
                <div className="grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-sm font-medium">Opening Balance</p>
                            <p className="text-2xl font-semibold">{formatMoney(session.opening_balance, 'TZS')}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-sm font-medium">Net Cash Collected</p>
                            <p className="text-2xl font-semibold">{formatMoney(summary.netCashCollected, 'TZS')}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-sm font-medium">Expected Closing</p>
                            <p className="text-2xl font-semibold">{formatMoney(expectedClosing, 'TZS')}</p>
                        </CardContent>
                    </Card>
                </div>

                {/* Collection summary by payment method */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Collections by Payment Method</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Method</TableHead>
                                    <TableHead className="text-right">Payments Received</TableHead>
                                    <TableHead className="text-right">Refunds Issued</TableHead>
                                    <TableHead className="text-right">Net</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {paymentMethods.map((method) => {
                                    const received = summary.paymentTotals[method] ?? 0;
                                    const refunded = summary.refundTotals[method] ?? 0;
                                    const net = received - refunded;
                                    return (
                                        <TableRow key={method}>
                                            <TableCell>{methodLabel[method] ?? method}</TableCell>
                                            <TableCell className="text-right">{formatMoney(received, 'TZS')}</TableCell>
                                            <TableCell className="text-right">{formatMoney(refunded, 'TZS')}</TableCell>
                                            <TableCell className={`text-right font-medium ${net < 0 ? 'text-destructive' : ''}`}>
                                                {formatMoney(net, 'TZS')}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                                {summary.reversalTotal > 0 && (
                                    <TableRow>
                                        <TableCell className="text-muted-foreground" colSpan={2}>
                                            Reversals (all methods)
                                        </TableCell>
                                        <TableCell className="text-right text-destructive font-medium" colSpan={2}>
                                            {formatMoney(summary.reversalTotal, 'TZS')}
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {/* Reconciliation result (closed sessions only) */}
                {session.status === 'closed' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Reconciliation</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                                <div>
                                    <dt className="text-muted-foreground">Expected Closing</dt>
                                    <dd>{formatMoney(session.expected_closing_balance ?? 0, 'TZS')}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">Actual Closing</dt>
                                    <dd>{formatMoney(session.actual_closing_balance ?? 0, 'TZS')}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">Variance</dt>
                                    <dd className={Number(session.variance) !== 0 ? 'text-destructive font-semibold' : ''}>
                                        {formatMoney(session.variance ?? 0, 'TZS')}
                                    </dd>
                                </div>
                                {session.variance_reason && (
                                    <div className="col-span-full">
                                        <dt className="text-muted-foreground">Variance Reason</dt>
                                        <dd>{session.variance_reason}</dd>
                                    </div>
                                )}
                                <div>
                                    <dt className="text-muted-foreground">Closed At</dt>
                                    <dd>{session.closed_at ? new Date(session.closed_at).toLocaleString() : '—'}</dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>
                )}

                {/* Session metadata */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Session Info</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                            <div>
                                <dt className="text-muted-foreground">Session Number</dt>
                                <dd className="font-mono">{session.session_number}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Cashier</dt>
                                <dd>{session.cashier?.name ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Facility</dt>
                                <dd>{session.hospital?.name ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Opened At</dt>
                                <dd>{new Date(session.opened_at).toLocaleString()}</dd>
                            </div>
                            {session.notes && (
                                <div className="col-span-full">
                                    <dt className="text-muted-foreground">Notes</dt>
                                    <dd>{session.notes}</dd>
                                </div>
                            )}
                        </dl>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
