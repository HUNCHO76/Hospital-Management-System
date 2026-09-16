import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { patientAccountStatusLabel, patientAccountStatusVariant } from '@/lib/billing-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type BillingAccountFilters, type PatientAccount } from '@/types/billing';
import { Head, Link, router } from '@inertiajs/react';
import { Users } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing', href: '/billing' },
    { title: 'Accounts', href: '/billing/accounts' },
];

interface BillingAccountsIndexProps {
    accounts: Paginated<PatientAccount>;
    filters: BillingAccountFilters;
}

function patientName(account: PatientAccount): string {
    if (!account.patient) {
        return '—';
    }

    return [account.patient.first_name, account.patient.middle_name, account.patient.last_name].filter(Boolean).join(' ');
}

/**
 * The searchable patient-account list (Phase 7, Step 52) — every filter is
 * applied server-side by BillingSearchService::accountsQuery(), mirroring
 * StockItemsIndex/DispensingIndex's own shape exactly.
 */
export default function BillingAccountsIndex({ accounts, filters }: BillingAccountsIndexProps) {
    const applyFilters = (overrides: Partial<BillingAccountFilters>) => {
        const params: Record<string, string> = { ...filters, ...overrides };
        router.get(route('billing.accounts.index'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(filters.search || filters.status);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Patient Accounts" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Patient Accounts</h1>
                    <p className="text-muted-foreground text-sm">Every patient financial account across this facility.</p>
                </div>

                <div className="flex flex-col gap-3">
                    <Input
                        value={filters.search || ''}
                        onChange={(e) => applyFilters({ search: e.target.value })}
                        placeholder="Search by patient name, number, or account number"
                        aria-label="Search patient accounts"
                        className="max-w-sm"
                    />

                    <div className="flex flex-wrap items-center gap-2">
                        <Select value={filters.status || 'all'} onValueChange={(value) => applyFilters({ status: value === 'all' ? '' : value })}>
                            <SelectTrigger className="w-[180px]">
                                <SelectValue placeholder="All statuses" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All statuses</SelectItem>
                                <SelectItem value="active">Active</SelectItem>
                                <SelectItem value="suspended">Suspended</SelectItem>
                                <SelectItem value="closed">Closed</SelectItem>
                            </SelectContent>
                        </Select>

                        {hasFilters && (
                            <Button variant="ghost" size="sm" onClick={() => router.get(route('billing.accounts.index'))}>
                                Clear filters
                            </Button>
                        )}
                    </div>
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Account #</TableHead>
                            <TableHead>Patient</TableHead>
                            <TableHead>Hospital</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Opened</TableHead>
                            <TableHead className="text-right">Action</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {accounts.data.map((account) => (
                            <TableRow key={account.id}>
                                <TableCell>{account.account_number}</TableCell>
                                <TableCell>
                                    <div className="flex flex-col">
                                        <span>{patientName(account)}</span>
                                        {account.patient && <span className="text-muted-foreground text-xs">{account.patient.patient_number}</span>}
                                    </div>
                                </TableCell>
                                <TableCell>{account.hospital?.name ?? '—'}</TableCell>
                                <TableCell>
                                    <Badge variant={patientAccountStatusVariant[account.status]}>{patientAccountStatusLabel[account.status]}</Badge>
                                </TableCell>
                                <TableCell>{new Date(account.created_at).toLocaleDateString()}</TableCell>
                                <TableCell className="text-right">
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={route('billing.accounts.show', account.id)}>View</Link>
                                    </Button>
                                </TableCell>
                            </TableRow>
                        ))}

                        {accounts.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={6} className="p-0">
                                    <EmptyState
                                        icon={Users}
                                        title={hasFilters ? 'No accounts match your search or filters' : 'No patient accounts yet'}
                                        description={
                                            hasFilters
                                                ? 'Try a different search term or clear your filters.'
                                                : 'An account is opened automatically the first time a patient is charged.'
                                        }
                                    />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>

                <Pagination links={accounts.links} />
            </div>
        </AppLayout>
    );
}
