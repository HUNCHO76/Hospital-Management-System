import { StatCard } from '@/components/dashboard/stat-card';
import { EmptyState } from '@/components/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { formatMoney, patientAccountStatusLabel, patientAccountStatusVariant } from '@/lib/billing-status';
import { type BreadcrumbItem } from '@/types';
import { type PatientAccount } from '@/types/billing';
import { Head, Link } from '@inertiajs/react';
import { BarChart3, ClipboardList, Clock, CreditCard, FileText, Receipt, RotateCcw, ShieldCheck, Tag, Users } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Billing', href: '/billing' }];

interface DashboardKPIs {
    totalBilled: number;
    totalCollected: number;
    totalReversals: number;
    totalRefunds: number;
    creditAdjustments: number;
    debitAdjustments: number;
    totalAdjustments: number;
    paymentsByMethod: Record<string, number>;
    invoicesByStatus: Record<string, number>;
    openCashierSessions: number;
    cashierSessionsToday: number;
}

interface BillingIndexProps {
    totalAccounts: number;
    pendingChargeCount: number;
    postedChargeTotal: number;
    currency: string;
    recentAccounts: PatientAccount[];
    kpis: DashboardKPIs;
    canViewReports: boolean;
    canViewCashier: boolean;
}

function patientName(account: PatientAccount): string {
    if (!account.patient) {
        return '—';
    }

    return [account.patient.first_name, account.patient.middle_name, account.patient.last_name].filter(Boolean).join(' ');
}

export default function BillingIndex({ totalAccounts, pendingChargeCount, postedChargeTotal, currency, recentAccounts, kpis, canViewReports, canViewCashier }: BillingIndexProps) {
    const { can } = usePermissions();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Billing" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Billing</h1>
                        <p className="text-muted-foreground text-sm">Patient financial accounts and charges.</p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button asChild variant="outline">
                            <Link href={route('billing.accounts.index')}>
                                <Users className="h-4 w-4" />
                                Accounts
                            </Link>
                        </Button>
                        {can('billing-services.view') && (
                            <Button asChild variant="outline">
                                <Link href={route('billing-services.index')}>
                                    <ClipboardList className="h-4 w-4" />
                                    Services
                                </Link>
                            </Button>
                        )}
                        {can('billing-pricing.view') && (
                            <Button asChild variant="outline">
                                <Link href={route('billing-pricing.index')}>
                                    <Tag className="h-4 w-4" />
                                    Pricing
                                </Link>
                            </Button>
                        )}
                        {can('billing-invoices.view') && (
                            <Button asChild variant="outline">
                                <Link href={route('billing.invoices.index')}>
                                    <FileText className="h-4 w-4" />
                                    Invoices
                                </Link>
                            </Button>
                        )}
                        {can('billing-insurance-rules.view') && (
                            <Button asChild variant="outline">
                                <Link href={route('billing-insurance-rules.index')}>
                                    <ShieldCheck className="h-4 w-4" />
                                    Insurance Rules
                                </Link>
                            </Button>
                        )}
                        {can('billing-payments.view') && (
                            <Button asChild variant="outline">
                                <Link href={route('billing.payments.index')}>
                                    <CreditCard className="h-4 w-4" />
                                    Payments
                                </Link>
                            </Button>
                        )}
                        {can('billing-payments.create') && (
                            <Button asChild>
                                <Link href={route('billing.payments.create')}>
                                    <CreditCard className="h-4 w-4" />
                                    Record Payment
                                </Link>
                            </Button>
                        )}
                        {can('billing-charges.create') && (
                            <Button asChild>
                                <Link href={route('billing.charges.create')}>
                                    <Receipt className="h-4 w-4" />
                                    Record Charge
                                </Link>
                            </Button>
                        )}
                        {canViewCashier && (
                            <Button asChild variant="outline">
                                <Link href={route('billing.cashier.index')}>
                                    <Receipt className="h-4 w-4" />
                                    Cashier
                                </Link>
                            </Button>
                        )}
                        {canViewReports && (
                            <Button asChild variant="outline">
                                <Link href={route('billing.reports.index')}>
                                    <BarChart3 className="h-4 w-4" />
                                    Reports
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Patient Accounts" value={totalAccounts} icon={Users} />
                    <StatCard label="Pending Charges" value={pendingChargeCount} icon={Clock} />
                    <StatCard label="Total Billed" value={formatMoney(kpis.totalBilled, currency)} icon={FileText} />
                    <StatCard label="Total Collected" value={formatMoney(kpis.totalCollected, currency)} icon={CreditCard} />
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Total Refunds" value={formatMoney(kpis.totalRefunds, currency)} icon={RotateCcw} />
                    <StatCard label="Total Reversals" value={formatMoney(kpis.totalReversals, currency)} icon={RotateCcw} />
                    <StatCard label="Credit Adjustments" value={formatMoney(kpis.creditAdjustments, currency)} icon={FileText} />
                    <StatCard label="Open Cashier Sessions" value={kpis.openCashierSessions} icon={Receipt} />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Recent Accounts</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Account #</TableHead>
                                    <TableHead>Patient</TableHead>
                                    <TableHead>Hospital</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Opened</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {recentAccounts.map((account) => (
                                    <TableRow key={account.id}>
                                        <TableCell>
                                            <Link href={route('billing.accounts.show', account.id)} className="hover:underline">
                                                {account.account_number}
                                            </Link>
                                        </TableCell>
                                        <TableCell>{patientName(account)}</TableCell>
                                        <TableCell>{account.hospital?.name ?? '—'}</TableCell>
                                        <TableCell>
                                            <Badge variant={patientAccountStatusVariant[account.status]}>
                                                {patientAccountStatusLabel[account.status]}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>{new Date(account.created_at).toLocaleDateString()}</TableCell>
                                    </TableRow>
                                ))}

                                {recentAccounts.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={5} className="p-0">
                                            <EmptyState
                                                icon={Users}
                                                title="No patient accounts yet"
                                                description="An account is opened automatically the first time a patient is charged."
                                            />
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
