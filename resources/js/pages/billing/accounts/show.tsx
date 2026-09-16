import { PostChargeButton } from '@/components/billing/post-charge-button';
import { VoidChargeDialog } from '@/components/billing/void-charge-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { chargeStatusLabel, chargeStatusVariant, formatMoney, patientAccountStatusLabel, patientAccountStatusVariant } from '@/lib/billing-status';
import { type BreadcrumbItem } from '@/types';
import { type Charge, type PatientAccount } from '@/types/billing';
import { Head, Link } from '@inertiajs/react';
import { FileText, Receipt } from 'lucide-react';

interface BillingAccountShowProps {
    account: PatientAccount;
    balance: number;
    pendingTotal: number;
    charges: Charge[];
    canCreateCharge: boolean;
    canManageCharges: boolean;
    canCreateInvoice: boolean;
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

/**
 * A single patient account's detail page (Phase 7, Step 52, spec §36-37) —
 * balance (computed live, never fabricated) plus the account's full
 * financial timeline. No invoice/payment/refund/adjustment sections: none
 * of those entities exist yet in this foundation (spec §36's own "show only
 * information that exists" instruction).
 */
export default function BillingAccountShow({
    account,
    balance,
    pendingTotal,
    charges,
    canCreateCharge,
    canManageCharges,
    canCreateInvoice,
}: BillingAccountShowProps) {
    const patientName = account.patient
        ? [account.patient.first_name, account.patient.middle_name, account.patient.last_name].filter(Boolean).join(' ')
        : '—';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Billing', href: '/billing' },
        { title: 'Accounts', href: '/billing/accounts' },
        { title: account.account_number, href: `/billing/accounts/${account.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={account.account_number} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{account.account_number}</h1>
                        <p className="text-muted-foreground text-sm">
                            {patientName}
                            {account.patient?.patient_number ? ` — ${account.patient.patient_number}` : ''} · {account.hospital?.name ?? '—'}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Badge variant={patientAccountStatusVariant[account.status]}>{patientAccountStatusLabel[account.status]}</Badge>
                        {canCreateInvoice && (
                            <Button asChild variant="outline">
                                <Link href={route('billing.invoices.create', { patient_account_id: account.id })}>
                                    <FileText className="h-4 w-4" />
                                    Create Invoice
                                </Link>
                            </Button>
                        )}
                        {canCreateCharge && (
                            <Button asChild>
                                <Link href={route('billing.charges.create', { patient_id: account.patient?.id, hospital_id: account.hospital?.id })}>
                                    <Receipt className="h-4 w-4" />
                                    Record Charge
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-sm font-medium">Current Balance</p>
                            <p className="text-2xl font-semibold tracking-tight">{formatMoney(balance, account.currency)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-5">
                            <p className="text-muted-foreground text-sm font-medium">Pending (Not Yet Posted)</p>
                            <p className="text-2xl font-semibold tracking-tight">{formatMoney(pendingTotal, account.currency)}</p>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Charges</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Description</TableHead>
                                    <TableHead className="text-right">Qty</TableHead>
                                    <TableHead className="text-right">Unit Price</TableHead>
                                    <TableHead className="text-right">Net Amount</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Charged</TableHead>
                                    {canManageCharges && <TableHead className="text-right">Actions</TableHead>}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {charges.map((charge) => (
                                    <TableRow key={charge.id}>
                                        <TableCell>
                                            <div className="flex flex-col">
                                                <div className="flex items-center gap-2">
                                                    <span>{charge.description}</span>
                                                    <Badge variant={charge.source_type ? 'secondary' : 'outline'} className="text-xs">
                                                        {charge.source_type ? 'Auto' : 'Manual'}
                                                    </Badge>
                                                </div>
                                                <span className="text-muted-foreground text-xs">
                                                    {charge.charge_number}
                                                    {charge.encounter && ` · ${charge.encounter.encounter_number}`}
                                                </span>
                                                {charge.notes && <span className="text-muted-foreground text-xs italic">{charge.notes}</span>}
                                                {charge.invoice_id && (
                                                    <Link
                                                        href={route('billing.invoices.show', charge.invoice_id)}
                                                        className="text-xs text-blue-600 hover:underline dark:text-blue-400"
                                                    >
                                                        View invoice
                                                    </Link>
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right">{charge.quantity}</TableCell>
                                        <TableCell className="text-right">{formatMoney(charge.unit_price, charge.currency)}</TableCell>
                                        <TableCell className="text-right">{formatMoney(charge.net_amount, charge.currency)}</TableCell>
                                        <TableCell>
                                            <Badge variant={chargeStatusVariant[charge.status]}>{chargeStatusLabel[charge.status]}</Badge>
                                        </TableCell>
                                        <TableCell>{formatDateTime(charge.charged_at)}</TableCell>
                                        {canManageCharges && (
                                            <TableCell className="text-right">
                                                <div className="flex justify-end gap-2">
                                                    {charge.status === 'pending' && (
                                                        <>
                                                            <PostChargeButton chargeId={charge.id} />
                                                            <VoidChargeDialog
                                                                chargeId={charge.id}
                                                                mode="void"
                                                                trigger={
                                                                    <Button size="sm" variant="ghost">
                                                                        Void
                                                                    </Button>
                                                                }
                                                            />
                                                        </>
                                                    )}
                                                    {charge.status === 'posted' && (
                                                        <VoidChargeDialog
                                                            chargeId={charge.id}
                                                            mode="cancel"
                                                            trigger={
                                                                <Button size="sm" variant="ghost">
                                                                    Cancel
                                                                </Button>
                                                            }
                                                        />
                                                    )}
                                                </div>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}

                                {charges.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={canManageCharges ? 7 : 6} className="text-muted-foreground py-6 text-center text-sm">
                                            No charges recorded yet.
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
