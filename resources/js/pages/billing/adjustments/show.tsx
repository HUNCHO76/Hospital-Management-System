import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/billing-status';
import { adjustmentDirectionVariant, adjustmentTypeLabel } from '@/lib/correction-status';
import { type BreadcrumbItem } from '@/types';
import { type BillingAdjustment } from '@/types/correction';
import { Head, Link } from '@inertiajs/react';

interface Props {
    adjustment: BillingAdjustment;
}

export default function BillingAdjustmentShow({ adjustment }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Billing', href: '/billing' },
        { title: 'Adjustments', href: route('billing.adjustments.index') },
        { title: adjustment.adjustment_number, href: '#' },
    ];

    const patientName = adjustment.patient
        ? [adjustment.patient.first_name, adjustment.patient.middle_name, adjustment.patient.last_name].filter(Boolean).join(' ')
        : '—';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={adjustment.adjustment_number} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{adjustment.adjustment_number}</h1>
                        <p className="text-muted-foreground text-sm">
                            {patientName} · {adjustment.hospital?.name ?? '—'}
                        </p>
                    </div>
                    <Badge variant={adjustmentDirectionVariant[adjustment.direction]}>
                        {adjustment.direction === 'credit' ? 'Credit' : 'Debit'}
                    </Badge>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-sm font-medium">Amount</p>
                            <p className="text-2xl font-semibold tracking-tight">{formatMoney(adjustment.amount, adjustment.currency)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-sm font-medium">Adjusted At</p>
                            <p className="text-2xl font-semibold tracking-tight">{new Date(adjustment.adjusted_at).toLocaleString()}</p>
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
                                <dt className="text-muted-foreground">Type</dt>
                                <dd>{adjustmentTypeLabel[adjustment.type]}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Invoice</dt>
                                <dd>
                                    {adjustment.invoice ? (
                                        <Link href={route('billing.invoices.show', adjustment.invoice.id)} className="hover:underline">
                                            {adjustment.invoice.invoice_number}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Adjusted By</dt>
                                <dd>{adjustment.adjusted_by?.name ?? '—'}</dd>
                            </div>
                            <div className="col-span-full">
                                <dt className="text-muted-foreground">Reason</dt>
                                <dd>{adjustment.reason}</dd>
                            </div>
                            {adjustment.notes && (
                                <div className="col-span-full">
                                    <dt className="text-muted-foreground">Notes</dt>
                                    <dd>{adjustment.notes}</dd>
                                </div>
                            )}
                        </dl>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
