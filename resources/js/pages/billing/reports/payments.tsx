import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/billing-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Download } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing', href: '/billing' },
    { title: 'Reports', href: '/billing/reports' },
    { title: 'Payments', href: '/billing/reports/payments' },
];

interface PaymentRow {
    id: number;
    payment_number: string;
    receipt_number: string;
    payment_date: string | null;
    payment_method: string;
    amount: number;
    currency: string;
    patient: { id: number; name: string; patient_number: string } | null;
    invoice: { id: number; invoice_number: string } | null;
    received_by: { id: number; name: string } | null;
}

interface Props {
    payments: Paginated<PaymentRow>;
    filters: { payment_method?: string; date_from?: string; date_to?: string };
    paymentMethods: string[];
}

export default function PaymentsReport({ payments, filters, paymentMethods }: Props) {
    function applyFilters(overrides: Record<string, string>) {
        router.get(route('billing.reports.payments'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Payments Report" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Payments Report</h1>
                        <p className="text-muted-foreground text-sm">All recorded payments, filterable by method and date.</p>
                    </div>
                    <Button variant="outline" asChild>
                        <a href={route('billing.reports.payments', { ...filters, export: 1 })}>
                            <Download className="h-4 w-4" />
                            Export CSV
                        </a>
                    </Button>
                </div>

                <div className="flex flex-wrap gap-3">
                    <Select
                        value={filters.payment_method ?? ''}
                        onValueChange={(v) => applyFilters({ payment_method: v === 'all' ? '' : v })}
                    >
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="All methods" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All methods</SelectItem>
                            {paymentMethods.map((m) => (
                                <SelectItem key={m} value={m}>
                                    {m.replace('_', ' ')}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

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

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Payment #</TableHead>
                                <TableHead>Date</TableHead>
                                <TableHead>Patient</TableHead>
                                <TableHead>Invoice #</TableHead>
                                <TableHead>Method</TableHead>
                                <TableHead>Received By</TableHead>
                                <TableHead className="text-right">Amount</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {payments.data.map((pmt) => (
                                <TableRow key={pmt.id}>
                                    <TableCell>
                                        <Link href={route('billing.payments.show', pmt.id)} className="hover:underline">
                                            {pmt.payment_number}
                                        </Link>
                                    </TableCell>
                                    <TableCell>{pmt.payment_date ?? '—'}</TableCell>
                                    <TableCell>{pmt.patient?.name ?? '—'}</TableCell>
                                    <TableCell>
                                        {pmt.invoice ? (
                                            <Link href={route('billing.invoices.show', pmt.invoice.id)} className="hover:underline">
                                                {pmt.invoice.invoice_number}
                                            </Link>
                                        ) : (
                                            '—'
                                        )}
                                    </TableCell>
                                    <TableCell className="capitalize">{pmt.payment_method.replace('_', ' ')}</TableCell>
                                    <TableCell>{pmt.received_by?.name ?? '—'}</TableCell>
                                    <TableCell className="text-right">{formatMoney(pmt.amount, pmt.currency)}</TableCell>
                                </TableRow>
                            ))}
                            {payments.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={7} className="text-muted-foreground py-8 text-center text-sm">
                                        No payments found.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>

                {payments.last_page > 1 && (
                    <div className="flex justify-center gap-2">
                        {payments.links.map((link, i) => (
                            <Button
                                key={i}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!link.url}
                                onClick={() => link.url && router.visit(link.url)}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
