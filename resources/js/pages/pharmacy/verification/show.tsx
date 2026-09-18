import { ReturnPrescriptionDialog } from '@/components/pharmacy/return-prescription-dialog';
import { VerifyPrescriptionDialog } from '@/components/pharmacy/verify-prescription-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import {
    prescriptionDurationUnitLabel,
    prescriptionFrequencyLabel,
    prescriptionRouteLabel,
    prescriptionVerificationFlagLabel,
    prescriptionVerificationStatusLabel,
    prescriptionVerificationStatusVariant,
} from '@/lib/prescription-status';
import { type BreadcrumbItem } from '@/types';
import { type PrescriptionVerificationOrder } from '@/types/prescription';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, RotateCcw } from 'lucide-react';

interface PharmacyVerificationShowProps {
    prescription: PrescriptionVerificationOrder;
    canVerify: boolean;
    canReturn: boolean;
    canResubmit: boolean;
    prescriptionFinancialStatus?: string;
    patientAccountId?: number | null;
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

/**
 * Prescription verification/review page (Phase 6, Step 47, spec §11/§22):
 * the complete context a verifier needs — patient/encounter/prescriber and
 * every medication item, read-only here (a verifier reviews, never
 * silently edits, the doctor's prescription — spec §3). Verify/Return only
 * render when the review is actually PENDING_VERIFICATION and this user is
 * independent of the prescriber (`canVerify`/`canReturn`, computed
 * server-side from PrescriptionOrderPolicy); the backend re-checks on every
 * write regardless. Resubmit renders only for the prescriber themselves
 * once RETURNED (`canResubmit`), after they have corrected the order
 * through the ordinary encounter-page edit flow.
 */
export default function PharmacyVerificationShow({
    prescription,
    canVerify,
    canReturn,
    canResubmit,
    prescriptionFinancialStatus = 'authorized',
    patientAccountId = null,
}: PharmacyVerificationShowProps) {
    const isPaymentRequired = prescriptionFinancialStatus === 'payment_required';
    const patientName = prescription.patient
        ? [prescription.patient.first_name, prescription.patient.middle_name, prescription.patient.last_name].filter(Boolean).join(' ')
        : '—';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Pharmacy', href: '/pharmacy' },
        { title: 'Verification', href: '/pharmacy/prescriptions/verification' },
        { title: prescription.prescription_number, href: `/pharmacy/prescriptions/${prescription.id}/verification` },
    ];

    const status = prescription.verification?.status;
    const isActionable = status === 'pending_verification';

    const resubmit = () => {
        router.post(route('pharmacy-verification.resubmit', prescription.id), {}, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Verify ${prescription.prescription_number}`} />

            <div className="flex flex-col gap-6 p-4">
                {isPaymentRequired && (
                    <div className="rounded-md border border-amber-500/50 bg-amber-500/10 p-4 text-amber-800 dark:text-amber-300">
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <div className="space-y-1">
                                <div className="flex items-center gap-2 text-sm font-bold">
                                    <span>Financial Status: PAYMENT REQUIRED</span>
                                    <Badge variant="destructive" className="bg-amber-600 text-white dark:bg-amber-700">
                                        PAYMENT REQUIRED
                                    </Badge>
                                </div>
                                <p className="text-muted-foreground text-xs">
                                    Prescription verification is blocked until the charges are settled at the cashier.
                                </p>
                            </div>
                            {patientAccountId && (
                                <Button size="sm" variant="default" className="bg-amber-700 text-white hover:bg-amber-800" asChild>
                                    <Link href={route('billing.accounts.show', patientAccountId)}>
                                        View Bill / Proceed to Payment
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </div>
                )}

                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{prescription.prescription_number}</h1>
                        <p className="text-muted-foreground text-sm">
                            {patientName} — {prescription.prescriber?.name ?? 'Unknown prescriber'}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        {status && (
                            <Badge variant={prescriptionVerificationStatusVariant[status]}>{prescriptionVerificationStatusLabel[status]}</Badge>
                        )}
                        {status === 'returned' && canResubmit && (
                            <Button variant="outline" onClick={resubmit}>
                                <RotateCcw className="h-4 w-4" />
                                Resubmit for Verification
                            </Button>
                        )}
                        {isActionable && canReturn && (
                            <ReturnPrescriptionDialog
                                prescriptionOrderId={prescription.id}
                                trigger={<Button variant="destructive">Return for Correction</Button>}
                            />
                        )}
                        {isActionable && canVerify && (
                            <VerifyPrescriptionDialog
                                prescriptionOrderId={prescription.id}
                                trigger={
                                    <Button disabled={isPaymentRequired} title={isPaymentRequired ? 'Payment required before verification' : undefined}>
                                        Verify Prescription
                                    </Button>
                                }
                            />
                        )}
                    </div>
                </div>

                {status === 'returned' && (
                    <Card className="border-destructive/50">
                        <CardHeader>
                            <CardTitle className="text-base">Returned for Correction</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Returned By</dt>
                                    <dd>{prescription.verification?.returned_by?.name ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Returned At</dt>
                                    <dd>{formatDateTime(prescription.verification?.returned_at ?? null)}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground mb-1">Reason</dt>
                                    <dd className="whitespace-pre-wrap">{prescription.verification?.return_reason || '—'}</dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>
                )}

                {status === 'verified' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Verified</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Verified By</dt>
                                    <dd>{prescription.verification?.verified_by?.name ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Verified At</dt>
                                    <dd>{formatDateTime(prescription.verification?.verified_at ?? null)}</dd>
                                </div>
                                {prescription.verification?.verification_notes && (
                                    <div>
                                        <dt className="text-muted-foreground mb-1">Verification Notes</dt>
                                        <dd className="whitespace-pre-wrap">{prescription.verification.verification_notes}</dd>
                                    </div>
                                )}
                            </dl>
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Patient &amp; Encounter</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Patient</dt>
                                    <dd>
                                        {prescription.patient ? (
                                            <Link href={route('patients.show', prescription.patient.id)} className="font-medium hover:underline">
                                                {patientName}
                                            </Link>
                                        ) : (
                                            '—'
                                        )}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Patient Number</dt>
                                    <dd>{prescription.patient?.patient_number ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Date of Birth</dt>
                                    <dd>{prescription.patient?.date_of_birth ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Sex</dt>
                                    <dd>{prescription.patient?.gender ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Encounter</dt>
                                    <dd>
                                        {prescription.encounter ? (
                                            <Link href={route('clinical-encounters.show', prescription.encounter.id)} className="hover:underline">
                                                {prescription.encounter.encounter_number}
                                            </Link>
                                        ) : (
                                            '—'
                                        )}
                                    </dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Prescription</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Prescriber</dt>
                                    <dd>{prescription.prescriber?.name ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Prescribed At</dt>
                                    <dd>{formatDateTime(prescription.prescribed_at)}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Submitted At</dt>
                                    <dd>{formatDateTime(prescription.verification?.submitted_at ?? null)}</dd>
                                </div>
                                {prescription.clinical_notes && (
                                    <div>
                                        <dt className="text-muted-foreground mb-1">Clinical Notes</dt>
                                        <dd className="whitespace-pre-wrap">{prescription.clinical_notes}</dd>
                                    </div>
                                )}
                            </dl>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Medications</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Medication</TableHead>
                                    <TableHead>Dose</TableHead>
                                    <TableHead>Frequency</TableHead>
                                    <TableHead>Route</TableHead>
                                    <TableHead>Duration</TableHead>
                                    <TableHead>Quantity</TableHead>
                                    <TableHead>Instructions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {prescription.items.map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell>
                                            <div className={item.status === 'cancelled' ? 'text-muted-foreground line-through' : ''}>
                                                <p>
                                                    {item.medication_name_snapshot}
                                                    {item.strength_snapshot ? ` ${item.strength_snapshot}` : ''}
                                                </p>
                                                <p className="text-muted-foreground text-xs">{item.medication_code_snapshot}</p>
                                            </div>
                                            {item.flags.length > 0 && (
                                                <div className="mt-1 flex flex-col gap-1">
                                                    {item.flags.map((flag) => (
                                                        <span key={flag} className="text-destructive flex items-center gap-1 text-xs">
                                                            <AlertTriangle className="h-3 w-3" />
                                                            {prescriptionVerificationFlagLabel[flag]}
                                                        </span>
                                                    ))}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell>{[item.dose, item.dose_unit].filter(Boolean).join(' ')}</TableCell>
                                        <TableCell>{prescriptionFrequencyLabel[item.frequency]}</TableCell>
                                        <TableCell>{prescriptionRouteLabel[item.route]}</TableCell>
                                        <TableCell>
                                            {item.duration ? `${item.duration} ${item.duration_unit ? prescriptionDurationUnitLabel[item.duration_unit].toLowerCase() : ''}` : '—'}
                                        </TableCell>
                                        <TableCell>
                                            {item.quantity}
                                            {item.quantity_unit ? ` ${item.quantity_unit}` : ''}
                                        </TableCell>
                                        <TableCell>{item.instructions || '—'}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
