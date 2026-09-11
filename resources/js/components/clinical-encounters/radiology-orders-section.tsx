import { CancelRadiologyOrderDialog } from '@/components/clinical-encounters/cancel-radiology-order-dialog';
import { CancelRadiologyOrderItemDialog } from '@/components/clinical-encounters/cancel-radiology-order-item-dialog';
import InputError from '@/components/input-error';
import { RadiologyOrderProcedureSelector } from '@/components/radiology/radiology-order-procedure-selector';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { usePermissions } from '@/hooks/use-permissions';
import { radiologyModalityLabel, radiologyOrderPriorityLabel, radiologyOrderStatusLabel, radiologyOrderStatusVariant } from '@/lib/radiology-status';
import { type Consultation } from '@/types/consultation';
import {
    type RadiologyOrder,
    type RadiologyOrderFormData,
    type RadiologyOrderPriority,
    type RadiologyProcedureSearchResult,
} from '@/types/radiology';
import { Link, useForm } from '@inertiajs/react';
import { ClipboardPlus, LoaderCircle, XCircle } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

interface RadiologyOrdersSectionProps {
    encounterId: number;
    consultation: Consultation | null;
    radiologyOrders: RadiologyOrder[];
}

const PRIORITIES: RadiologyOrderPriority[] = ['routine', 'urgent', 'stat'];

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * Radiology is its own sibling branch off the clinical encounter (Phase 5,
 * Step 36) — embedded on the encounter screen, mirroring
 * LaboratoryOrdersSection/DiagnosisSection/CarePlanSection exactly, never a
 * standalone create/edit page. Requires the consultation to be in_progress
 * or completed, same as Laboratory — but deliberately has no diagnosis
 * requirement, since urgent imaging must not be blocked on a final
 * diagnosis not yet being entered. Ends at ORDERED — scheduling/
 * examination/reporting/release are Steps 38-44.
 */
export function RadiologyOrdersSection({ encounterId, consultation, radiologyOrders }: RadiologyOrdersSectionProps) {
    const { can } = usePermissions();
    const consultationEngaged = consultation?.status === 'in_progress' || consultation?.status === 'completed';

    if (!consultationEngaged) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Radiology</CardTitle>
                </CardHeader>
                <CardContent>
                    <p className="text-muted-foreground text-sm">A consultation must be started before a radiology order can be placed.</p>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Radiology</CardTitle>
            </CardHeader>
            <CardContent className="space-y-6">
                {radiologyOrders.length > 0 ? (
                    <ul className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                        {radiologyOrders.map((order) => (
                            <RadiologyOrderRow key={order.id} encounterId={encounterId} order={order} />
                        ))}
                    </ul>
                ) : (
                    <p className="text-muted-foreground text-sm">No radiology orders have been placed for this encounter yet.</p>
                )}

                {can('radiology-orders.create') && <AddRadiologyOrderForm encounterId={encounterId} />}
            </CardContent>
        </Card>
    );
}

function RadiologyOrderRow({ encounterId, order }: { encounterId: number; order: RadiologyOrder }) {
    const { can } = usePermissions();
    const mutable = order.status === 'ordered';

    return (
        <li className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <Link href={route('radiology.orders.show', order.id)} className="font-medium hover:underline">
                        {order.order_number}
                    </Link>
                    <Badge variant={radiologyOrderStatusVariant[order.status]}>{radiologyOrderStatusLabel[order.status]}</Badge>
                    <span className="text-muted-foreground text-xs">{radiologyOrderPriorityLabel[order.priority]} priority</span>
                </div>

                {mutable && can('radiology-orders.cancel') && (
                    <CancelRadiologyOrderDialog
                        encounterId={encounterId}
                        orderId={order.id}
                        trigger={
                            <Button size="sm" variant="destructive">
                                <XCircle className="h-4 w-4" />
                                Cancel Order
                            </Button>
                        }
                    />
                )}
            </div>

            <ul className="space-y-1 text-sm">
                {order.items.map((item) => (
                    <li key={item.id} className="flex flex-wrap items-center justify-between gap-2">
                        <span className={item.status === 'cancelled' ? 'text-muted-foreground line-through' : ''}>
                            {item.procedure_code} — {item.procedure_name}
                            {item.modality_snapshot && (
                                <span className="text-muted-foreground"> ({radiologyModalityLabel[item.modality_snapshot]})</span>
                            )}
                        </span>

                        {mutable && item.status === 'ordered' && can('radiology-orders.update') && (
                            <CancelRadiologyOrderItemDialog
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
                {order.clinical_indication && <span>Indication: {order.clinical_indication}</span>}
                {order.notes && <span>{order.notes}</span>}
                <span>
                    {order.ordered_by?.name ?? '—'} · {formatDateTime(order.ordered_at)}
                </span>
                {order.status === 'cancelled' && order.cancellation_reason && <span>Cancelled: {order.cancellation_reason}</span>}
            </div>
        </li>
    );
}

function AddRadiologyOrderForm({ encounterId }: { encounterId: number }) {
    const [selectedProcedures, setSelectedProcedures] = useState<RadiologyProcedureSearchResult[]>([]);
    const { data, setData, post, processing, errors, reset } = useForm<RadiologyOrderFormData>({
        procedure_ids: [],
        priority: '',
        clinical_indication: '',
        notes: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('clinical-encounters.radiology-orders.store', encounterId), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setSelectedProcedures([]);
            },
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4 border-t pt-4">
            <h3 className="text-sm font-medium">New Radiology Order</h3>

            <div className="grid gap-1.5">
                <label className="text-sm font-medium">Procedures</label>
                <RadiologyOrderProcedureSelector
                    selected={selectedProcedures}
                    onChange={(procedures) => {
                        setSelectedProcedures(procedures);
                        setData(
                            'procedure_ids',
                            procedures.map((p) => p.id),
                        );
                    }}
                />
                <InputError message={errors.procedure_ids} />
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-1.5">
                    <label className="text-sm font-medium">Priority</label>
                    <Select value={data.priority} onValueChange={(value) => setData('priority', value as RadiologyOrderPriority)}>
                        <SelectTrigger>
                            <SelectValue placeholder="Routine" />
                        </SelectTrigger>
                        <SelectContent>
                            {PRIORITIES.map((priority) => (
                                <SelectItem key={priority} value={priority}>
                                    {radiologyOrderPriorityLabel[priority]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.priority} />
                </div>
            </div>

            <div className="grid gap-1.5">
                <label className="text-sm font-medium" htmlFor="rad_order_clinical_indication">
                    Clinical Indication
                </label>
                <Textarea
                    id="rad_order_clinical_indication"
                    rows={2}
                    value={data.clinical_indication}
                    onChange={(e) => setData('clinical_indication', e.target.value)}
                />
                <InputError message={errors.clinical_indication} />
            </div>

            <div className="grid gap-1.5">
                <label className="text-sm font-medium" htmlFor="rad_order_notes">
                    Notes
                </label>
                <Textarea id="rad_order_notes" rows={2} value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                <InputError message={errors.notes} />
            </div>

            <div className="flex justify-end">
                <Button type="submit" size="sm" disabled={processing || selectedProcedures.length === 0}>
                    {processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <ClipboardPlus className="h-4 w-4" />}
                    Create Radiology Order
                </Button>
            </div>
        </form>
    );
}
