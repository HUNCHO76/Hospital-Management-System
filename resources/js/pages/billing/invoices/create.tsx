import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/billing-status';
import { type BreadcrumbItem } from '@/types';
import { type Charge, type CreateInvoiceFormData } from '@/types/billing';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, useMemo } from 'react';

interface InvoiceCreateAccount {
    id: number;
    account_number: string;
    currency: string;
    patient: { id: number; patient_number: string; first_name: string; middle_name: string | null; last_name: string } | null;
    hospital: { id: number; name: string } | null;
}

interface BillingInvoiceCreateProps {
    account: InvoiceCreateAccount;
    eligibleCharges: Charge[];
}

/**
 * The invoice builder (Phase 7, Step 55, spec §16/§27) — reached from a
 * patient account, offers only that account's currently eligible (POSTED,
 * not-yet-invoiced) charges. This list is a convenience only: the backend
 * re-validates every selected charge under a lock regardless
 * (InvoiceService::addCharge(), spec §16's own "never rely on the frontend
 * list"), so a charge that becomes ineligible between page load and submit
 * is simply rejected, never silently included.
 */
export default function BillingInvoiceCreate({ account, eligibleCharges }: BillingInvoiceCreateProps) {
    const patientName = account.patient
        ? [account.patient.first_name, account.patient.middle_name, account.patient.last_name].filter(Boolean).join(' ')
        : '—';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Billing', href: '/billing' },
        { title: 'Accounts', href: '/billing/accounts' },
        { title: account.account_number, href: `/billing/accounts/${account.id}` },
        { title: 'Create Invoice', href: `/billing/invoices/create?patient_account_id=${account.id}` },
    ];

    const { data, setData, post, processing, errors } = useForm<CreateInvoiceFormData>({
        patient_account_id: String(account.id),
        charge_ids: [],
        notes: '',
    });

    const selectedTotal = useMemo(
        () =>
            eligibleCharges
                .filter((charge) => data.charge_ids.includes(charge.id))
                .reduce((sum, charge) => sum + Number(charge.net_amount), 0),
        [eligibleCharges, data.charge_ids],
    );

    const toggleCharge = (chargeId: number, checked: boolean) => {
        setData('charge_ids', checked ? [...data.charge_ids, chargeId] : data.charge_ids.filter((id) => id !== chargeId));
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('billing.invoices.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create Invoice" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Create Invoice</h1>
                    <p className="text-muted-foreground text-sm">
                        {patientName}
                        {account.patient?.patient_number ? ` — ${account.patient.patient_number}` : ''} · {account.account_number} ·{' '}
                        {account.hospital?.name ?? '—'}
                    </p>
                </div>

                <form onSubmit={submit} className="flex flex-col gap-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Eligible Charges</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-10" />
                                        <TableHead>Description</TableHead>
                                        <TableHead className="text-right">Qty</TableHead>
                                        <TableHead className="text-right">Net Amount</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {eligibleCharges.map((charge) => (
                                        <TableRow key={charge.id}>
                                            <TableCell>
                                                <Checkbox
                                                    checked={data.charge_ids.includes(charge.id)}
                                                    onCheckedChange={(checked) => toggleCharge(charge.id, checked === true)}
                                                    aria-label={`Select charge ${charge.charge_number}`}
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-col">
                                                    <span>{charge.description}</span>
                                                    <span className="text-muted-foreground text-xs">{charge.charge_number}</span>
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-right">{charge.quantity}</TableCell>
                                            <TableCell className="text-right">{formatMoney(charge.net_amount, charge.currency)}</TableCell>
                                        </TableRow>
                                    ))}

                                    {eligibleCharges.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={4} className="text-muted-foreground py-6 text-center text-sm">
                                                No posted, uninvoiced charges are available on this account.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                            <InputError message={errors.charge_ids} />

                            {eligibleCharges.length > 0 && (
                                <div className="mt-4 flex justify-end">
                                    <p className="text-sm font-medium">
                                        Selected total: {formatMoney(selectedTotal, account.currency)}
                                        <span className="text-muted-foreground ml-1 font-normal">(server-recalculated on save)</span>
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="pt-6">
                            <div className="grid gap-2">
                                <Label htmlFor="notes">Notes</Label>
                                <Textarea
                                    id="notes"
                                    placeholder="Optional notes for this invoice"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                />
                                <InputError message={errors.notes} />
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" asChild>
                            <Link href={route('billing.accounts.show', account.id)}>Cancel</Link>
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Create Draft Invoice
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
