import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/billing-status';
import { paymentMethodLabel } from '@/lib/payment-status';
import { type BreadcrumbItem } from '@/types';
import { type Payment } from '@/types/payment';
import { Head, Link } from '@inertiajs/react';
import { Printer } from 'lucide-react';

interface BillingPaymentReceiptProps {
    payment: Payment;
}

function patientName(payment: Payment): string {
    if (!payment.patient) {
        return '—';
    }

    return [payment.patient.first_name, payment.patient.middle_name, payment.patient.last_name].filter(Boolean).join(' ');
}

/**
 * A printable receipt for a posted payment (Phase 7, Step 57, spec
 * §57.17/§57.28) — the receipt is a plain, print-friendly render of the
 * same authorized Payment resource; it is never a second financial record
 * and contains only the fields spec §57.28 itself lists, nothing
 * internal (no raw database ids, no other patients' data).
 */
export default function BillingPaymentReceipt({ payment }: BillingPaymentReceiptProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Billing', href: '/billing' },
        { title: 'Payments', href: '/billing/payments' },
        { title: payment.payment_number, href: `/billing/payments/${payment.id}` },
        { title: 'Receipt', href: `/billing/payments/${payment.id}/receipt` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Receipt — ${payment.receipt_number}`} />

            <div className="bg-muted/30 flex min-h-screen flex-col items-center gap-4 p-6 print:bg-white print:p-0">
                <div className="flex w-full max-w-xl items-center justify-between print:hidden">
                    <Button asChild variant="outline">
                        <Link href={route('billing.payments.show', payment.id)}>Back to Payment</Link>
                    </Button>
                    <Button type="button" onClick={() => window.print()}>
                        <Printer className="h-4 w-4" />
                        Print
                    </Button>
                </div>

                <div className="w-full max-w-xl rounded-md border bg-white p-8 text-black shadow-sm print:w-full print:max-w-none print:border-0 print:p-0 print:shadow-none">
                    <div className="mb-6 text-center">
                        <h1 className="text-lg font-semibold">{payment.hospital?.name ?? 'St. Joseph DMI'}</h1>
                        <p className="text-sm text-gray-600">Payment Receipt</p>
                    </div>

                    <div className="mb-6 grid grid-cols-2 gap-y-2 text-sm">
                        <span className="text-gray-500">Receipt Number</span>
                        <span className="text-right font-medium">{payment.receipt_number}</span>

                        <span className="text-gray-500">Payment Number</span>
                        <span className="text-right">{payment.payment_number}</span>

                        <span className="text-gray-500">Date</span>
                        <span className="text-right">{payment.payment_date}</span>

                        <span className="text-gray-500">Patient</span>
                        <span className="text-right">{patientName(payment)}</span>

                        <span className="text-gray-500">Invoice</span>
                        <span className="text-right">{payment.invoice?.invoice_number ?? '—'}</span>

                        <span className="text-gray-500">Payment Method</span>
                        <span className="text-right">{paymentMethodLabel[payment.payment_method]}</span>

                        {payment.reference_number && (
                            <>
                                <span className="text-gray-500">Reference</span>
                                <span className="text-right">{payment.reference_number}</span>
                            </>
                        )}

                        <span className="text-gray-500">Received By</span>
                        <span className="text-right">{payment.received_by?.name ?? '—'}</span>
                    </div>

                    <div className="border-t pt-4">
                        <div className="flex items-center justify-between text-base font-semibold">
                            <span>Amount Paid</span>
                            <span>{formatMoney(payment.amount, payment.currency)}</span>
                        </div>
                    </div>

                    <p className="mt-8 text-center text-xs text-gray-400">This is a system-generated receipt.</p>
                </div>
            </div>
        </AppLayout>
    );
}
