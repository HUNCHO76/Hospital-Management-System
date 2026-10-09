import { CancelDispensingDialog } from '@/components/pharmacy/cancel-dispensing-dialog';
import { CompleteDispensingDialog } from '@/components/pharmacy/complete-dispensing-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { dispensingItemStatusLabel, dispensingStatusLabel, dispensingStatusVariant, fulfillmentStatusLabel, fulfillmentStatusVariant } from '@/lib/dispensing-status';
import { prescriptionDurationUnitLabel, prescriptionFrequencyLabel, prescriptionOrderStatusLabel, prescriptionOrderStatusVariant, prescriptionRouteLabel } from '@/lib/prescription-status';
import { NhifClaimFinalizeDialog } from '@/components/nhif/nhif-claim-finalize-dialog';
import { NhifUtilizationStatus } from '@/components/nhif/nhif-utilization-status';
import { type BreadcrumbItem } from '@/types';
import { type DispensingItemStatus, type DispensingPrescription } from '@/types/dispensing';
import { type NhifUtilization } from '@/types/nhif';
import { Head, Link, router } from '@inertiajs/react';
import { PlayCircle } from 'lucide-react';
import { useMemo, useState } from 'react';

interface PharmacyDispensingShowProps {
    prescription: DispensingPrescription;
    canStart: boolean;
    canComplete: boolean;
    canCancel: boolean;
    nhifUtilizations?: NhifUtilization[];
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

interface DraftLine {
    dispensed_quantity: string;
    status: DispensingItemStatus;
}

/**
 * Pharmacy dispensing detail page (Phase 6, Step 48, spec §13/§30): patient
 * identity, the verified prescription's full clinical context, and each
 * medication's prescribed/previously-dispensed/remaining quantities. When a
 * dispensing event is in progress, an authorized pharmacist enters the
 * quantity being dispensed now for each medication (never more than what
 * remains — even though the frontend blocks it here, DispensingService::
 * complete() re-validates every quantity against the live remaining amount
 * regardless, spec §32). Instructions are shown read-only — pharmacy staff
 * never silently edit a doctor's prescription here (spec §16).
 */
export default function PharmacyDispensingShow({
    prescription,
    canStart,
    canComplete,
    canCancel,
    prescriptionFinancialStatus = 'authorized',
    patientAccountId = null,
    nhifUtilizations = [],
}: PharmacyDispensingShowProps & { prescriptionFinancialStatus?: string; patientAccountId?: number | null }) {
    const isPaymentRequired = prescriptionFinancialStatus === 'payment_required';
    const patientName = prescription.patient
        ? [prescription.patient.first_name, prescription.patient.middle_name, prescription.patient.last_name].filter(Boolean).join(' ')
        : '—';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Pharmacy', href: '/pharmacy' },
        { title: 'Dispensing', href: '/pharmacy/dispensing' },
        { title: prescription.prescription_number, href: `/pharmacy/dispensing/${prescription.id}` },
    ];

    const dispensableItems = prescription.items.filter((item) => item.status === 'active' && item.remaining_quantity > 0);
    const inProgress = prescription.current_dispensing;

    const [draft, setDraft] = useState<Record<number, DraftLine>>(() =>
        Object.fromEntries(dispensableItems.map((item) => [item.id, { dispensed_quantity: String(item.remaining_quantity), status: 'dispensed' as DispensingItemStatus }])),
    );
    const [notes, setNotes] = useState('');

    const setLine = (itemId: number, patch: Partial<DraftLine>) => {
        setDraft((prev) => ({ ...prev, [itemId]: { ...prev[itemId], ...patch } }));
    };

    const markUnavailable = (itemId: number) => {
        setLine(itemId, { dispensed_quantity: '0', status: 'unavailable' });
    };

    const dialogItems = useMemo(
        () =>
            dispensableItems.map((item) => ({
                prescription_order_item_id: item.id,
                label: `${item.medication_name_snapshot}${item.strength_snapshot ? ` ${item.strength_snapshot}` : ''}`,
                dispensed_quantity: draft[item.id]?.dispensed_quantity ?? '0',
                status: draft[item.id]?.status ?? ('dispensed' as DispensingItemStatus),
                notes: '',
            })),
        [dispensableItems, draft],
    );

    const startDispensing = () => {
        router.post(route('pharmacy-dispensing.start', prescription.id), {}, { preserveScroll: true });
    };

    const medicationLabel = (prescriptionOrderItemId: number): string => {
        const item = prescription.items.find((candidate) => candidate.id === prescriptionOrderItemId);

        return item ? `${item.medication_name_snapshot}${item.strength_snapshot ? ` ${item.strength_snapshot}` : ''}` : `Item #${prescriptionOrderItemId}`;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Dispense ${prescription.prescription_number}`} />

            <div className="flex flex-col gap-6 p-4">
                {isPaymentRequired && (
                    <div className="rounded-md border border-amber-500/50 bg-amber-500/10 p-4 text-amber-800 dark:text-amber-300">
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <div className="space-y-1">
                                <div className="flex items-center gap-2 font-bold text-sm">
                                    <span>Financial Status: PAYMENT REQUIRED</span>
                                    <Badge variant="destructive" className="bg-amber-600 dark:bg-amber-700 text-white">
                                        PAYMENT REQUIRED
                                    </Badge>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Pharmacy dispensing is blocked until prescription item charges are settled at the cashier.
                                </p>
                            </div>
                            {patientAccountId && (
                                <Button size="sm" variant="default" className="bg-amber-700 hover:bg-amber-800 text-white" asChild>
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
                            {patientName}
                            {prescription.patient?.patient_number ? ` — ${prescription.patient.patient_number}` : ''}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Badge variant={fulfillmentStatusVariant[prescription.fulfillment_status]}>
                            {fulfillmentStatusLabel[prescription.fulfillment_status]}
                        </Badge>
                        {prescription.clinical_encounter_id && (
                            <NhifClaimFinalizeDialog
                                clinicalEncounterId={prescription.clinical_encounter_id}
                                patientName={patientName}
                            />
                        )}
                        {!inProgress && canStart && prescription.fulfillment_status !== 'dispensed' && (
                            <Button onClick={startDispensing} disabled={isPaymentRequired} title={isPaymentRequired ? 'Payment required before dispensing' : undefined}>
                                <PlayCircle className="h-4 w-4" />
                                Start Dispensing
                            </Button>
                        )}
                    </div>
                </div>

                {nhifUtilizations.length > 0 && (
                    <div className="flex flex-col gap-2 rounded-md border p-3">
                        {nhifUtilizations.map((u) => (
                            <NhifUtilizationStatus key={u.id} utilization={u} linkable />
                        ))}
                    </div>
                )}

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Patient</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Name</dt>
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
                                    <dt className="text-muted-foreground">Order Status</dt>
                                    <dd>
                                        <Badge variant={prescriptionOrderStatusVariant[prescription.status]}>
                                            {prescriptionOrderStatusLabel[prescription.status]}
                                        </Badge>
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Verified By</dt>
                                    <dd>{prescription.verification?.verified_by?.name ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Verified At</dt>
                                    <dd>{formatDateTime(prescription.verification?.verified_at ?? null)}</dd>
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
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Medication</TableHead>
                                    <TableHead>Dose / Route / Frequency</TableHead>
                                    <TableHead>Instructions</TableHead>
                                    <TableHead className="text-right">Prescribed</TableHead>
                                    <TableHead className="text-right">Dispensed</TableHead>
                                    <TableHead className="text-right">Remaining</TableHead>
                                    {inProgress && canComplete && !isPaymentRequired && <TableHead className="text-right">Dispense Now</TableHead>}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {prescription.items.map((item) => {
                                    const line = draft[item.id];
                                    const canDispenseThis = inProgress && canComplete && !isPaymentRequired && item.status === 'active' && item.remaining_quantity > 0;

                                    return (
                                        <TableRow key={item.id}>
                                            <TableCell>
                                                <div className={item.status === 'cancelled' ? 'text-muted-foreground line-through' : ''}>
                                                    {item.medication_name_snapshot}
                                                    {item.strength_snapshot ? ` ${item.strength_snapshot}` : ''}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {[item.dose, item.dose_unit].filter(Boolean).join(' ')} · {prescriptionRouteLabel[item.route]} ·{' '}
                                                {prescriptionFrequencyLabel[item.frequency]}
                                                {item.duration
                                                    ? ` · ${item.duration} ${item.duration_unit ? prescriptionDurationUnitLabel[item.duration_unit].toLowerCase() : ''}`
                                                    : ''}
                                            </TableCell>
                                            <TableCell>{item.instructions || '—'}</TableCell>
                                            <TableCell className="text-right">
                                                {item.quantity}
                                                {item.quantity_unit ? ` ${item.quantity_unit}` : ''}
                                            </TableCell>
                                            <TableCell className="text-right">{item.dispensed_quantity}</TableCell>
                                            <TableCell className="text-right">{item.remaining_quantity}</TableCell>
                                            {inProgress && canComplete && !isPaymentRequired && (
                                                <TableCell className="text-right">
                                                    {canDispenseThis ? (
                                                        <div className="flex items-center justify-end gap-2">
                                                            <Input
                                                                type="number"
                                                                min={0}
                                                                max={item.remaining_quantity}
                                                                value={line?.status === 'unavailable' ? 0 : (line?.dispensed_quantity ?? '')}
                                                                disabled={line?.status === 'unavailable'}
                                                                onChange={(e) => {
                                                                    const clamped = Math.max(0, Math.min(Number(e.target.value) || 0, item.remaining_quantity));
                                                                    setLine(item.id, { dispensed_quantity: String(clamped), status: 'dispensed' });
                                                                }}
                                                                className="w-20 text-right"
                                                            />
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant={line?.status === 'unavailable' ? 'secondary' : 'ghost'}
                                                                onClick={() =>
                                                                    line?.status === 'unavailable'
                                                                        ? setLine(item.id, { dispensed_quantity: String(item.remaining_quantity), status: 'dispensed' })
                                                                        : markUnavailable(item.id)
                                                                }
                                                            >
                                                                {line?.status === 'unavailable' ? dispensingItemStatusLabel.unavailable : 'Unavailable'}
                                                            </Button>
                                                        </div>
                                                    ) : (
                                                        '—'
                                                    )}
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {inProgress && (canComplete || canCancel) && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Current Dispensing — {inProgress.dispensing_number}</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {isPaymentRequired ? (
                                <p className="text-sm text-amber-700 dark:text-amber-400">
                                    Completing this dispensing is blocked until the prescription charges are paid. You may cancel the in-progress event below.
                                </p>
                            ) : (
                                <div className="grid gap-1.5">
                                    <label className="text-sm font-medium" htmlFor="dispensing_notes">
                                        Notes
                                    </label>
                                    <Textarea
                                        id="dispensing_notes"
                                        rows={2}
                                        value={notes}
                                        onChange={(e) => setNotes(e.target.value)}
                                        placeholder="e.g. Patient counselled on administration."
                                    />
                                </div>
                            )}

                            <div className="flex justify-end gap-2">
                                {canCancel && (
                                    <CancelDispensingDialog
                                        prescriptionOrderId={prescription.id}
                                        trigger={<Button variant="destructive">Cancel</Button>}
                                    />
                                )}
                                {canComplete && !isPaymentRequired && (
                                    <CompleteDispensingDialog
                                        prescriptionOrderId={prescription.id}
                                        items={dialogItems.map((item) => ({ ...item, notes: '' }))}
                                        notes={notes}
                                        trigger={<Button>Complete Dispensing</Button>}
                                    />
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Dispensing History</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {prescription.dispensings.length > 0 ? (
                            <ul className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                                {prescription.dispensings.map((dispensing) => (
                                    <li key={dispensing.id} className="flex flex-col gap-1 py-3 first:pt-0 last:pb-0 text-sm">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">{dispensing.dispensing_number}</span>
                                            <Badge variant={dispensingStatusVariant[dispensing.status]}>{dispensingStatusLabel[dispensing.status]}</Badge>
                                            <span className="text-muted-foreground text-xs">
                                                {dispensing.dispensed_by?.name ?? '—'} · {formatDateTime(dispensing.started_at)}
                                            </span>
                                        </div>
                                        {dispensing.items.length > 0 && (
                                            <ul className="text-muted-foreground ml-1 space-y-0.5">
                                                {dispensing.items.map((item) => (
                                                    <li key={item.id}>
                                                        {medicationLabel(item.prescription_order_item_id)} —{' '}
                                                        {item.status === 'unavailable'
                                                            ? dispensingItemStatusLabel.unavailable
                                                            : `${item.dispensed_quantity} dispensed`}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                        {dispensing.notes && <span className="text-muted-foreground">{dispensing.notes}</span>}
                                        {dispensing.status === 'cancelled' && dispensing.cancellation_reason && (
                                            <span className="text-muted-foreground">Cancelled: {dispensing.cancellation_reason}</span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-muted-foreground text-sm">No dispensing events recorded yet.</p>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
