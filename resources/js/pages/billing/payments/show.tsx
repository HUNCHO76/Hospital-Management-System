import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/billing-status';
import { paymentMethodLabel, paymentStatusLabel, paymentStatusVariant } from '@/lib/payment-status';
import { type BreadcrumbItem } from '@/types';
import { type StoreRefundFormData, type StoreReversalFormData } from '@/types/correction';
import { type Payment, type PaymentMethod, type VoidPaymentFormData } from '@/types/payment';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface BillingPaymentShowProps {
    payment: Payment;
    canVoid: boolean;
    canReverse: boolean;
    canRefund: boolean;
}

function patientName(payment: Payment): string {
    if (!payment.patient) {
        return '—';
    }

    return [payment.patient.first_name, payment.patient.middle_name, payment.patient.last_name].filter(Boolean).join(' ');
}

function MarkFailedDialog({ paymentId }: { paymentId: number }) {
    const { data, setData, post, processing, errors, reset } = useForm<VoidPaymentFormData>({ reason: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('billing.payments.void', paymentId), { preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button type="button" variant="outline">
                    Mark as Failed
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Mark payment as failed</DialogTitle>
                        <DialogDescription>
                            This payment will stop counting toward the invoice's paid total. The original record is kept for audit; genuine
                            refunds/reversals are handled separately.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2 py-4">
                        <Label htmlFor="payment_failure_reason">Reason *</Label>
                        <Textarea id="payment_failure_reason" rows={3} value={data.reason} onChange={(e) => setData('reason', e.target.value)} autoFocus />
                        <InputError message={errors.reason} />
                    </div>

                    <DialogFooter>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Mark as Failed
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ReversalDialog({ paymentId }: { paymentId: number }) {
    const { data, setData, post, processing, errors, reset } = useForm<StoreReversalFormData>({
        reason: '',
        notes: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('billing.payments.reversal.store', paymentId), { preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button type="button" variant="destructive">
                    Reverse Payment
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Reverse payment</DialogTitle>
                        <DialogDescription>
                            A full reversal cancels this payment entirely and adds the full amount back to the patient's outstanding balance. This
                            cannot be undone.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid gap-2">
                            <Label htmlFor="reversal_reason">Reason *</Label>
                            <Textarea
                                id="reversal_reason"
                                rows={3}
                                value={data.reason}
                                onChange={(e) => setData('reason', e.target.value)}
                                autoFocus
                            />
                            <InputError message={errors.reason} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="reversal_notes">Notes</Label>
                            <Textarea id="reversal_notes" rows={2} value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                            <InputError message={errors.notes} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Confirm Reversal
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

const PAYMENT_METHODS: { value: PaymentMethod; label: string }[] = [
    { value: 'cash', label: 'Cash' },
    { value: 'card', label: 'Card' },
    { value: 'bank_transfer', label: 'Bank Transfer' },
    { value: 'mobile_money', label: 'Mobile Money' },
    { value: 'other', label: 'Other' },
];

function RefundDialog({ paymentId }: { paymentId: number }) {
    const { data, setData, post, processing, errors, reset } = useForm<StoreRefundFormData>({
        amount: '',
        refund_method: '',
        refund_reference: '',
        reason: '',
        notes: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('billing.payments.refunds.store', paymentId), { preserveScroll: true, onSuccess: () => reset() });
    };

    const methodsRequiringRef: PaymentMethod[] = ['card', 'bank_transfer', 'mobile_money'];

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button type="button" variant="outline">
                    Issue Refund
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Issue refund</DialogTitle>
                        <DialogDescription>Issue a partial or full refund against this payment. Amount must not exceed the refundable balance.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid gap-2">
                            <Label htmlFor="refund_amount">Amount *</Label>
                            <Input
                                id="refund_amount"
                                type="number"
                                min="0.01"
                                step="0.01"
                                value={data.amount}
                                onChange={(e) => setData('amount', e.target.value)}
                                autoFocus
                            />
                            <InputError message={errors.amount} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="refund_method">Refund Method *</Label>
                            <Select value={data.refund_method} onValueChange={(v) => setData('refund_method', v as PaymentMethod)}>
                                <SelectTrigger id="refund_method">
                                    <SelectValue placeholder="Select method" />
                                </SelectTrigger>
                                <SelectContent>
                                    {PAYMENT_METHODS.map((m) => (
                                        <SelectItem key={m.value} value={m.value}>
                                            {m.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.refund_method} />
                        </div>
                        {data.refund_method && methodsRequiringRef.includes(data.refund_method as PaymentMethod) && (
                            <div className="grid gap-2">
                                <Label htmlFor="refund_reference">Reference Number *</Label>
                                <Input
                                    id="refund_reference"
                                    value={data.refund_reference}
                                    onChange={(e) => setData('refund_reference', e.target.value)}
                                />
                                <InputError message={errors.refund_reference} />
                            </div>
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="refund_reason">Reason *</Label>
                            <Textarea id="refund_reason" rows={2} value={data.reason} onChange={(e) => setData('reason', e.target.value)} />
                            <InputError message={errors.reason} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="refund_notes">Notes</Label>
                            <Textarea id="refund_notes" rows={2} value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                            <InputError message={errors.notes} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Issue Refund
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * A single payment's detail page (Phase 7, Step 57/58, spec §57.27 + §58).
 * Includes inline reversal and refund dialogs, plus a correction history
 * table showing any existing reversals/refunds on this payment.
 */
export default function BillingPaymentShow({ payment, canVoid, canReverse, canRefund }: BillingPaymentShowProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Billing', href: '/billing' },
        { title: 'Payments', href: '/billing/payments' },
        { title: payment.payment_number, href: `/billing/payments/${payment.id}` },
    ];

    const hasReversal = !!payment.reversal;
    const refunds = payment.refunds ?? [];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={payment.payment_number} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{payment.payment_number}</h1>
                        <p className="text-muted-foreground text-sm">
                            {patientName(payment)} · {payment.hospital?.name ?? '—'}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant={paymentStatusVariant[payment.status]}>{paymentStatusLabel[payment.status]}</Badge>
                        <Button asChild variant="outline">
                            <Link href={route('billing.payments.receipt', payment.id)}>View Receipt</Link>
                        </Button>
                        {payment.status === 'posted' && canVoid && !hasReversal && <MarkFailedDialog paymentId={payment.id} />}
                        {payment.status === 'posted' && canReverse && !hasReversal && refunds.length === 0 && (
                            <ReversalDialog paymentId={payment.id} />
                        )}
                        {payment.status === 'posted' && canRefund && !hasReversal && <RefundDialog paymentId={payment.id} />}
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-sm font-medium">Amount</p>
                            <p className="text-2xl font-semibold tracking-tight">{formatMoney(payment.amount, payment.currency)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-sm font-medium">Payment Method</p>
                            <p className="text-2xl font-semibold tracking-tight">{paymentMethodLabel[payment.payment_method]}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-sm font-medium">Payment Date</p>
                            <p className="text-2xl font-semibold tracking-tight">{payment.payment_date}</p>
                        </CardContent>
                    </Card>
                </div>

                {payment.status === 'failed' && payment.failure_reason && (
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-sm font-medium">Failure reason</p>
                            <p className="text-muted-foreground text-sm">{payment.failure_reason}</p>
                            {payment.failed_by && <p className="text-muted-foreground mt-1 text-xs">Marked failed by {payment.failed_by.name}</p>}
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                            <div>
                                <dt className="text-muted-foreground">Receipt Number</dt>
                                <dd>{payment.receipt_number}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Reference Number</dt>
                                <dd>{payment.reference_number ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Invoice</dt>
                                <dd>
                                    {payment.invoice ? (
                                        <Link href={route('billing.invoices.show', payment.invoice.id)} className="hover:underline">
                                            {payment.invoice.invoice_number}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Patient Account</dt>
                                <dd>
                                    {payment.patient_account ? (
                                        <Link href={route('billing.accounts.show', payment.patient_account.id)} className="hover:underline">
                                            {payment.patient_account.account_number}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Received By</dt>
                                <dd>{payment.received_by?.name ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Posted At</dt>
                                <dd>{payment.posted_at ? new Date(payment.posted_at).toLocaleString() : '—'}</dd>
                            </div>
                            {payment.notes && (
                                <div className="col-span-full">
                                    <dt className="text-muted-foreground">Notes</dt>
                                    <dd>{payment.notes}</dd>
                                </div>
                            )}
                        </dl>
                    </CardContent>
                </Card>

                {(hasReversal || refunds.length > 0) && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Financial Corrections</CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b">
                                        <th className="px-4 py-2 text-left font-medium">Number</th>
                                        <th className="px-4 py-2 text-left font-medium">Type</th>
                                        <th className="px-4 py-2 text-right font-medium">Amount</th>
                                        <th className="px-4 py-2 text-left font-medium">Reason</th>
                                        <th className="px-4 py-2 text-left font-medium">Date</th>
                                        <th className="px-4 py-2 text-left font-medium">By</th>
                                        <th className="px-4 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {hasReversal && payment.reversal && (
                                        <tr className="border-b last:border-0">
                                            <td className="px-4 py-2 font-mono">{payment.reversal.reversal_number}</td>
                                            <td className="px-4 py-2">
                                                <Badge variant="destructive">Reversal</Badge>
                                            </td>
                                            <td className="px-4 py-2 text-right">
                                                {formatMoney(payment.reversal.amount, payment.reversal.currency)}
                                            </td>
                                            <td className="px-4 py-2">{payment.reversal.reason}</td>
                                            <td className="px-4 py-2">{new Date(payment.reversal.reversed_at).toLocaleDateString()}</td>
                                            <td className="px-4 py-2">{payment.reversal.reversed_by?.name ?? '—'}</td>
                                            <td className="px-4 py-2">
                                                <Link
                                                    href={route('billing.reversals.show', payment.reversal.id)}
                                                    className="text-primary hover:underline"
                                                >
                                                    View
                                                </Link>
                                            </td>
                                        </tr>
                                    )}
                                    {refunds.map((refund) => (
                                        <tr key={refund.id} className="border-b last:border-0">
                                            <td className="px-4 py-2 font-mono">{refund.refund_number}</td>
                                            <td className="px-4 py-2">
                                                <Badge variant="secondary">Refund</Badge>
                                            </td>
                                            <td className="px-4 py-2 text-right">{formatMoney(refund.amount, refund.currency)}</td>
                                            <td className="px-4 py-2">{refund.reason}</td>
                                            <td className="px-4 py-2">{new Date(refund.processed_at).toLocaleDateString()}</td>
                                            <td className="px-4 py-2">{refund.processed_by?.name ?? '—'}</td>
                                            <td className="px-4 py-2">
                                                <Link href={route('billing.refunds.show', refund.id)} className="text-primary hover:underline">
                                                    View
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
