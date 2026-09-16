import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/billing-status';
import { paymentMethodLabel } from '@/lib/payment-status';
import { type BreadcrumbItem } from '@/types';
import { type PaymentRefund } from '@/types/correction';
import { Head, Link } from '@inertiajs/react';

interface Props {
    refund: PaymentRefund;
}

export default function BillingRefundShow({ refund }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Billing', href: '/billing' },
        { title: 'Payments', href: '/billing/payments' },
        refund.payment
            ? { title: refund.payment.payment_number, href: route('billing.payments.show', refund.payment.id) }
            : { title: 'Payment', href: '/billing/payments' },
        { title: refund.refund_number, href: '#' },
    ];

    const patientName = refund.patient
        ? [refund.patient.first_name, refund.patient.middle_name, refund.patient.last_name].filter(Boolean).join(' ')
        : '—';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={refund.refund_number} />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">{refund.refund_number}</h1>
                    <p className="text-muted-foreground text-sm">
                        {patientName} · {refund.hospital?.name ?? '—'}
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-sm font-medium">Refund Amount</p>
                            <p className="text-2xl font-semibold tracking-tight">{formatMoney(refund.amount, refund.currency)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-sm font-medium">Processed At</p>
                            <p className="text-2xl font-semibold tracking-tight">{new Date(refund.processed_at).toLocaleString()}</p>
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
                                    {refund.payment ? (
                                        <Link href={route('billing.payments.show', refund.payment.id)} className="hover:underline">
                                            {refund.payment.payment_number}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Refund Method</dt>
                                <dd>{paymentMethodLabel[refund.refund_method]}</dd>
                            </div>
                            {refund.refund_reference && (
                                <div>
                                    <dt className="text-muted-foreground">Reference</dt>
                                    <dd>{refund.refund_reference}</dd>
                                </div>
                            )}
                            <div>
                                <dt className="text-muted-foreground">Invoice</dt>
                                <dd>
                                    {refund.invoice ? (
                                        <Link href={route('billing.invoices.show', refund.invoice.id)} className="hover:underline">
                                            {refund.invoice.invoice_number}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Processed By</dt>
                                <dd>{refund.processed_by?.name ?? '—'}</dd>
                            </div>
                            <div className="col-span-full">
                                <dt className="text-muted-foreground">Reason</dt>
                                <dd>{refund.reason}</dd>
                            </div>
                            {refund.notes && (
                                <div className="col-span-full">
                                    <dt className="text-muted-foreground">Notes</dt>
                                    <dd>{refund.notes}</dd>
                                </div>
                            )}
                        </dl>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
