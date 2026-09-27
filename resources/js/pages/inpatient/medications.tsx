import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Admission } from '@/types/admission';
import { Head, Link, useForm } from '@inertiajs/react';
import { Activity, BedDouble, CheckCircle, Clock, Pill, XCircle } from 'lucide-react';
import { useState } from 'react';

interface MedAdministration {
    id: number;
    status: string;
    administered_at: string;
    dose: string;
    dose_unit: string;
    route: string;
    reason: string | null;
    notes: string | null;
    corrects_id: number | null;
    administeredBy?: { id: number; UserName: string } | null;
}

interface DispensingItem {
    id: number;
    dispensed_quantity: number;
    status: string;
}

interface PrescriptionOrderItem {
    id: number;
    medication_name_snapshot: string;
    medication_code_snapshot: string;
    dose: string;
    dose_unit: string;
    frequency: string;
    route: string;
    duration: number | null;
    duration_unit: string | null;
    instructions: string | null;
    status: string;
    dispensingItems: DispensingItem[];
}

interface PrescriptionOrder {
    id: number;
    items: PrescriptionOrderItem[];
    clinicalEncounter: { id: number; encounter_number: string; started_at: string } | null;
}

interface MedicationsProps {
    admission: Admission;
    prescriptionOrders: PrescriptionOrder[];
    administrations: MedAdministration[];
    marStatuses: string[];
    holdReasons: string[];
    routes: string[];
    canAdminister: boolean;
    canHold: boolean;
    canRecordRefusal: boolean;
    canRecordMissed: boolean;
    canCorrect: boolean;
}

const statusVariant: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    administered: 'default',
    held: 'outline',
    refused: 'secondary',
    missed: 'destructive',
};

const statusLabel: Record<string, string> = {
    administered: 'Administered',
    held: 'Held',
    refused: 'Refused',
    missed: 'Missed',
};

const holdReasonLabel: Record<string, string> = {
    patient_refused: 'Patient Refused',
    patient_npo: 'Patient NPO',
    patient_unavailable: 'Patient Unavailable',
    clinical_hold: 'Clinical Hold',
    medication_unavailable: 'Medication Unavailable',
    adverse_reaction_risk: 'Adverse Reaction Risk',
    prescriber_instruction: 'Prescriber Instruction',
    other: 'Other',
};

function frequencyLabel(f: string): string {
    return f.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatDateTime(value: string | null | undefined): string {
    if (!value) return '—';
    return new Date(value).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });
}

interface AdministerFormData {
    [key: string]: string | boolean;
    prescription_order_item_id: string;
    clinical_encounter_id: string;
    dispensing_item_id: string;
    status: string;
    administered_at: string;
    scheduled_at: string;
    dose: string;
    dose_unit: string;
    route: string;
    is_prn: boolean;
    reason: string;
    notes: string;
}

export default function InpatientMedications({
    admission,
    prescriptionOrders,
    administrations,
    routes,
    holdReasons,
    canAdminister,
    canHold,
    canRecordRefusal,
    canRecordMissed,
    canCorrect,
}: MedicationsProps) {
    const patientName = admission.patient
        ? `${admission.patient.first_name} ${admission.patient.last_name}`
        : 'Unknown Patient';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Inpatient Ward', href: route('inpatient.dashboard') },
        { title: patientName, href: route('inpatient.clinical-profile', admission.id) },
        { title: 'Medications', href: '#' },
    ];

    const [selectedItem, setSelectedItem] = useState<PrescriptionOrderItem | null>(null);
    const [selectedAction, setSelectedAction] = useState<string>('administered');
    const [selectedEncounterId, setSelectedEncounterId] = useState<string>('');
    const [dialogOpen, setDialogOpen] = useState(false);

    const administerForm = useForm<AdministerFormData>({
        prescription_order_item_id: '',
        clinical_encounter_id: '',
        dispensing_item_id: '',
        status: 'administered',
        administered_at: new Date().toISOString().slice(0, 16),
        scheduled_at: '',
        dose: '',
        dose_unit: '',
        route: '',
        is_prn: false,
        reason: '',
        notes: '',
    });

    const openDialog = (item: PrescriptionOrderItem, action: string, encounterId: string) => {
        setSelectedItem(item);
        setSelectedAction(action);
        setSelectedEncounterId(encounterId);
        administerForm.setData({
            prescription_order_item_id: String(item.id),
            clinical_encounter_id: encounterId,
            dispensing_item_id: '',
            status: action,
            administered_at: new Date().toISOString().slice(0, 16),
            scheduled_at: '',
            dose: item.dose,
            dose_unit: item.dose_unit,
            route: item.route,
            is_prn: false,
            reason: '',
            notes: '',
        });
        setDialogOpen(true);
    };

    const submitAdminister = () => {
        administerForm.post(route('inpatient.medications.store', admission.id), {
            onSuccess: () => setDialogOpen(false),
        });
    };

    // Per-item administration history
    const adminsByItem = administrations.reduce<Record<number, MedAdministration[]>>((acc, a) => {
        const key = a.corrects_id ?? a.id;
        // group by prescription_order_item_id — we actually need it but it's in the mar object
        return acc;
    }, {});

    const lastAdminByItem = administrations.reduce<Record<string, MedAdministration>>((acc, a) => {
        // keyed by prescription_order_item_id — need to find from the render loop
        return acc;
    }, {});

    const needsReason = ['held', 'refused', 'missed'].includes(selectedAction);

    const allItems = prescriptionOrders.flatMap((order) =>
        order.items.map((item) => ({ item, order })),
    );

    const adminsByItemId = administrations.reduce<Record<number, MedAdministration[]>>((acc, a) => {
        // We know prescription_order_item_id from backend but it's not typed above
        // The backend sends it; cast via unknown
        const itemId = (a as unknown as { prescription_order_item_id: number }).prescription_order_item_id;
        if (!acc[itemId]) acc[itemId] = [];
        acc[itemId].push(a);
        return acc;
    }, {});

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Medications — ${patientName}`} />

            <div className="flex flex-col gap-6 p-4">
                <PageHeader
                    title="Medication Administration Record"
                    description={`${patientName} · Admission ${admission.admission_number}`}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={route('inpatient.clinical-profile', admission.id)}>
                                Clinical Profile
                            </Link>
                        </Button>
                    }
                />

                {allItems.length === 0 ? (
                    <EmptyState
                        icon={Pill}
                        title="No active prescriptions"
                        description="No active medications are prescribed for this admission. Prescriptions must be written in a clinical encounter and dispensed by pharmacy before administration."
                    />
                ) : (
                    allItems.map(({ item, order }) => {
                        const itemAdmins = adminsByItemId[item.id] ?? [];
                        const lastAdmin = itemAdmins.find((a) => a.corrects_id === null);
                        const dispensedQty = item.dispensingItems
                            .filter((d) => d.status === 'dispensed')
                            .reduce((sum, d) => sum + d.dispensed_quantity, 0);

                        return (
                            <Card key={item.id}>
                                <CardHeader className="pb-2">
                                    <div className="flex items-start justify-between gap-2">
                                        <div>
                                            <CardTitle className="flex items-center gap-2 text-base">
                                                <Pill className="h-4 w-4" />
                                                {item.medication_name_snapshot}
                                                <span className="text-muted-foreground font-mono text-xs">
                                                    {item.medication_code_snapshot}
                                                </span>
                                            </CardTitle>
                                            <p className="text-muted-foreground mt-1 text-sm">
                                                {item.dose} {item.dose_unit} · {frequencyLabel(item.frequency)} · {item.route}
                                                {item.duration && item.duration_unit ? ` · ${item.duration} ${item.duration_unit}` : ''}
                                            </p>
                                            {item.instructions && (
                                                <p className="text-muted-foreground mt-1 text-xs italic">{item.instructions}</p>
                                            )}
                                        </div>
                                        <div className="text-right text-sm">
                                            <div className="text-muted-foreground flex items-center gap-1">
                                                <BedDouble className="h-3 w-3" />
                                                {order.clinicalEncounter?.encounter_number ?? '—'}
                                            </div>
                                            <div className="text-muted-foreground text-xs">Dispensed: {dispensedQty}</div>
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    {/* Last administration status */}
                                    {lastAdmin && (
                                        <div className="mb-3 flex items-center gap-2 text-sm">
                                            {lastAdmin.status === 'administered' ? (
                                                <CheckCircle className="h-4 w-4 text-green-600" />
                                            ) : (
                                                <XCircle className="text-muted-foreground h-4 w-4" />
                                            )}
                                            <Badge variant={statusVariant[lastAdmin.status]}>
                                                {statusLabel[lastAdmin.status]}
                                            </Badge>
                                            <span className="text-muted-foreground">
                                                {formatDateTime(lastAdmin.administered_at)}
                                            </span>
                                            {lastAdmin.reason && (
                                                <span className="text-muted-foreground text-xs">— {lastAdmin.reason}</span>
                                            )}
                                        </div>
                                    )}

                                    {/* Action buttons */}
                                    <div className="flex flex-wrap gap-2">
                                        {canAdminister && (
                                            <Button
                                                size="sm"
                                                onClick={() => openDialog(item, 'administered', String(order.clinicalEncounter?.id ?? ''))}
                                            >
                                                <CheckCircle className="mr-1 h-3 w-3" />
                                                Administer
                                            </Button>
                                        )}
                                        {canHold && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => openDialog(item, 'held', String(order.clinicalEncounter?.id ?? ''))}
                                            >
                                                <Clock className="mr-1 h-3 w-3" />
                                                Hold
                                            </Button>
                                        )}
                                        {canRecordRefusal && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => openDialog(item, 'refused', String(order.clinicalEncounter?.id ?? ''))}
                                            >
                                                <XCircle className="mr-1 h-3 w-3" />
                                                Refused
                                            </Button>
                                        )}
                                        {canRecordMissed && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => openDialog(item, 'missed', String(order.clinicalEncounter?.id ?? ''))}
                                            >
                                                <Activity className="mr-1 h-3 w-3" />
                                                Missed
                                            </Button>
                                        )}
                                    </div>

                                    {/* Recent history */}
                                    {itemAdmins.length > 0 && (
                                        <div className="mt-4">
                                            <p className="text-muted-foreground mb-2 text-xs font-medium uppercase tracking-wide">
                                                History
                                            </p>
                                            <div className="flex flex-col gap-1">
                                                {itemAdmins.slice(0, 5).map((a) => (
                                                    <div
                                                        key={a.id}
                                                        className={`flex items-center gap-2 text-xs ${a.corrects_id !== null ? 'text-muted-foreground line-through' : ''}`}
                                                    >
                                                        <Badge variant={statusVariant[a.status]} className="text-xs">
                                                            {statusLabel[a.status]}
                                                        </Badge>
                                                        <span>{formatDateTime(a.administered_at)}</span>
                                                        <span className="text-muted-foreground">
                                                            {a.administeredBy?.UserName ?? '—'}
                                                        </span>
                                                        {a.reason && <span>— {a.reason}</span>}
                                                        {canCorrect && a.corrects_id === null && (
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                className="h-5 px-1 text-xs"
                                                                asChild
                                                            >
                                                                <Link
                                                                    href={route('inpatient.medications.correct', {
                                                                        admission: admission.id,
                                                                        medicationAdministration: a.id,
                                                                    })}
                                                                >
                                                                    Correct
                                                                </Link>
                                                            </Button>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        );
                    })
                )}
            </div>

            {/* Administer / Hold / Refuse / Miss dialog */}
            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {statusLabel[selectedAction] ?? selectedAction} —{' '}
                            {selectedItem?.medication_name_snapshot}
                        </DialogTitle>
                    </DialogHeader>

                    <div className="flex flex-col gap-4 py-2">
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label htmlFor="dose">Dose</Label>
                                <Input
                                    id="dose"
                                    value={administerForm.data.dose}
                                    onChange={(e) => administerForm.setData('dose', e.target.value)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="dose_unit">Unit</Label>
                                <Input
                                    id="dose_unit"
                                    value={administerForm.data.dose_unit}
                                    onChange={(e) => administerForm.setData('dose_unit', e.target.value)}
                                />
                            </div>
                        </div>

                        <div>
                            <Label htmlFor="route">Route</Label>
                            <Select
                                value={administerForm.data.route}
                                onValueChange={(v) => administerForm.setData('route', v)}
                            >
                                <SelectTrigger id="route">
                                    <SelectValue placeholder="Select route" />
                                </SelectTrigger>
                                <SelectContent>
                                    {routes.map((r) => (
                                        <SelectItem key={r} value={r}>
                                            {r.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label htmlFor="administered_at">Date / Time</Label>
                            <Input
                                id="administered_at"
                                type="datetime-local"
                                value={administerForm.data.administered_at}
                                onChange={(e) => administerForm.setData('administered_at', e.target.value)}
                            />
                        </div>

                        {needsReason && (
                            <div>
                                <Label htmlFor="reason">
                                    Reason <span className="text-destructive">*</span>
                                </Label>
                                {selectedAction === 'held' ? (
                                    <Select
                                        value={administerForm.data.reason}
                                        onValueChange={(v) => administerForm.setData('reason', v)}
                                    >
                                        <SelectTrigger id="reason">
                                            <SelectValue placeholder="Select reason" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {holdReasons.map((r) => (
                                                <SelectItem key={r} value={r}>
                                                    {holdReasonLabel[r] ?? r}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                ) : (
                                    <Textarea
                                        id="reason"
                                        value={administerForm.data.reason}
                                        onChange={(e) => administerForm.setData('reason', e.target.value)}
                                        rows={2}
                                    />
                                )}
                            </div>
                        )}

                        <div>
                            <Label htmlFor="notes">Notes (optional)</Label>
                            <Textarea
                                id="notes"
                                value={administerForm.data.notes}
                                onChange={(e) => administerForm.setData('notes', e.target.value)}
                                rows={2}
                            />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDialogOpen(false)}>
                            Cancel
                        </Button>
                        <Button onClick={submitAdminister} disabled={administerForm.processing}>
                            Confirm {statusLabel[selectedAction] ?? selectedAction}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
