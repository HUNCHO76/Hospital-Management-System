import { VoidInvoiceDialog } from '@/components/billing/void-invoice-dialog';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatMoney, invoiceStatusLabel, invoiceStatusVariant } from '@/lib/billing-status';
import { adjustmentDirectionLabel, adjustmentDirectionVariant, adjustmentTypeLabel } from '@/lib/correction-status';
import { invoicePaymentStatusLabel, invoicePaymentStatusVariant, paymentMethodLabel } from '@/lib/payment-status';
import { type BreadcrumbItem } from '@/types';
import { type Charge, type Invoice } from '@/types/billing';
import { type BillingAdjustment, type AdjustmentDirection, type AdjustmentType, type StoreAdjustmentFormData } from '@/types/correction';
import { type OutstandingBreakdown, type Payment } from '@/types/payment';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

interface BillingInvoiceShowProps {
    invoice: Invoice;
    currentTotal: number;
    eligibleCharges: Charge[];
    outstanding: OutstandingBreakdown | null;
    payments: Payment[];
    adjustments: BillingAdjustment[];
    canRecordPayment: boolean;
    canAdjust: boolean;
    canManage: boolean;
    canPost: boolean;
    canVoid: boolean;
    canViewInsurance: boolean;
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

const ADJUSTMENT_TYPES: { value: AdjustmentType; label: string }[] = [
    { value: 'credit_adjustment', label: 'Credit Adjustment' },
    { value: 'debit_adjustment', label: 'Debit Adjustment' },
    { value: 'approved_discount', label: 'Approved Discount' },
    { value: 'write_off', label: 'Write-Off' },
    { value: 'billing_correction', label: 'Billing Correction' },
];

const ADJUSTMENT_DIRECTIONS: { value: AdjustmentDirection; label: string }[] = [
    { value: 'credit', label: 'Credit (reduces patient balance)' },
    { value: 'debit', label: 'Debit (increases patient balance)' },
];

function AdjustmentDialog({ invoiceId }: { invoiceId: number }) {
    const { data, setData, post, processing, errors, reset } = useForm<StoreAdjustmentFormData>({
        type: '',
        direction: '',
        amount: '',
        reason: '',
        notes: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('billing.invoices.adjustments.store', invoiceId), { preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button type="button" variant="outline">
                    Post Adjustment
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle>Post billing adjustment</DialogTitle>
                        <DialogDescription>
                            Adjustments modify the patient's outstanding balance without altering the original invoice or payments.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid gap-2">
                            <Label htmlFor="adj_type">Type *</Label>
                            <Select value={data.type} onValueChange={(v) => setData('type', v as AdjustmentType)}>
                                <SelectTrigger id="adj_type">
                                    <SelectValue placeholder="Select type" />
                                </SelectTrigger>
                                <SelectContent>
                                    {ADJUSTMENT_TYPES.map((t) => (
                                        <SelectItem key={t.value} value={t.value}>
                                            {t.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.type} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="adj_direction">Direction *</Label>
                            <Select value={data.direction} onValueChange={(v) => setData('direction', v as AdjustmentDirection)}>
                                <SelectTrigger id="adj_direction">
                                    <SelectValue placeholder="Select direction" />
                                </SelectTrigger>
                                <SelectContent>
                                    {ADJUSTMENT_DIRECTIONS.map((d) => (
                                        <SelectItem key={d.value} value={d.value}>
                                            {d.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.direction} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="adj_amount">Amount *</Label>
                            <Input
                                id="adj_amount"
                                type="number"
                                min="0.01"
                                step="0.01"
                                value={data.amount}
                                onChange={(e) => setData('amount', e.target.value)}
                            />
                            <InputError message={errors.amount} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="adj_reason">Reason *</Label>
                            <Textarea id="adj_reason" rows={2} value={data.reason} onChange={(e) => setData('reason', e.target.value)} />
                            <InputError message={errors.reason} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="adj_notes">Notes</Label>
                            <Textarea id="adj_notes" rows={2} value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                            <InputError message={errors.notes} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Post Adjustment
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * A single invoice's detail page (Phase 7, Steps 55/58, spec §25-27 + §58).
 * Extends the Step 55 page with an inline AdjustmentDialog, an adjustments
 * table, and the extended outstanding breakdown (reversals/refunds/adjustments).
 */
export default function BillingInvoiceShow({
    invoice,
    currentTotal,
    eligibleCharges,
    outstanding,
    payments,
    adjustments,
    canRecordPayment,
    canAdjust,
    canManage,
    canPost,
    canVoid,
    canViewInsurance,
}: BillingInvoiceShowProps) {
    const [selectedChargeId, setSelectedChargeId] = useState<string>('');

    const patientName = invoice.patient
        ? [invoice.patient.first_name, invoice.patient.middle_name, invoice.patient.last_name].filter(Boolean).join(' ')
        : '—';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Billing', href: '/billing' },
        { title: 'Invoices', href: '/billing/invoices' },
        { title: invoice.invoice_number, href: `/billing/invoices/${invoice.id}` },
    ];

    const addCharge = () => {
        if (!selectedChargeId) {
            return;
        }

        router.post(
            route('billing.invoices.charges.add', invoice.id),
            { charge_id: selectedChargeId },
            { preserveScroll: true, onSuccess: () => setSelectedChargeId('') },
        );
    };

    const removeCharge = (chargeId: number) => {
        router.post(route('billing.invoices.charges.remove', [invoice.id, chargeId]), {}, { preserveScroll: true });
    };

    const displayTotal = invoice.status === 'draft' ? currentTotal : Number(invoice.total_amount ?? 0);

    const hasCorrections =
        outstanding &&
        ((outstanding.reversals_total ?? 0) > 0 || (outstanding.refunds_total ?? 0) > 0 || (outstanding.credit_adjustments ?? 0) > 0 || (outstanding.debit_adjustments ?? 0) > 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={invoice.invoice_number} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{invoice.invoice_number}</h1>
                        <p className="text-muted-foreground text-sm">
                            {patientName}
                            {invoice.patient_account ? ` · ${invoice.patient_account.account_number}` : ''} · {invoice.hospital?.name ?? '—'}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant={invoiceStatusVariant[invoice.status]}>{invoiceStatusLabel[invoice.status]}</Badge>
                        {canViewInsurance && (
                            <Button asChild variant="outline">
                                <Link href={route('billing.invoices.insurance.show', invoice.id)}>Insurance & Coverage</Link>
                            </Button>
                        )}
                        {invoice.status === 'posted' && canAdjust && <AdjustmentDialog invoiceId={invoice.id} />}
                        {invoice.status === 'posted' && canRecordPayment && outstanding && outstanding.patient_outstanding > 0 && (
                            <Button asChild>
                                <Link href={route('billing.payments.create', { patient_id: invoice.patient?.id })}>Record Payment</Link>
                            </Button>
                        )}
                        {invoice.status === 'draft' && canPost && (
                            <Button type="button" onClick={() => router.post(route('billing.invoices.post', invoice.id), {}, { preserveScroll: true })}>
                                Post Invoice
                            </Button>
                        )}
                        {invoice.status === 'draft' && canVoid && (
                            <VoidInvoiceDialog
                                invoiceId={invoice.id}
                                mode="void"
                                trigger={
                                    <Button type="button" variant="outline">
                                        Void
                                    </Button>
                                }
                            />
                        )}
                        {invoice.status === 'posted' && canVoid && (
                            <VoidInvoiceDialog
                                invoiceId={invoice.id}
                                mode="cancel"
                                trigger={
                                    <Button type="button" variant="outline">
                                        Cancel
                                    </Button>
                                }
                            />
                        )}
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-sm font-medium">
                                {invoice.status === 'draft' ? 'Current Total (live)' : 'Total Amount'}
                            </p>
                            <p className="text-2xl font-semibold tracking-tight">{formatMoney(displayTotal, invoice.currency)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-sm font-medium">Invoice Date</p>
                            <p className="text-2xl font-semibold tracking-tight">{invoice.invoice_date ?? '—'}</p>
                        </CardContent>
                    </Card>
                </div>

                {outstanding && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base flex items-center gap-2">
                                Patient Payment Status
                                <Badge variant={invoicePaymentStatusVariant[outstanding.status]}>
                                    {invoicePaymentStatusLabel[outstanding.status]}
                                </Badge>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                            <div>
                                <p className="text-muted-foreground">Insurance Responsibility</p>
                                <p className="font-medium">{formatMoney(outstanding.insurance_responsibility, invoice.currency)}</p>
                            </div>
                            <div>
                                <p className="text-muted-foreground">Patient Responsibility</p>
                                <p className="font-medium">{formatMoney(outstanding.patient_responsibility, invoice.currency)}</p>
                            </div>
                            <div>
                                <p className="text-muted-foreground">Already Paid</p>
                                <p className="font-medium">{formatMoney(outstanding.patient_paid, invoice.currency)}</p>
                            </div>
                            <div>
                                <p className="text-muted-foreground">Outstanding</p>
                                <p className="font-semibold">{formatMoney(outstanding.patient_outstanding, invoice.currency)}</p>
                            </div>
                            {hasCorrections && (
                                <>
                                    {(outstanding.reversals_total ?? 0) > 0 && (
                                        <div>
                                            <p className="text-muted-foreground">Reversals</p>
                                            <p className="font-medium text-destructive">+{formatMoney(outstanding.reversals_total, invoice.currency)}</p>
                                        </div>
                                    )}
                                    {(outstanding.refunds_total ?? 0) > 0 && (
                                        <div>
                                            <p className="text-muted-foreground">Refunds</p>
                                            <p className="font-medium text-destructive">+{formatMoney(outstanding.refunds_total, invoice.currency)}</p>
                                        </div>
                                    )}
                                    {(outstanding.credit_adjustments ?? 0) > 0 && (
                                        <div>
                                            <p className="text-muted-foreground">Credit Adjustments</p>
                                            <p className="font-medium text-green-600">-{formatMoney(outstanding.credit_adjustments, invoice.currency)}</p>
                                        </div>
                                    )}
                                    {(outstanding.debit_adjustments ?? 0) > 0 && (
                                        <div>
                                            <p className="text-muted-foreground">Debit Adjustments</p>
                                            <p className="font-medium text-destructive">+{formatMoney(outstanding.debit_adjustments, invoice.currency)}</p>
                                        </div>
                                    )}
                                </>
                            )}
                        </CardContent>
                    </Card>
                )}

                {invoice.status === 'cancelled' && invoice.cancellation_reason && (
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-sm font-medium">Cancellation reason</p>
                            <p className="text-muted-foreground text-sm">{invoice.cancellation_reason}</p>
                        </CardContent>
                    </Card>
                )}

                {invoice.status === 'voided' && invoice.cancellation_reason && (
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-sm font-medium">Void reason</p>
                            <p className="text-muted-foreground text-sm">{invoice.cancellation_reason}</p>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Charges</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Description</TableHead>
                                    <TableHead className="text-right">Qty</TableHead>
                                    <TableHead className="text-right">Unit Price</TableHead>
                                    <TableHead className="text-right">Net Amount</TableHead>
                                    <TableHead>Charged</TableHead>
                                    {invoice.status === 'draft' && canManage && <TableHead className="text-right">Actions</TableHead>}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {invoice.charges.map((charge) => (
                                    <TableRow key={charge.id}>
                                        <TableCell>
                                            <div className="flex flex-col">
                                                <span>{charge.description}</span>
                                                <span className="text-muted-foreground text-xs">{charge.charge_number}</span>
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right">{charge.quantity}</TableCell>
                                        <TableCell className="text-right">{formatMoney(charge.unit_price, charge.currency)}</TableCell>
                                        <TableCell className="text-right">{formatMoney(charge.net_amount, charge.currency)}</TableCell>
                                        <TableCell>{formatDateTime(charge.charged_at)}</TableCell>
                                        {invoice.status === 'draft' && canManage && (
                                            <TableCell className="text-right">
                                                <Button size="sm" variant="ghost" onClick={() => removeCharge(charge.id)}>
                                                    Remove
                                                </Button>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}

                                {invoice.charges.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={invoice.status === 'draft' && canManage ? 6 : 5}
                                            className="text-muted-foreground py-6 text-center text-sm"
                                        >
                                            No charges included yet.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {invoice.status === 'draft' && canManage && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Add a Charge</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {eligibleCharges.length === 0 ? (
                                <p className="text-muted-foreground text-sm">No further posted, uninvoiced charges are available on this account.</p>
                            ) : (
                                <div className="flex flex-wrap items-center gap-2">
                                    <Select value={selectedChargeId} onValueChange={setSelectedChargeId}>
                                        <SelectTrigger className="w-[320px]">
                                            <SelectValue placeholder="Select a charge to add" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {eligibleCharges.map((charge) => (
                                                <SelectItem key={charge.id} value={String(charge.id)}>
                                                    {charge.description} — {formatMoney(charge.net_amount, charge.currency)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <Button type="button" onClick={addCharge} disabled={!selectedChargeId}>
                                        Add
                                    </Button>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {payments.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Payments</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Payment #</TableHead>
                                        <TableHead className="text-right">Amount</TableHead>
                                        <TableHead>Method</TableHead>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Received By</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">Action</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {payments.map((payment) => (
                                        <TableRow key={payment.id}>
                                            <TableCell>{payment.payment_number}</TableCell>
                                            <TableCell className="text-right">{formatMoney(payment.amount, payment.currency)}</TableCell>
                                            <TableCell>{paymentMethodLabel[payment.payment_method]}</TableCell>
                                            <TableCell>{payment.payment_date}</TableCell>
                                            <TableCell>{payment.received_by?.name ?? '—'}</TableCell>
                                            <TableCell>
                                                <Badge variant={payment.status === 'posted' ? 'default' : 'destructive'}>{payment.status}</Badge>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Button asChild size="sm" variant="ghost">
                                                    <Link href={route('billing.payments.show', payment.id)}>View</Link>
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}

                {adjustments.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Billing Adjustments</CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b">
                                        <th className="px-4 py-2 text-left font-medium">Number</th>
                                        <th className="px-4 py-2 text-left font-medium">Type</th>
                                        <th className="px-4 py-2 text-left font-medium">Direction</th>
                                        <th className="px-4 py-2 text-right font-medium">Amount</th>
                                        <th className="px-4 py-2 text-left font-medium">Reason</th>
                                        <th className="px-4 py-2 text-left font-medium">Date</th>
                                        <th className="px-4 py-2 text-left font-medium">By</th>
                                        <th className="px-4 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {adjustments.map((adj) => (
                                        <tr key={adj.id} className="border-b last:border-0">
                                            <td className="px-4 py-2 font-mono">{adj.adjustment_number}</td>
                                            <td className="px-4 py-2">{adjustmentTypeLabel[adj.type]}</td>
                                            <td className="px-4 py-2">
                                                <Badge variant={adjustmentDirectionVariant[adj.direction]}>
                                                    {adj.direction === 'credit' ? 'Credit' : 'Debit'}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-2 text-right">{formatMoney(adj.amount, adj.currency)}</td>
                                            <td className="px-4 py-2">{adj.reason}</td>
                                            <td className="px-4 py-2">{new Date(adj.adjusted_at).toLocaleDateString()}</td>
                                            <td className="px-4 py-2">{adj.adjusted_by?.name ?? '—'}</td>
                                            <td className="px-4 py-2">
                                                <Link
                                                    href={route('billing.adjustments.show', adj.id)}
                                                    className="text-primary hover:underline"
                                                >
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
