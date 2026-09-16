import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/billing-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type CashierSession, type CashierSessionStatus } from '@/types/cashier';
import { Head, Link, router } from '@inertiajs/react';
import { Wallet } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing', href: '/billing' },
    { title: 'Cashier Sessions', href: '/billing/cashier' },
];

interface Props {
    sessions: Paginated<CashierSession>;
    filters: { status: string };
    statuses: CashierSessionStatus[];
    openSession: CashierSession | null;
    canOpen: boolean;
}

const statusVariant = (status: CashierSessionStatus): 'default' | 'secondary' => {
    return status === 'open' ? 'default' : 'secondary';
};

export default function CashierSessionIndex({ sessions, filters, statuses, openSession, canOpen }: Props) {
    const applyFilters = (overrides: { status: string }) => {
        const params = { ...filters, ...overrides };
        router.get(route('billing.cashier.index'), params, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cashier Sessions" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Cashier Sessions</h1>
                        <p className="text-muted-foreground text-sm">Cashier shift sessions and collection reconciliation.</p>
                    </div>
                    {canOpen && !openSession && (
                        <Button asChild>
                            <Link href={route('billing.cashier.create')}>Open Session</Link>
                        </Button>
                    )}
                </div>

                {openSession && (
                    <Card className="border-primary/40 bg-primary/5">
                        <CardContent className="flex items-center justify-between gap-4 p-4">
                            <div>
                                <p className="text-sm font-medium">Your active session</p>
                                <p className="text-muted-foreground text-sm">
                                    {openSession.session_number} · {openSession.hospital?.name} · Opened{' '}
                                    {new Date(openSession.opened_at).toLocaleString()}
                                </p>
                            </div>
                            <Button asChild size="sm">
                                <Link href={route('billing.cashier.show', openSession.id)}>View &amp; Reconcile</Link>
                            </Button>
                        </CardContent>
                    </Card>
                )}

                <div className="flex flex-wrap items-center gap-2">
                    <Select value={filters.status || 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? '' : v })}>
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="All statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            {statuses.map((s) => (
                                <SelectItem key={s} value={s}>
                                    {s === 'open' ? 'Open' : 'Closed'}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {filters.status && (
                        <Button variant="ghost" size="sm" onClick={() => applyFilters({ status: '' })}>
                            Clear
                        </Button>
                    )}
                </div>

                {sessions.data.length === 0 ? (
                    <EmptyState icon={Wallet} title="No sessions found" description="No cashier sessions match your current filters." />
                ) : (
                    <>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Number</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Cashier</TableHead>
                                    <TableHead>Facility</TableHead>
                                    <TableHead className="text-right">Opening Balance</TableHead>
                                    <TableHead className="text-right">Variance</TableHead>
                                    <TableHead>Opened At</TableHead>
                                    <TableHead className="text-right">Action</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sessions.data.map((session) => (
                                    <TableRow key={session.id}>
                                        <TableCell className="font-mono">{session.session_number}</TableCell>
                                        <TableCell>
                                            <Badge variant={statusVariant(session.status)}>
                                                {session.status === 'open' ? 'Open' : 'Closed'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>{session.cashier?.name ?? '—'}</TableCell>
                                        <TableCell>{session.hospital?.name ?? '—'}</TableCell>
                                        <TableCell className="text-right">{formatMoney(session.opening_balance, 'TZS')}</TableCell>
                                        <TableCell className="text-right">
                                            {session.variance !== null ? (
                                                <span className={Number(session.variance) !== 0 ? 'text-destructive font-medium' : ''}>
                                                    {formatMoney(session.variance, 'TZS')}
                                                </span>
                                            ) : (
                                                '—'
                                            )}
                                        </TableCell>
                                        <TableCell>{new Date(session.opened_at).toLocaleString()}</TableCell>
                                        <TableCell className="text-right">
                                            <Button asChild size="sm" variant="ghost">
                                                <Link href={route('billing.cashier.show', session.id)}>View</Link>
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <Pagination links={sessions.links} />
                    </>
                )}
            </div>
        </AppLayout>
    );
}
