import { CancelPrescriptionOrderDialog } from '@/components/clinical-encounters/cancel-prescription-order-dialog';
import { CancelPrescriptionOrderItemDialog } from '@/components/clinical-encounters/cancel-prescription-order-item-dialog';
import { MedicationSearch } from '@/components/care-plan/medication-search';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { usePermissions } from '@/hooks/use-permissions';
import {
    prescriptionDurationUnitLabel,
    prescriptionFrequencyLabel,
    prescriptionOrderStatusLabel,
    prescriptionOrderStatusVariant,
    prescriptionRouteLabel,
    prescriptionVerificationStatusLabel,
    prescriptionVerificationStatusVariant,
} from '@/lib/prescription-status';
import { type Consultation } from '@/types/consultation';
import { type MedicationSearchResult } from '@/types/medication';
import {
    type MedicationSafetyWarning,
    type PrescriptionDurationUnit,
    type PrescriptionFrequency,
    type PrescriptionOrder,
    type PrescriptionOrderFormData,
    type PrescriptionRoute,
} from '@/types/prescription';
import { Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, ClipboardPlus, LoaderCircle, Plus, RotateCcw, XCircle } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

interface PrescriptionOrdersSectionProps {
    encounterId: number;
    consultation: Consultation | null;
    prescriptionOrders: PrescriptionOrder[];
    medicationSafetyRules: Record<number, MedicationSafetyWarning[]>;
}

const FREQUENCIES = Object.keys(prescriptionFrequencyLabel) as PrescriptionFrequency[];
const ROUTES = Object.keys(prescriptionRouteLabel) as PrescriptionRoute[];
const DURATION_UNITS = Object.keys(prescriptionDurationUnitLabel) as PrescriptionDurationUnit[];

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

function itemSummary(item: {
    medication_name_snapshot: string;
    strength_snapshot: string | null;
    dose: string;
    dose_unit: string | null;
    route: PrescriptionRoute;
    frequency: PrescriptionFrequency;
    duration: number | null;
    duration_unit: PrescriptionDurationUnit | null;
}): string {
    const parts = [
        item.medication_name_snapshot + (item.strength_snapshot ? ` ${item.strength_snapshot}` : ''),
        [item.dose, item.dose_unit].filter(Boolean).join(' '),
        prescriptionRouteLabel[item.route],
        prescriptionFrequencyLabel[item.frequency],
        item.duration ? `${item.duration} ${item.duration_unit ? prescriptionDurationUnitLabel[item.duration_unit].toLowerCase() : ''}`.trim() : null,
    ].filter(Boolean);

    return parts.join(' — ');
}

/**
 * Prescription is its own sibling branch off the clinical encounter (Phase
 * 6, Step 46) — embedded on the encounter screen, mirroring
 * RadiologyOrdersSection/LaboratoryOrdersSection/CarePlanSection exactly,
 * never a standalone create/edit page. Requires the consultation to be
 * in_progress or completed, same as Laboratory/Radiology — but deliberately
 * has no diagnosis requirement, since an urgent prescription must not be
 * blocked on a final diagnosis not yet being entered.
 */
export function PrescriptionOrdersSection({ encounterId, consultation, prescriptionOrders, medicationSafetyRules }: PrescriptionOrdersSectionProps) {
    const { can } = usePermissions();
    const consultationEngaged = consultation?.status === 'in_progress' || consultation?.status === 'completed';

    if (!consultationEngaged) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Prescriptions</CardTitle>
                </CardHeader>
                <CardContent>
                    <p className="text-muted-foreground text-sm">A consultation must be started before a prescription can be written.</p>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Prescriptions</CardTitle>
            </CardHeader>
            <CardContent className="space-y-6">
                {prescriptionOrders.length > 0 ? (
                    <ul className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                        {prescriptionOrders.map((order) => (
                            <PrescriptionOrderRow key={order.id} encounterId={encounterId} order={order} />
                        ))}
                    </ul>
                ) : (
                    <p className="text-muted-foreground text-sm">No prescriptions have been written for this encounter yet.</p>
                )}

                {can('prescription-orders.create') && (
                    <AddPrescriptionOrderForm encounterId={encounterId} medicationSafetyRules={medicationSafetyRules} />
                )}
            </CardContent>
        </Card>
    );
}

function PrescriptionOrderRow({ encounterId, order }: { encounterId: number; order: PrescriptionOrder }) {
    const { can } = usePermissions();
    const mutable = order.status === 'active';
    const verification = order.verification;

    const resubmit = () => {
        router.post(route('pharmacy-verification.resubmit', order.id), {}, { preserveScroll: true });
    };

    return (
        <li className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <Link href={route('pharmacy.prescriptions.show', order.id)} className="font-medium hover:underline">
                        {order.prescription_number}
                    </Link>
                    <Badge variant={prescriptionOrderStatusVariant[order.status]}>{prescriptionOrderStatusLabel[order.status]}</Badge>
                    {verification && (
                        <Badge variant={prescriptionVerificationStatusVariant[verification.status]}>
                            {prescriptionVerificationStatusLabel[verification.status]}
                        </Badge>
                    )}
                </div>

                <div className="flex items-center gap-2">
                    {verification?.status === 'returned' && mutable && can('prescription-orders.update') && (
                        <Button size="sm" variant="outline" onClick={resubmit}>
                            <RotateCcw className="h-4 w-4" />
                            Resubmit for Verification
                        </Button>
                    )}

                    {mutable && can('prescription-orders.cancel') && (
                        <CancelPrescriptionOrderDialog
                            encounterId={encounterId}
                            orderId={order.id}
                            trigger={
                                <Button size="sm" variant="destructive">
                                    <XCircle className="h-4 w-4" />
                                    Cancel Prescription
                                </Button>
                            }
                        />
                    )}
                </div>
            </div>

            {verification?.status === 'returned' && verification.return_reason && (
                <p className="text-destructive text-xs">Returned for correction: {verification.return_reason}</p>
            )}

            <ul className="space-y-1 text-sm">
                {order.items.map((item) => (
                    <li key={item.id} className="flex flex-wrap items-center justify-between gap-2">
                        <span className={item.status === 'cancelled' ? 'text-muted-foreground line-through' : ''}>{itemSummary(item)}</span>

                        {mutable && item.status === 'active' && can('prescription-orders.update') && (
                            <CancelPrescriptionOrderItemDialog
                                encounterId={encounterId}
                                orderId={order.id}
                                itemId={item.id}
                                trigger={
                                    <Button size="sm" variant="ghost">
                                        <XCircle className="h-4 w-4" />
                                        Remove
                                    </Button>
                                }
                            />
                        )}
                    </li>
                ))}
            </ul>

            <div className="text-muted-foreground grid gap-1 text-xs">
                {order.clinical_notes && <span>{order.clinical_notes}</span>}
                <span>
                    {order.prescriber?.name ?? '—'} · {formatDateTime(order.prescribed_at)}
                </span>
                {order.status === 'cancelled' && order.cancellation_reason && <span>Cancelled: {order.cancellation_reason}</span>}
            </div>
        </li>
    );
}

interface DraftItem {
    medication: MedicationSearchResult;
    dose: string;
    dose_unit: string;
    frequency: PrescriptionFrequency;
    route: PrescriptionRoute;
    duration: string;
    duration_unit: PrescriptionDurationUnit | '';
    quantity: string;
    quantity_unit: string;
    instructions: string;
}

function AddPrescriptionOrderForm({
    encounterId,
    medicationSafetyRules,
}: {
    encounterId: number;
    medicationSafetyRules: Record<number, MedicationSafetyWarning[]>;
}) {
    const { data, setData, post, processing, errors, reset } = useForm<PrescriptionOrderFormData>({
        items: [],
        clinical_notes: '',
    });
    const [addedItems, setAddedItems] = useState<DraftItem[]>([]);
    const [itemJustifications, setItemJustifications] = useState<Record<number, string>>({});

    const [medication, setMedication] = useState<MedicationSearchResult | null>(null);
    const [dose, setDose] = useState('');
    const [doseUnit, setDoseUnit] = useState('');
    const [frequency, setFrequency] = useState<PrescriptionFrequency | ''>('');
    // Named administrationRoute, not route, so it never shadows Ziggy's
    // global route() helper used by submit() below — that shadowing bug
    // silently broke this form's submission (route(...) is not callable
    // once `route` is a string) until this fix.
    const [administrationRoute, setAdministrationRoute] = useState<PrescriptionRoute | ''>('');
    const [duration, setDuration] = useState('');
    const [durationUnit, setDurationUnit] = useState<PrescriptionDurationUnit | ''>('');
    const [quantity, setQuantity] = useState('');
    const [quantityUnit, setQuantityUnit] = useState('');
    const [instructions, setInstructions] = useState('');

    const resetDraft = () => {
        setMedication(null);
        setDose('');
        setDoseUnit('');
        setFrequency('');
        setAdministrationRoute('');
        setDuration('');
        setDurationUnit('');
        setQuantity('');
        setQuantityUnit('');
        setInstructions('');
    };

    const canAddItem = medication && dose.trim() !== '' && frequency !== '' && administrationRoute !== '' && quantity.trim() !== '';

    const updateJustification = (index: number, value: string) => {
        setItemJustifications((prev) => ({ ...prev, [index]: value }));
        setData(
            'items',
            data.items.map((item, i) => (i === index ? { ...item, safety_override_justification: value } : item)),
        );
    };

    const addItem = () => {
        if (!medication || frequency === '' || administrationRoute === '') {
            return;
        }

        const item: DraftItem = {
            medication,
            dose,
            dose_unit: doseUnit,
            frequency,
            route: administrationRoute,
            duration,
            duration_unit: durationUnit,
            quantity,
            quantity_unit: quantityUnit,
            instructions,
        };

        setAddedItems((prev) => [...prev, item]);
        setData('items', [
            ...data.items,
            {
                medication_id: medication.id,
                dose,
                dose_unit: doseUnit,
                frequency,
                route: administrationRoute,
                duration,
                duration_unit: durationUnit,
                quantity,
                quantity_unit: quantityUnit,
                instructions,
                safety_override_justification: '',
            },
        ]);
        resetDraft();
    };

    const removeItem = (index: number) => {
        setAddedItems((prev) => prev.filter((_, i) => i !== index));
        setData(
            'items',
            data.items.filter((_, i) => i !== index),
        );
        setItemJustifications((prev) => {
            const next = { ...prev };
            delete next[index];
            return next;
        });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('clinical-encounters.prescription-orders.store', encounterId), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setAddedItems([]);
                resetDraft();
            },
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4 border-t pt-4">
            <h3 className="text-sm font-medium">New Prescription</h3>

            {addedItems.length > 0 && (
                <ul className="space-y-2">
                    {addedItems.map((item, index) => {
                        const warnings = medicationSafetyRules[item.medication.id] ?? [];
                        const justificationError = (errors as Record<string, string>)[`items.${index}.safety_override_justification`];
                        return (
                            <li key={index} className="bg-muted/50 space-y-2 rounded-md px-3 py-2 text-sm">
                                <div className="flex items-center justify-between gap-2">
                                    <span>
                                        {itemSummary({
                                            medication_name_snapshot: item.medication.generic_name,
                                            strength_snapshot: item.medication.strength,
                                            dose: item.dose,
                                            dose_unit: item.dose_unit || null,
                                            route: item.route,
                                            frequency: item.frequency,
                                            duration: item.duration ? Number(item.duration) : null,
                                            duration_unit: item.duration_unit || null,
                                        })}
                                    </span>
                                    <Button type="button" size="sm" variant="ghost" onClick={() => removeItem(index)}>
                                        <XCircle className="h-4 w-4" />
                                        Remove
                                    </Button>
                                </div>

                                {warnings.length > 0 && (
                                    <div className="space-y-1.5">
                                        {warnings.map((w, wi) => (
                                            <div key={wi} className="text-destructive flex items-start gap-1.5 text-xs">
                                                <AlertTriangle className="mt-0.5 h-3 w-3 shrink-0" />
                                                <span>
                                                    <strong className="capitalize">{w.rule_type}</strong> ({w.diagnosis_code}): {w.description}
                                                </span>
                                            </div>
                                        ))}
                                        <div className="grid gap-1">
                                            <label className="text-xs font-medium">
                                                Clinical justification for prescribing <span className="text-destructive">*</span>
                                            </label>
                                            <textarea
                                                className="border-input bg-background focus-visible:ring-ring min-h-[60px] w-full rounded-md border px-3 py-1.5 text-xs shadow-sm focus-visible:ring-1 focus-visible:outline-none"
                                                rows={2}
                                                placeholder="Explain the clinical rationale for prescribing despite this warning…"
                                                value={itemJustifications[index] ?? ''}
                                                onChange={(e) => updateJustification(index, e.target.value)}
                                            />
                                            {justificationError && <InputError message={justificationError} />}
                                        </div>
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
            <InputError message={errors.items} />

            <div className="grid gap-4 rounded-md border p-3">
                <div className="grid gap-1.5">
                    <label className="text-sm font-medium">Medication</label>
                    <MedicationSearch selected={medication} onSelect={setMedication} />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-1.5">
                        <label className="text-sm font-medium">Dose</label>
                        <Input value={dose} onChange={(e) => setDose(e.target.value)} placeholder="e.g. 1" />
                    </div>
                    <div className="grid gap-1.5">
                        <label className="text-sm font-medium">Dose Unit</label>
                        <Input value={doseUnit} onChange={(e) => setDoseUnit(e.target.value)} placeholder="e.g. tablet" />
                    </div>
                    <div className="grid gap-1.5">
                        <label className="text-sm font-medium">Frequency</label>
                        <Select value={frequency} onValueChange={(value) => setFrequency(value as PrescriptionFrequency)}>
                            <SelectTrigger>
                                <SelectValue placeholder="Select a frequency" />
                            </SelectTrigger>
                            <SelectContent>
                                {FREQUENCIES.map((value) => (
                                    <SelectItem key={value} value={value}>
                                        {prescriptionFrequencyLabel[value]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1.5">
                        <label className="text-sm font-medium">Route</label>
                        <Select value={administrationRoute} onValueChange={(value) => setAdministrationRoute(value as PrescriptionRoute)}>
                            <SelectTrigger>
                                <SelectValue placeholder="Select a route" />
                            </SelectTrigger>
                            <SelectContent>
                                {ROUTES.map((value) => (
                                    <SelectItem key={value} value={value}>
                                        {prescriptionRouteLabel[value]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1.5">
                        <label className="text-sm font-medium">Duration</label>
                        <Input value={duration} onChange={(e) => setDuration(e.target.value)} placeholder="e.g. 5" inputMode="numeric" />
                    </div>
                    <div className="grid gap-1.5">
                        <label className="text-sm font-medium">Duration Unit</label>
                        <Select value={durationUnit} onValueChange={(value) => setDurationUnit(value as PrescriptionDurationUnit)}>
                            <SelectTrigger>
                                <SelectValue placeholder="Select a unit" />
                            </SelectTrigger>
                            <SelectContent>
                                {DURATION_UNITS.map((value) => (
                                    <SelectItem key={value} value={value}>
                                        {prescriptionDurationUnitLabel[value]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1.5">
                        <label className="text-sm font-medium">Quantity</label>
                        <Input value={quantity} onChange={(e) => setQuantity(e.target.value)} placeholder="e.g. 15" inputMode="numeric" />
                    </div>
                    <div className="grid gap-1.5">
                        <label className="text-sm font-medium">Quantity Unit</label>
                        <Input value={quantityUnit} onChange={(e) => setQuantityUnit(e.target.value)} placeholder="e.g. tablets" />
                    </div>
                </div>

                <div className="grid gap-1.5">
                    <label className="text-sm font-medium">Instructions</label>
                    <Textarea rows={2} value={instructions} onChange={(e) => setInstructions(e.target.value)} placeholder="e.g. Take after meals" />
                </div>

                <div className="flex justify-end">
                    <Button type="button" size="sm" variant="secondary" disabled={!canAddItem} onClick={addItem}>
                        <Plus className="h-4 w-4" />
                        Add Medication
                    </Button>
                </div>
            </div>

            <div className="grid gap-1.5">
                <label className="text-sm font-medium" htmlFor="prescription_order_clinical_notes">
                    Clinical Notes
                </label>
                <Textarea
                    id="prescription_order_clinical_notes"
                    rows={2}
                    value={data.clinical_notes}
                    onChange={(e) => setData('clinical_notes', e.target.value)}
                />
                <InputError message={errors.clinical_notes} />
            </div>

            <div className="flex justify-end">
                <Button type="submit" size="sm" disabled={processing || data.items.length === 0}>
                    {processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <ClipboardPlus className="h-4 w-4" />}
                    Create Prescription
                </Button>
            </div>
        </form>
    );
}
