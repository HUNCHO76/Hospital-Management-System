import { Badge } from '@/components/ui/badge';
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
    { title: 'Cashier Sessions', href: '/billing/reports/cashier-sessions' },
];

interface SessionRow {
    id: number;
    session_number: string;
    status: string;
    opening_balance: number;
    expected_closing_balance: number | null;
    actual_closing_balance: number | null;
    variance: number | null;
    opened_at: string | null;
    closed_at: string | null;
    cashier: { id: number; name: string } | null;
    hospital: { id: number; name: string } | null;
}

interface Props {
    sessions: Paginated<SessionRow>;
    filters: { status?: string; date_from?: string; date_to?: string; cashier_id?: string };
    statuses: string[];
}

const CURRENCY = 'TZS';

export default function CashierSessionsReport({ sessions, filters, statuses }: Props) {
    function applyFilters(overrides: Record<string, string>) {
        router.get(route('billing.reports.cashier-sessions'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cashier Sessions Report" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Cashier Sessions Report</h1>
                        <p className="text-muted-foreground text-sm">Session reconciliation with opening/closing balances and variance.</p>
                    </div>
                    <Button variant="outline" asChild>
                        <a href={route('billing.reports.cashier-sessions', { ...filters, export: 1 })}>
                            <Download className="h-4 w-4" />
                            Export CSV
                        </a>
                    </Button>
                </div>

                <div className="flex flex-wrap gap-3">
                    <Select value={filters.status ?? ''} onValueChange={(v) => applyFilters({ status: v === 'all' ? '' : v })}>
                        <SelectTrigger className="w-36">
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
                                <TableHead>Session #</TableHead>
                                <TableHead>Cashier</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">Opening</TableHead>
                                <TableHead className="text-right">Expected</TableHead>
                                <TableHead className="text-right">Actual</TableHead>
                                <TableHead className="text-right">Variance</TableHead>
                                <TableHead>Opened</TableHead>
                                <TableHead>Closed</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {sessions.data.map((session) => (
                                <TableRow key={session.id}>
                                    <TableCell>
                                        <Link href={route('billing.cashier.show', session.id)} className="hover:underline">
                                            {session.session_number}
                                        </Link>
                                    </TableCell>
                                    <TableCell>{session.cashier?.name ?? '—'}</TableCell>
                                    <TableCell>
                                        <Badge variant={session.status === 'open' ? 'default' : 'secondary'}>
                                            {session.status}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="text-right">{formatMoney(session.opening_balance, CURRENCY)}</TableCell>
                                    <TableCell className="text-right">
                                        {session.expected_closing_balance != null ? formatMoney(session.expected_closing_balance, CURRENCY) : '—'}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {session.actual_closing_balance != null ? formatMoney(session.actual_closing_balance, CURRENCY) : '—'}
                                    </TableCell>
                                    <TableCell className={`text-right ${session.variance != null && session.variance !== 0 ? 'text-destructive font-medium' : ''}`}>
                                        {session.variance != null ? formatMoney(session.variance, CURRENCY) : '—'}
                                    </TableCell>
                                    <TableCell>{session.opened_at ? new Date(session.opened_at).toLocaleDateString() : '—'}</TableCell>
                                    <TableCell>{session.closed_at ? new Date(session.closed_at).toLocaleDateString() : '—'}</TableCell>
                                </TableRow>
                            ))}
                            {sessions.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={9} className="text-muted-foreground py-8 text-center text-sm">
                                        No sessions found.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>

                {sessions.last_page > 1 && (
                    <div className="flex justify-center gap-2">
                        {sessions.links.map((link, i) => (
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
