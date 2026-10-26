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
import { useToast } from '@/hooks/use-toast';
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
import { BiometricIssueDialog } from '@/components/nhif/biometric-issue-dialog';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Checkbox } from '@/components/ui/checkbox';
import { type NhifInsuranceSummary, type NhifPreApprovalSession } from '@/types/nhif';
import { Link, router, useForm } from '@inertiajs/react';
import { AlertCircle, AlertTriangle, CheckCircle2, ClipboardPlus, Fingerprint, Info, LoaderCircle, Package, Plus, RotateCcw, Send, XCircle } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

interface PrescriptionOrdersSectionProps {
    encounterId: number;
    consultation: Consultation | null;
    prescriptionOrders: PrescriptionOrder[];
    medicationSafetyRules: Record<number, MedicationSafetyWarning[]>;
    nhifEligible?: boolean;
    nhifInsurance?: NhifInsuranceSummary | null;
    nhifPreApprovalSessions?: NhifPreApprovalSession[];
    patientName?: string;
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
export function PrescriptionOrdersSection({
    encounterId,
    consultation,
    prescriptionOrders,
    medicationSafetyRules,
    nhifEligible,
    nhifInsurance,
    nhifPreApprovalSessions,
    patientName,
}: PrescriptionOrdersSectionProps) {
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

    const hasPendingNhif = Boolean(
        nhifEligible &&
        prescriptionOrders.some(
            (order) =>
                order.nhif_pre_approval?.status === 'nhif_pending_request' ||
                order.nhif_pre_approval?.status === 'nhif_sent_not_issued'
        )
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Prescriptions</CardTitle>
            </CardHeader>
            <CardContent className="space-y-6">
                {hasPendingNhif && (
                    <div className="flex items-start gap-3 rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-amber-900 dark:text-amber-200 text-sm">
                        <AlertTriangle className="h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400 mt-0.5" />
                        <div className="space-y-1">
                            <p className="font-medium">NHIF Pre-Approval Required</p>
                            <p className="text-xs text-muted-foreground">
                                Prescriptions for NHIF patients must have their pre-approval requested and issued with biometric verification before pharmacy verification and dispensing.
                            </p>
                        </div>
                    </div>
                )}

                {prescriptionOrders.length > 0 ? (
                    <ul className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                        {prescriptionOrders.map((order) => (
                            <PrescriptionOrderRow
                                key={order.id}
                                encounterId={encounterId}
                                order={order}
                                nhifEligible={nhifEligible}
                                nhifInsurance={nhifInsurance}
                                sessions={nhifPreApprovalSessions}
                            />
                        ))}
                    </ul>
                ) : (
                    <p className="text-muted-foreground text-sm">No prescriptions have been written for this encounter yet.</p>
                )}

                {can('prescription-orders.create') && (
                    <AddPrescriptionOrderForm
                        encounterId={encounterId}
                        medicationSafetyRules={medicationSafetyRules}
                        nhifEligible={nhifEligible}
                    />
                )}
            </CardContent>
        </Card>
    );
}

function PrescriptionOrderRow({
    encounterId,
    order,
    nhifEligible,
    nhifInsurance,
    sessions,
}: {
    encounterId: number;
    order: PrescriptionOrder;
    nhifEligible?: boolean;
    nhifInsurance?: NhifInsuranceSummary | null;
    sessions?: NhifPreApprovalSession[];
}) {
    const { can } = usePermissions();
    const { toast } = useToast();
    const mutable = order.status === 'active';
    const verification = order.verification;
    const [submittingPreApproval, setSubmittingPreApproval] = useState(false);
    const [issueDialogOpen, setIssueDialogOpen] = useState(false);

    const preApproval = order.nhif_pre_approval;
    const activeItemIds = order.items.filter((item) => item.status === 'active').map((item) => item.id);
    const sessionId = preApproval?.session_id ?? sessions?.find((s) => s.items.some((i) => i.source_type === 'medication' && activeItemIds.includes(i.source_id)))?.id;

    const sendPreApproval = () => {
        if (nhifInsurance?.is_authorization_expired) {
            toast({
                variant: 'error',
                description: "Patient NHIF authorization has expired (>24 hours). Please send the patient to reception to re-verify their NHIF card.",
            });
            return;
        }

        setSubmittingPreApproval(true);
        router.post(
            route('clinical-encounters.nhif-pre-approval.store', encounterId),
            {
                prescription_order_item_ids: activeItemIds,
                clinical_notes: order.clinical_notes || undefined,
            },
            {
                preserveScroll: true,
                onError: (errors) => {
                    const message = (errors.nhif_authorization_no as string) || Object.values(errors)[0] || 'Failed to submit pre-approval';
                    toast({ variant: 'error', description: String(message) });
                },
                onFinish: () => setSubmittingPreApproval(false),
            }
        );
    };

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
                    {preApproval && (
                        <Badge
                            variant="outline"
                            className={
                                preApproval.status === 'authorized'
                                    ? 'border-green-500/50 text-green-700 dark:text-green-400 font-medium'
                                    : 'border-amber-500 bg-amber-500/10 text-amber-700 dark:text-amber-400 font-medium'
                            }
                        >
                            {preApproval.label}
                        </Badge>
                    )}
                </div>

                <div className="flex items-center gap-2 flex-wrap">
                    {preApproval?.status === 'nhif_pending_request' && mutable && can('prescription-orders.update') && (
                        <Button
                            size="sm"
                            variant="outline"
                            className="gap-1 border-amber-500 text-amber-700 hover:bg-amber-50 dark:text-amber-300 dark:hover:bg-amber-950/30 font-medium"
                            onClick={sendPreApproval}
                            disabled={submittingPreApproval || Boolean(nhifInsurance?.is_authorization_expired)}
                            title={nhifInsurance?.is_authorization_expired ? 'Patient authorization expired. Send patient to reception to re-verify.' : undefined}
                        >
                            <Send className="h-3.5 w-3.5" />
                            {submittingPreApproval ? 'Sending Request...' : 'Send Pre-Approval Request'}
                        </Button>
                    )}

                    {preApproval?.status === 'nhif_sent_not_issued' && sessionId && (
                        <Button
                            size="sm"
                            className="gap-1 bg-amber-600 hover:bg-amber-700 text-white dark:bg-amber-700 font-medium"
                            onClick={() => setIssueDialogOpen(true)}
                        >
                            <Fingerprint className="h-3.5 w-3.5" />
                            Issue Pre-Approval
                        </Button>
                    )}

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

            {sessionId && (
                <BiometricIssueDialog
                    open={issueDialogOpen}
                    onOpenChange={setIssueDialogOpen}
                    issueUrl={route('clinical-encounters.nhif-pre-approval.issue', [encounterId, sessionId])}
                />
            )}
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
    nhifEligible,
}: {
    encounterId: number;
    medicationSafetyRules: Record<number, MedicationSafetyWarning[]>;
    nhifEligible?: boolean;
}) {
    const { data, setData, post, processing, errors, reset } = useForm<PrescriptionOrderFormData>({
        items: [],
        clinical_notes: '',
        send_preapproval: nhifEligible ? true : undefined,
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

    const [stockConfirmOpen, setStockConfirmOpen] = useState(false);

    const requestedQtyNum = Number(quantity) || 0;
    const availableStockNum = medication?.available_stock ?? 0;
    const isMedicationOutOfStock = Boolean(medication && (medication.is_out_of_stock || availableStockNum === 0));
    const isQuantityExceedingStock = Boolean(medication && !isMedicationOutOfStock && requestedQtyNum > availableStockNum);
    const isMedicationLowStock = Boolean(medication && !isMedicationOutOfStock && !isQuantityExceedingStock && medication.is_low_stock);

    const stockDeficits = addedItems.filter((item) => {
        const avail = item.medication.available_stock ?? 0;
        const req = Number(item.quantity) || 0;
        return item.medication.is_out_of_stock || avail === 0 || req > avail;
    });

    const submitOrder = () => {
        post(route('clinical-encounters.prescription-orders.store', encounterId), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setAddedItems([]);
                resetDraft();
                setStockConfirmOpen(false);
            },
        });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (stockDeficits.length > 0) {
            setStockConfirmOpen(true);
            return;
        }
        submitOrder();
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
                                    <div className="flex flex-wrap items-center gap-2">
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
                                        {item.medication.available_stock !== undefined && (
                                            (item.medication.is_out_of_stock || (item.medication.available_stock ?? 0) === 0) ? (
                                                <span className="inline-flex items-center gap-1 rounded bg-red-100 dark:bg-red-950/60 px-1.5 py-0.5 text-[10px] font-semibold text-red-700 dark:text-red-300 border border-red-300 dark:border-red-900">
                                                    <AlertCircle className="h-3 w-3" />
                                                    Out of stock (0 avail)
                                                </span>
                                            ) : Number(item.quantity) > (item.medication.available_stock ?? 0) ? (
                                                <span className="inline-flex items-center gap-1 rounded bg-amber-100 dark:bg-amber-950/60 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 dark:text-amber-300 border border-amber-300 dark:border-amber-900">
                                                    <AlertTriangle className="h-3 w-3" />
                                                    Exceeds stock ({item.quantity} req / {item.medication.available_stock} avail)
                                                </span>
                                            ) : (
                                                <span className="inline-flex items-center gap-1 rounded bg-emerald-100 dark:bg-emerald-950/60 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-900">
                                                    <CheckCircle2 className="h-3 w-3" />
                                                    In stock ({item.medication.available_stock} avail)
                                                </span>
                                            )
                                        )}
                                    </div>
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
                    <MedicationSearch
                        selected={medication}
                        onSelect={setMedication}
                        onClear={() => setMedication(null)}
                        clinicalEncounterId={encounterId}
                    />

                    {medication && (
                        <div className="mt-1 flex flex-wrap items-center justify-between gap-2 rounded-md border p-2 text-xs bg-muted/40">
                            <div className="flex items-center gap-1.5">
                                <Package className="h-3.5 w-3.5 text-muted-foreground" />
                                <span className="font-medium text-foreground">{medication.generic_name}</span>
                                {medication.brand_name && <span className="text-muted-foreground">({medication.brand_name})</span>}
                            </div>
                            <div>
                                {medication.available_stock !== undefined ? (
                                    medication.is_out_of_stock ? (
                                        <span className="inline-flex items-center gap-1 rounded bg-red-100 dark:bg-red-950/60 text-red-800 dark:text-red-300 px-2 py-0.5 font-semibold border border-red-300 dark:border-red-900">
                                            <AlertCircle className="h-3 w-3" />
                                            Out of Stock (0 units available)
                                        </span>
                                    ) : medication.is_low_stock ? (
                                        <span className="inline-flex items-center gap-1 rounded bg-amber-100 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 px-2 py-0.5 font-semibold border border-amber-300 dark:border-amber-900">
                                            <AlertTriangle className="h-3 w-3" />
                                            Low Stock ({medication.available_stock} available, Reorder level: {medication.reorder_level ?? 0})
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center gap-1 rounded bg-emerald-100 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-300 px-2 py-0.5 font-semibold border border-emerald-300 dark:border-emerald-900">
                                            <CheckCircle2 className="h-3 w-3" />
                                            In Stock ({medication.available_stock} units available)
                                        </span>
                                    )
                                ) : (
                                    <span className="text-muted-foreground">Stock inventory unlinked</span>
                                )}
                            </div>
                        </div>
                    )}
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

                {medication && isMedicationOutOfStock && (
                    <div className="flex items-center gap-2 rounded border border-red-500/40 bg-red-500/10 p-2.5 text-xs text-red-900 dark:text-red-200">
                        <AlertCircle className="h-4 w-4 shrink-0 text-red-600 dark:text-red-400" />
                        <span>
                            <strong>Out of Stock Alert:</strong> The hospital pharmacy currently has <strong>0 units</strong> of {medication.generic_name}. If requested, the patient may need outside purchasing or pharmacy restock.
                        </span>
                    </div>
                )}

                {medication && isQuantityExceedingStock && requestedQtyNum > 0 && (
                    <div className="flex items-center gap-2 rounded border border-amber-500/40 bg-amber-500/10 p-2.5 text-xs text-amber-900 dark:text-amber-200">
                        <AlertTriangle className="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                        <span>
                            <strong>Insufficient Stock Alert:</strong> You requested <strong>{requestedQtyNum}</strong> units, but only <strong>{availableStockNum}</strong> units are currently available in the pharmacy.
                        </span>
                    </div>
                )}

                {medication && isMedicationLowStock && requestedQtyNum > 0 && (
                    <div className="flex items-center gap-2 rounded border border-blue-500/40 bg-blue-500/10 p-2.5 text-xs text-blue-900 dark:text-blue-200">
                        <Info className="h-4 w-4 shrink-0 text-blue-600 dark:text-blue-400" />
                        <span>
                            <strong>Low Stock Notice:</strong> Pharmacy has only <strong>{availableStockNum}</strong> units remaining of this medication.
                        </span>
                    </div>
                )}

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

            {nhifEligible && (
                <div className="flex items-center gap-2 rounded-md border border-amber-500/30 bg-amber-500/5 p-2.5 text-sm">
                    <Checkbox
                        id="send_preapproval"
                        checked={data.send_preapproval !== false}
                        onCheckedChange={(checked) => setData('send_preapproval', Boolean(checked))}
                    />
                    <label htmlFor="send_preapproval" className="cursor-pointer select-none text-xs">
                        <span className="font-semibold text-foreground">Submit NHIF Pre-Approval Request immediately</span>
                        <span className="block text-muted-foreground">Automatically packages medications and sends pre-approval request to NHIF upon writing prescription.</span>
                    </label>
                </div>
            )}

            <div className="flex justify-end">
                <Button type="submit" size="sm" disabled={processing || data.items.length === 0}>
                    {processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <ClipboardPlus className="h-4 w-4" />}
                    Create Prescription
                </Button>
            </div>

            <Dialog open={stockConfirmOpen} onOpenChange={setStockConfirmOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-amber-700 dark:text-amber-400">
                            <AlertTriangle className="h-5 w-5" />
                            Insufficient Stock Warning
                        </DialogTitle>
                        <DialogDescription>
                            One or more prescribed medications currently have insufficient stock in the hospital pharmacy:
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-2">
                        <ul className="divide-y rounded-md border border-amber-500/30 bg-amber-500/5 text-xs">
                            {stockDeficits.map((item, idx) => {
                                const avail = item.medication.available_stock ?? 0;
                                const req = Number(item.quantity) || 0;
                                return (
                                    <li key={idx} className="flex items-center justify-between p-2.5">
                                        <div>
                                            <span className="font-semibold text-foreground">{item.medication.generic_name}</span>
                                            {item.medication.brand_name && (
                                                <span className="text-muted-foreground text-[11px]"> ({item.medication.brand_name})</span>
                                            )}
                                        </div>
                                        <div className="text-right">
                                            {avail === 0 ? (
                                                <span className="font-bold text-red-600 dark:text-red-400">Out of Stock (0 avail)</span>
                                            ) : (
                                                <span className="font-bold text-amber-700 dark:text-amber-400">
                                                    Req: {req} · Avail: {avail}
                                                </span>
                                            )}
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                        <p className="text-muted-foreground text-xs">
                            The pharmacy may not be able to immediately dispense the requested quantity without procuring stock or issuing an alternative. Do you still wish to submit this prescription request?
                        </p>
                    </div>

                    <DialogFooter className="gap-2 sm:gap-0">
                        <Button type="button" variant="outline" size="sm" onClick={() => setStockConfirmOpen(false)}>
                            Adjust Prescription
                        </Button>
                        <Button type="button" size="sm" onClick={submitOrder} disabled={processing}>
                            {processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <ClipboardPlus className="h-4 w-4" />}
                            Confirm & Send Request
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </form>
    );
}
