import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/billing-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing', href: '/billing' },
    { title: 'Reports', href: '/billing/reports' },
    { title: 'Invoices', href: '/billing/reports/invoices' },
];

interface InvoiceRow {
    id: number;
    invoice_number: string;
    invoice_date: string | null;
    status: string;
    total_amount: number;
    currency: string;
    posted_at: string | null;
    patient: { id: number; name: string; patient_number: string } | null;
    hospital: { id: number; name: string } | null;
}

interface Props {
    invoices: Paginated<InvoiceRow>;
    filters: { search?: string; status?: string; date_from?: string; date_to?: string };
    statuses: string[];
}

export default function InvoicesReport({ invoices, filters, statuses }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');

    function applyFilters(overrides: Record<string, string>) {
        router.get(route('billing.reports.invoices'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Invoice Report" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Invoice Report</h1>
                        <p className="text-muted-foreground text-sm">All invoices with status and totals.</p>
                    </div>
                    <Button variant="outline" asChild>
                        <a href={route('billing.reports.invoices', { ...filters, export: 1 })}>
                            <Download className="h-4 w-4" />
                            Export CSV
                        </a>
                    </Button>
                </div>

                <div className="flex flex-wrap gap-3">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            applyFilters({ search });
                        }}
                        className="flex gap-2"
                    >
                        <Input
                            placeholder="Invoice number…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="w-48"
                        />
                        <Button type="submit" variant="secondary" size="sm">
                            Search
                        </Button>
                    </form>

                    <Select value={filters.status ?? ''} onValueChange={(v) => applyFilters({ status: v === 'all' ? '' : v })}>
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="All statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            {statuses.map((s) => (
                                <SelectItem key={s} value={s}>
                                    {s.charAt(0).toUpperCase() + s.slice(1)}
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
                                <TableHead>Invoice #</TableHead>
                                <TableHead>Date</TableHead>
                                <TableHead>Patient</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">Amount</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {invoices.data.map((inv) => (
                                <TableRow key={inv.id}>
                                    <TableCell>
                                        <Link href={route('billing.invoices.show', inv.id)} className="hover:underline">
                                            {inv.invoice_number}
                                        </Link>
                                    </TableCell>
                                    <TableCell>{inv.invoice_date ?? '—'}</TableCell>
                                    <TableCell>{inv.patient?.name ?? '—'}</TableCell>
                                    <TableCell className="capitalize">{inv.status}</TableCell>
                                    <TableCell className="text-right">{formatMoney(inv.total_amount, inv.currency)}</TableCell>
                                </TableRow>
                            ))}
                            {invoices.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={5} className="text-muted-foreground py-8 text-center text-sm">
                                        No invoices found.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>

                {invoices.last_page > 1 && (
                    <div className="flex justify-center gap-2">
                        {invoices.links.map((link, i) => (
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
