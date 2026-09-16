import { PatientSearch } from '@/components/billing/patient-search';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/billing-status';
import { invoicePaymentStatusLabel, invoicePaymentStatusVariant, paymentMethodLabel } from '@/lib/payment-status';
import { type BreadcrumbItem } from '@/types';
import { type PatientSearchResult } from '@/types/billing';
import { type CreatePaymentFormData, type EligibleInvoice, type PaymentMethod } from '@/types/payment';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing', href: '/billing' },
    { title: 'Record Payment', href: '/billing/payments/create' },
];

interface BillingPaymentCreateProps {
    initialPatient: PatientSearchResult | null;
    eligibleInvoices: EligibleInvoice[];
    methods: PaymentMethod[];
    methodsRequiringReference: PaymentMethod[];
}

/**
 * Record a patient's payment against one of their own posted invoices
 * (Phase 7, Step 57, spec §57.26). Every figure shown here (invoice total,
 * insurance responsibility, patient responsibility, already paid,
 * outstanding) is server-calculated by PaymentService::calculateOutstanding()
 * and delivered already-embedded in `eligibleInvoices` — this page never
 * computes or overrides any of them; the amount is re-validated against the
 * same calculation, under a lock, at submit time regardless of what is
 * displayed here.
 */
export default function BillingPaymentCreate({ initialPatient, eligibleInvoices, methods, methodsRequiringReference }: BillingPaymentCreateProps) {
    const [selectedPatient, setSelectedPatient] = useState<PatientSearchResult | null>(initialPatient);

    const { data, setData, post, processing, errors } = useForm<CreatePaymentFormData>({
        invoice_id: '',
        amount: '',
        payment_date: new Date().toISOString().slice(0, 10),
        payment_method: '',
        reference_number: '',
        notes: '',
    });

    const selectedInvoice = useMemo(
        () => eligibleInvoices.find((row) => String(row.invoice.id) === data.invoice_id) ?? null,
        [eligibleInvoices, data.invoice_id],
    );

    const referenceRequired = methodsRequiringReference.includes(data.payment_method as PaymentMethod);

    const selectPatient = (patient: PatientSearchResult) => {
        setSelectedPatient(patient);
        setData('invoice_id', '');
        router.get(route('billing.payments.create'), { patient_id: patient.id }, { preserveState: true, replace: true });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('billing.payments.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Record Payment" />

            <div className="p-4">
                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Record Payment</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-6">
                            <div className="grid gap-2">
                                <Label>Patient *</Label>
                                <PatientSearch selected={selectedPatient} onSelect={selectPatient} />
                            </div>

                            {selectedPatient && eligibleInvoices.length === 0 && (
                                <p className="text-muted-foreground text-sm">
                                    This patient has no posted invoice with an outstanding patient balance.
                                </p>
                            )}

                            {eligibleInvoices.length > 0 && (
                                <div className="grid gap-2">
                                    <Label>Invoice *</Label>
                                    <Select value={data.invoice_id} onValueChange={(value) => setData('invoice_id', value)}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select an invoice" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {eligibleInvoices.map((row) => (
                                                <SelectItem key={row.invoice.id} value={String(row.invoice.id)}>
                                                    {row.invoice.invoice_number} — Outstanding{' '}
                                                    {formatMoney(row.outstanding.patient_outstanding, row.invoice.currency)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.invoice_id} />
                                </div>
                            )}

                            {selectedInvoice && (
                                <Card className="bg-muted/40">
                                    <CardContent className="grid grid-cols-2 gap-3 p-4 text-sm sm:grid-cols-3">
                                        <div>
                                            <p className="text-muted-foreground">Invoice Total</p>
                                            <p className="font-medium">
                                                {formatMoney(selectedInvoice.outstanding.invoice_total, selectedInvoice.invoice.currency)}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground">Insurance Responsibility</p>
                                            <p className="font-medium">
                                                {formatMoney(selectedInvoice.outstanding.insurance_responsibility, selectedInvoice.invoice.currency)}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground">Patient Responsibility</p>
                                            <p className="font-medium">
                                                {formatMoney(selectedInvoice.outstanding.patient_responsibility, selectedInvoice.invoice.currency)}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground">Already Paid</p>
                                            <p className="font-medium">
                                                {formatMoney(selectedInvoice.outstanding.patient_paid, selectedInvoice.invoice.currency)}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground">Outstanding</p>
                                            <p className="font-semibold">
                                                {formatMoney(selectedInvoice.outstanding.patient_outstanding, selectedInvoice.invoice.currency)}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground">Status</p>
                                            <Badge variant={invoicePaymentStatusVariant[selectedInvoice.outstanding.status]}>
                                                {invoicePaymentStatusLabel[selectedInvoice.outstanding.status]}
                                            </Badge>
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="amount">Amount *</Label>
                                    <Input
                                        id="amount"
                                        type="number"
                                        min={0}
                                        step="0.01"
                                        value={data.amount}
                                        onChange={(e) => setData('amount', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.amount} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="payment_date">Payment Date *</Label>
                                    <Input
                                        id="payment_date"
                                        type="date"
                                        max={new Date().toISOString().slice(0, 10)}
                                        value={data.payment_date}
                                        onChange={(e) => setData('payment_date', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.payment_date} />
                                </div>

                                <div className="grid gap-2">
                                    <Label>Payment Method *</Label>
                                    <Select
                                        value={data.payment_method}
                                        onValueChange={(value) => setData('payment_method', value as PaymentMethod)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select method" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {methods.map((method) => (
                                                <SelectItem key={method} value={method}>
                                                    {paymentMethodLabel[method]}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.payment_method} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="reference_number">Reference Number {referenceRequired && '*'}</Label>
                                    <Input
                                        id="reference_number"
                                        value={data.reference_number}
                                        onChange={(e) => setData('reference_number', e.target.value)}
                                        required={referenceRequired}
                                    />
                                    <InputError message={errors.reference_number} />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="notes">Notes</Label>
                                <Textarea id="notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                                <InputError message={errors.notes} />
                            </div>

                            <div className="flex justify-end gap-2">
                                <Button type="button" variant="outline" asChild>
                                    <Link href={route('billing.payments.index')}>Cancel</Link>
                                </Button>
                                <Button type="submit" disabled={processing || !data.invoice_id}>
                                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                    Record Payment
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
