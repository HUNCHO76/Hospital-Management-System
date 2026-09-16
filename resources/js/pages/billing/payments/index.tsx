import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/billing-status';
import { paymentMethodLabel, paymentStatusLabel, paymentStatusVariant } from '@/lib/payment-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type Payment, type PaymentFilters, type PaymentMethod, type PaymentStatus } from '@/types/payment';
import { Head, Link, router } from '@inertiajs/react';
import { Receipt } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing', href: '/billing' },
    { title: 'Payments', href: '/billing/payments' },
];

interface BillingPaymentsIndexProps {
    payments: Paginated<Payment>;
    filters: PaymentFilters;
    methods: PaymentMethod[];
    statuses: PaymentStatus[];
}

function patientName(payment: Payment): string {
    if (!payment.patient) {
        return '—';
    }

    return [payment.patient.first_name, payment.patient.middle_name, payment.patient.last_name].filter(Boolean).join(' ');
}

/**
 * The searchable payment list (Phase 7, Step 57) — every filter is applied
 * server-side by PaymentSearchService::paymentsQuery(), mirroring
 * BillingInvoicesIndex's own shape exactly.
 */
export default function BillingPaymentsIndex({ payments, filters, methods, statuses }: BillingPaymentsIndexProps) {
    const { can } = usePermissions();

    const applyFilters = (overrides: Partial<PaymentFilters>) => {
        const params: Record<string, string> = { ...filters, ...overrides };
        router.get(route('billing.payments.index'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(filters.search || filters.status || filters.payment_method);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Payments" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Payments</h1>
                        <p className="text-muted-foreground text-sm">Every payment recorded against a patient's outstanding balance.</p>
                    </div>
                    {can('billing-payments.create') && (
                        <Button asChild>
                            <Link href={route('billing.payments.create')}>Record Payment</Link>
                        </Button>
                    )}
                </div>

                <div className="flex flex-col gap-3">
                    <Input
                        value={filters.search || ''}
                        onChange={(e) => applyFilters({ search: e.target.value })}
                        placeholder="Search by payment #, receipt #, reference, or patient"
                        aria-label="Search payments"
                        className="max-w-sm"
                    />

                    <div className="flex flex-wrap items-center gap-2">
                        <Select value={filters.status || 'all'} onValueChange={(value) => applyFilters({ status: value === 'all' ? '' : value })}>
                            <SelectTrigger className="w-[160px]">
                                <SelectValue placeholder="All statuses" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All statuses</SelectItem>
                                {statuses.map((status) => (
                                    <SelectItem key={status} value={status}>
                                        {paymentStatusLabel[status]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.payment_method || 'all'}
                            onValueChange={(value) => applyFilters({ payment_method: value === 'all' ? '' : value })}
                        >
                            <SelectTrigger className="w-[180px]">
                                <SelectValue placeholder="All methods" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All methods</SelectItem>
                                {methods.map((method) => (
                                    <SelectItem key={method} value={method}>
                                        {paymentMethodLabel[method]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        {hasFilters && (
                            <Button variant="ghost" size="sm" onClick={() => router.get(route('billing.payments.index'))}>
                                Clear filters
                            </Button>
                        )}
                    </div>
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Payment #</TableHead>
                            <TableHead>Patient</TableHead>
                            <TableHead>Invoice</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                            <TableHead>Method</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Date</TableHead>
                            <TableHead className="text-right">Action</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {payments.data.map((payment) => (
                            <TableRow key={payment.id}>
                                <TableCell>{payment.payment_number}</TableCell>
                                <TableCell>{patientName(payment)}</TableCell>
                                <TableCell>{payment.invoice?.invoice_number ?? '—'}</TableCell>
                                <TableCell className="text-right">{formatMoney(payment.amount, payment.currency)}</TableCell>
                                <TableCell>{paymentMethodLabel[payment.payment_method]}</TableCell>
                                <TableCell>
                                    <Badge variant={paymentStatusVariant[payment.status]}>{paymentStatusLabel[payment.status]}</Badge>
                                </TableCell>
                                <TableCell>{payment.payment_date}</TableCell>
                                <TableCell className="text-right">
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={route('billing.payments.show', payment.id)}>View</Link>
                                    </Button>
                                </TableCell>
                            </TableRow>
                        ))}

                        {payments.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={8} className="p-0">
                                    <EmptyState
                                        icon={Receipt}
                                        title={hasFilters ? 'No payments match your search or filters' : 'No payments recorded yet'}
                                        description={
                                            hasFilters ? 'Try a different search term or clear your filters.' : 'Record one from a posted invoice.'
                                        }
                                    />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>

                <Pagination links={payments.links} />
            </div>
        </AppLayout>
    );
}
