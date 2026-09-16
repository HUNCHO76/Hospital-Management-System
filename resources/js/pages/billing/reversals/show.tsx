import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/billing-status';
import { paymentMethodLabel } from '@/lib/payment-status';
import { type BreadcrumbItem } from '@/types';
import { type PaymentReversal } from '@/types/correction';
import { Head, Link } from '@inertiajs/react';

interface Props {
    reversal: PaymentReversal & {
        original_payment: {
            id: number;
            payment_number: string;
            amount: string;
            payment_method: 'cash' | 'card' | 'bank_transfer' | 'mobile_money' | 'other';
            payment_date: string;
        } | null;
    };
}

export default function BillingReversalShow({ reversal }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Billing', href: '/billing' },
        { title: 'Payments', href: '/billing/payments' },
        reversal.original_payment
            ? { title: reversal.original_payment.payment_number, href: route('billing.payments.show', reversal.original_payment.id) }
            : { title: 'Payment', href: '/billing/payments' },
        { title: reversal.reversal_number, href: '#' },
    ];

    const patientName = reversal.patient
        ? [reversal.patient.first_name, reversal.patient.middle_name, reversal.patient.last_name].filter(Boolean).join(' ')
        : '—';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={reversal.reversal_number} />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">{reversal.reversal_number}</h1>
                    <p className="text-muted-foreground text-sm">
                        {patientName} · {reversal.hospital?.name ?? '—'}
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-sm font-medium">Reversed Amount</p>
                            <p className="text-2xl font-semibold tracking-tight">{formatMoney(reversal.amount, reversal.currency)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-sm font-medium">Reversed At</p>
                            <p className="text-2xl font-semibold tracking-tight">{new Date(reversal.reversed_at).toLocaleString()}</p>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                            <div>
                                <dt className="text-muted-foreground">Original Payment</dt>
                                <dd>
                                    {reversal.original_payment ? (
                                        <Link href={route('billing.payments.show', reversal.original_payment.id)} className="hover:underline">
                                            {reversal.original_payment.payment_number}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </dd>
                            </div>
                            {reversal.original_payment && (
                                <div>
                                    <dt className="text-muted-foreground">Payment Method</dt>
                                    <dd>{paymentMethodLabel[reversal.original_payment.payment_method]}</dd>
                                </div>
                            )}
                            <div>
                                <dt className="text-muted-foreground">Invoice</dt>
                                <dd>
                                    {reversal.invoice ? (
                                        <Link href={route('billing.invoices.show', reversal.invoice.id)} className="hover:underline">
                                            {reversal.invoice.invoice_number}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Reversed By</dt>
                                <dd>{reversal.reversed_by?.name ?? '—'}</dd>
                            </div>
                            <div className="col-span-full">
                                <dt className="text-muted-foreground">Reason</dt>
                                <dd>{reversal.reason}</dd>
                            </div>
                            {reversal.notes && (
                                <div className="col-span-full">
                                    <dt className="text-muted-foreground">Notes</dt>
                                    <dd>{reversal.notes}</dd>
                                </div>
                            )}
                        </dl>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
