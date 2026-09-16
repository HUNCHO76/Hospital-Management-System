import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatMoney, invoiceStatusLabel, invoiceStatusVariant } from '@/lib/billing-status';
import { allocationStatusLabel, allocationStatusVariant, coverageTypeLabel } from '@/lib/insurance-billing-status';
import { type BreadcrumbItem } from '@/types';
import { type Invoice } from '@/types/billing';
import { type CoverageEligibility } from '@/types/insurance-billing';
import { Head, Link, router } from '@inertiajs/react';
import { ShieldAlert, ShieldCheck } from 'lucide-react';

interface BillingInvoiceInsuranceProps {
    invoice: Invoice;
    eligibility: CoverageEligibility;
    canCalculate: boolean;
}

/**
 * An invoice's insurance/coverage breakdown (Phase 7, Step 56, spec §31) —
 * every figure here is server-calculated and persisted by
 * InsuranceBillingService::applyToInvoice(); nothing on this page computes
 * or trusts a coverage_percentage/covered_amount/patient_amount value of
 * its own (spec §33). Calculation is only offered while the invoice is
 * still DRAFT — once POSTED/VOIDED/CANCELLED, the breakdown shown here is
 * the final, frozen historical record (spec §28/§29).
 */
export default function BillingInvoiceInsurance({ invoice, eligibility, canCalculate }: BillingInvoiceInsuranceProps) {
    const patientName = invoice.patient
        ? [invoice.patient.first_name, invoice.patient.middle_name, invoice.patient.last_name].filter(Boolean).join(' ')
        : '—';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Billing', href: '/billing' },
        { title: 'Invoices', href: '/billing/invoices' },
        { title: invoice.invoice_number, href: `/billing/invoices/${invoice.id}` },
        { title: 'Insurance', href: `/billing/invoices/${invoice.id}/insurance` },
    ];

    const chargesWithAllocation = invoice.charges.filter((charge) => charge.insurance_allocation);
    const hasAnyAllocation = chargesWithAllocation.length > 0;

    const totals = chargesWithAllocation.reduce(
        (acc, charge) => {
            const allocation = charge.insurance_allocation!;

            return {
                eligible: acc.eligible + Number(allocation.eligible_amount),
                covered: acc.covered + Number(allocation.covered_amount),
                patient: acc.patient + Number(allocation.patient_responsibility),
            };
        },
        { eligible: 0, covered: 0, patient: 0 },
    );

    const calculate = () => {
        router.post(route('billing.invoices.insurance.calculate', invoice.id), {}, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${invoice.invoice_number} — Insurance`} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Insurance & Coverage</h1>
                        <p className="text-muted-foreground text-sm">
                            {invoice.invoice_number} · {patientName} · {invoice.hospital?.name ?? '—'}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Badge variant={invoiceStatusVariant[invoice.status]}>{invoiceStatusLabel[invoice.status]}</Badge>
                        <Button asChild variant="outline">
                            <Link href={route('billing.invoices.show', invoice.id)}>Back to Invoice</Link>
                        </Button>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Coverage Eligibility</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="flex items-center gap-2">
                            {eligibility.eligible ? (
                                <Badge variant="default" className="gap-1">
                                    <ShieldCheck className="h-3.5 w-3.5" />
                                    Eligible
                                </Badge>
                            ) : (
                                <Badge variant="destructive" className="gap-1">
                                    <ShieldAlert className="h-3.5 w-3.5" />
                                    Not Eligible
                                </Badge>
                            )}
                            {eligibility.reason && <span className="text-muted-foreground text-sm">{eligibility.reason}</span>}
                        </div>

                        {eligibility.coverage && (
                            <dl className="grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-4">
                                <div>
                                    <dt className="text-muted-foreground">Membership #</dt>
                                    <dd>{eligibility.coverage.membership_number ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">Coverage Status</dt>
                                    <dd className="capitalize">{eligibility.coverage.status}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">Verification</dt>
                                    <dd className="capitalize">{eligibility.coverage.verification_status}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">Effective</dt>
                                    <dd>
                                        {eligibility.coverage.effective_from ?? '—'} – {eligibility.coverage.effective_to ?? 'ongoing'}
                                    </dd>
                                </div>
                            </dl>
                        )}
                    </CardContent>
                </Card>

                {invoice.status === 'draft' && canCalculate && (
                    <Card>
                        <CardContent className="flex flex-wrap items-center justify-between gap-4 p-5">
                            <p className="text-muted-foreground text-sm">
                                {hasAnyAllocation
                                    ? 'Recalculating replaces the coverage breakdown below for every charge on this invoice.'
                                    : 'Calculate this invoice’s insurance coverage against the patient’s primary coverage on file.'}
                            </p>
                            <Button type="button" onClick={calculate}>
                                {hasAnyAllocation ? 'Recalculate Coverage' : 'Calculate Coverage'}
                            </Button>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Coverage Breakdown</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Charge</TableHead>
                                    <TableHead>Coverage</TableHead>
                                    <TableHead className="text-right">Eligible</TableHead>
                                    <TableHead className="text-right">Insurance</TableHead>
                                    <TableHead className="text-right">Patient</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {invoice.charges.map((charge) => {
                                    const allocation = charge.insurance_allocation;

                                    return (
                                        <TableRow key={charge.id}>
                                            <TableCell>
                                                <div className="flex flex-col">
                                                    <span>{charge.description}</span>
                                                    <span className="text-muted-foreground text-xs">{charge.charge_number}</span>
                                                </div>
                                            </TableCell>
                                            {allocation ? (
                                                <>
                                                    <TableCell>
                                                        {allocation.coverage_type_applied ? (
                                                            <div className="flex flex-col">
                                                                <span>{coverageTypeLabel[allocation.coverage_type_applied]}</span>
                                                                {allocation.coverage_percentage_applied && (
                                                                    <span className="text-muted-foreground text-xs">
                                                                        {allocation.coverage_percentage_applied}%
                                                                    </span>
                                                                )}
                                                                {allocation.insurance_coverage_rule?.requires_preauthorization && (
                                                                    <span className="text-xs text-amber-600">Requires preauthorization</span>
                                                                )}
                                                            </div>
                                                        ) : (
                                                            <span className="text-muted-foreground">—</span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        {formatMoney(allocation.eligible_amount, allocation.currency)}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        {formatMoney(allocation.covered_amount, allocation.currency)}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        {formatMoney(allocation.patient_responsibility, allocation.currency)}
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex flex-col gap-1">
                                                            <Badge variant={allocationStatusVariant[allocation.status]}>
                                                                {allocationStatusLabel[allocation.status]}
                                                            </Badge>
                                                            {allocation.rejection_reason && (
                                                                <span className="text-muted-foreground text-xs">{allocation.rejection_reason}</span>
                                                            )}
                                                        </div>
                                                    </TableCell>
                                                </>
                                            ) : (
                                                <TableCell colSpan={5} className="text-muted-foreground text-sm">
                                                    Not yet calculated.
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    );
                                })}

                                {invoice.charges.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-muted-foreground py-6 text-center text-sm">
                                            No charges on this invoice yet.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>

                        {hasAnyAllocation && (
                            <div className="mt-4 flex flex-wrap justify-end gap-6 border-t pt-4 text-sm">
                                <div>
                                    <span className="text-muted-foreground">Total Eligible: </span>
                                    <span className="font-medium">{formatMoney(totals.eligible, invoice.currency)}</span>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Insurance Portion: </span>
                                    <span className="font-medium">{formatMoney(totals.covered, invoice.currency)}</span>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Patient Responsibility: </span>
                                    <span className="font-medium">{formatMoney(totals.patient, invoice.currency)}</span>
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
