import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatMoney, invoiceStatusLabel, invoiceStatusVariant } from '@/lib/billing-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type BillingInvoiceFilters, type Invoice } from '@/types/billing';
import { Head, Link, router } from '@inertiajs/react';
import { FileText } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing', href: '/billing' },
    { title: 'Invoices', href: '/billing/invoices' },
];

interface BillingInvoicesIndexProps {
    invoices: Paginated<Invoice>;
    filters: BillingInvoiceFilters;
}

function patientName(invoice: Invoice): string {
    if (!invoice.patient) {
        return '—';
    }

    return [invoice.patient.first_name, invoice.patient.middle_name, invoice.patient.last_name].filter(Boolean).join(' ');
}

/**
 * The searchable invoice list (Phase 7, Step 55) — every filter is applied
 * server-side by InvoiceSearchService::invoicesQuery(), mirroring
 * BillingAccountsIndex's own shape exactly.
 */
export default function BillingInvoicesIndex({ invoices, filters }: BillingInvoicesIndexProps) {
    const applyFilters = (overrides: Partial<BillingInvoiceFilters>) => {
        const params: Record<string, string> = { ...filters, ...overrides };
        router.get(route('billing.invoices.index'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(filters.search || filters.status);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Invoices" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Invoices</h1>
                    <p className="text-muted-foreground text-sm">Every invoice raised against a patient account.</p>
                </div>

                <div className="flex flex-col gap-3">
                    <Input
                        value={filters.search || ''}
                        onChange={(e) => applyFilters({ search: e.target.value })}
                        placeholder="Search by invoice number, patient name, or account number"
                        aria-label="Search invoices"
                        className="max-w-sm"
                    />

                    <div className="flex flex-wrap items-center gap-2">
                        <Select value={filters.status || 'all'} onValueChange={(value) => applyFilters({ status: value === 'all' ? '' : value })}>
                            <SelectTrigger className="w-[180px]">
                                <SelectValue placeholder="All statuses" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All statuses</SelectItem>
                                <SelectItem value="draft">Draft</SelectItem>
                                <SelectItem value="posted">Posted</SelectItem>
                                <SelectItem value="voided">Voided</SelectItem>
                                <SelectItem value="cancelled">Cancelled</SelectItem>
                            </SelectContent>
                        </Select>

                        {hasFilters && (
                            <Button variant="ghost" size="sm" onClick={() => router.get(route('billing.invoices.index'))}>
                                Clear filters
                            </Button>
                        )}
                    </div>
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Invoice #</TableHead>
                            <TableHead>Patient</TableHead>
                            <TableHead>Hospital</TableHead>
                            <TableHead className="text-right">Total</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Created</TableHead>
                            <TableHead className="text-right">Action</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {invoices.data.map((invoice) => (
                            <TableRow key={invoice.id}>
                                <TableCell>{invoice.invoice_number}</TableCell>
                                <TableCell>
                                    <div className="flex flex-col">
                                        <span>{patientName(invoice)}</span>
                                        {invoice.patient_account && (
                                            <span className="text-muted-foreground text-xs">{invoice.patient_account.account_number}</span>
                                        )}
                                    </div>
                                </TableCell>
                                <TableCell>{invoice.hospital?.name ?? '—'}</TableCell>
                                <TableCell className="text-right">
                                    {invoice.total_amount !== null ? formatMoney(invoice.total_amount, invoice.currency) : '—'}
                                </TableCell>
                                <TableCell>
                                    <Badge variant={invoiceStatusVariant[invoice.status]}>{invoiceStatusLabel[invoice.status]}</Badge>
                                </TableCell>
                                <TableCell>{new Date(invoice.created_at).toLocaleDateString()}</TableCell>
                                <TableCell className="text-right">
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={route('billing.invoices.show', invoice.id)}>View</Link>
                                    </Button>
                                </TableCell>
                            </TableRow>
                        ))}

                        {invoices.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={7} className="p-0">
                                    <EmptyState
                                        icon={FileText}
                                        title={hasFilters ? 'No invoices match your search or filters' : 'No invoices yet'}
                                        description={
                                            hasFilters
                                                ? 'Try a different search term or clear your filters.'
                                                : 'Start one from a patient account’s eligible charges.'
                                        }
                                    />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>

                <Pagination links={invoices.links} />
            </div>
        </AppLayout>
    );
}
