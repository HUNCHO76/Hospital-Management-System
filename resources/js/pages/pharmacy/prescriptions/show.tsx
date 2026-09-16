import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import {
    prescriptionDurationUnitLabel,
    prescriptionFrequencyLabel,
    prescriptionOrderItemStatusLabel,
    prescriptionOrderStatusLabel,
    prescriptionOrderStatusVariant,
    prescriptionRouteLabel,
} from '@/lib/prescription-status';
import { type BreadcrumbItem } from '@/types';
import { type PrescriptionOrder } from '@/types/prescription';
import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, CircleDot, XCircle } from 'lucide-react';

interface PharmacyPrescriptionShowProps {
    prescription: PrescriptionOrder;
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * A single prescription's detail (Phase 6, Step 46) — the same order shown
 * inline on the clinical encounter page, reached from the Pharmacy
 * worklist. Mirrors RadiologyShow's structure exactly. Pharmacy-side
 * processing (verify/dispense) is not shown here — that is Steps 47-48's
 * job.
 */
export default function PharmacyPrescriptionShow({ prescription }: PharmacyPrescriptionShowProps) {
    const patientName = prescription.patient
        ? [prescription.patient.first_name, prescription.patient.middle_name, prescription.patient.last_name].filter(Boolean).join(' ')
        : '—';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Pharmacy', href: '/pharmacy' },
        { title: 'Prescriptions', href: '/pharmacy/prescriptions' },
        { title: prescription.prescription_number, href: `/pharmacy/prescriptions/${prescription.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Prescription ${prescription.prescription_number}`} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{prescription.prescription_number}</h1>
                        <p className="text-muted-foreground text-sm">{patientName}</p>
                    </div>
                    <Badge variant={prescriptionOrderStatusVariant[prescription.status]}>
                        {prescriptionOrderStatusLabel[prescription.status]}
                    </Badge>
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Prescription Details</CardTitle>
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
                                {prescription.clinical_notes && (
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Clinical Notes</dt>
                                        <dd className="text-right">{prescription.clinical_notes}</dd>
                                    </div>
                                )}
                                {prescription.status === 'cancelled' && (
                                    <>
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-muted-foreground">Cancelled At</dt>
                                            <dd>{prescription.cancelled_at ? formatDateTime(prescription.cancelled_at) : '—'}</dd>
                                        </div>
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-muted-foreground">Cancellation Reason</dt>
                                            <dd className="text-right">{prescription.cancellation_reason ?? '—'}</dd>
                                        </div>
                                    </>
                                )}
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Patient & Encounter</CardTitle>
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
                                    <dt className="text-muted-foreground">Department</dt>
                                    <dd>{prescription.department?.name ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Encounter</dt>
                                    <dd>
                                        {prescription.encounter ? (
                                            <Button asChild variant="link" size="sm" className="h-auto p-0">
                                                <Link href={route('clinical-encounters.show', prescription.encounter.id)}>
                                                    {prescription.encounter.encounter_number}
                                                </Link>
                                            </Button>
                                        ) : (
                                            '—'
                                        )}
                                    </dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Medications</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                            {prescription.items.map((item) => (
                                <li key={item.id} className="flex flex-wrap items-start justify-between gap-2 py-2 first:pt-0 last:pb-0">
                                    <div className={item.status === 'cancelled' ? 'text-muted-foreground line-through' : ''}>
                                        <p>
                                            {item.medication_name_snapshot}
                                            {item.strength_snapshot ? ` ${item.strength_snapshot}` : ''}
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            {[item.dose, item.dose_unit].filter(Boolean).join(' ')} · {prescriptionRouteLabel[item.route]} ·{' '}
                                            {prescriptionFrequencyLabel[item.frequency]}
                                            {item.duration
                                                ? ` · ${item.duration} ${item.duration_unit ? prescriptionDurationUnitLabel[item.duration_unit].toLowerCase() : ''}`
                                                : ''}
                                            {item.quantity ? ` · Qty: ${item.quantity}${item.quantity_unit ? ` ${item.quantity_unit}` : ''}` : ''}
                                        </p>
                                        {item.instructions && <p className="text-muted-foreground text-xs">{item.instructions}</p>}
                                    </div>

                                    <Badge variant={item.status === 'cancelled' ? 'destructive' : 'default'}>
                                        {prescriptionOrderItemStatusLabel[item.status]}
                                    </Badge>
                                </li>
                            ))}

                            {prescription.items.length === 0 && <p className="text-muted-foreground text-sm">No medications on this prescription.</p>}
                        </ul>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Timeline</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ol className="space-y-3 text-sm">
                            <li className="flex items-center gap-2">
                                <CircleDot className="text-muted-foreground h-4 w-4" />
                                <span>Created — {formatDateTime(prescription.created_at)}</span>
                            </li>
                            <li className="flex items-center gap-2">
                                <CheckCircle2 className="h-4 w-4 text-green-600" />
                                <span>Prescribed — {formatDateTime(prescription.prescribed_at)}</span>
                            </li>
                            {prescription.status === 'cancelled' && prescription.cancelled_at && (
                                <li className="flex items-center gap-2">
                                    <XCircle className="text-destructive h-4 w-4" />
                                    <span>Cancelled — {formatDateTime(prescription.cancelled_at)}</span>
                                </li>
                            )}
                        </ol>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
