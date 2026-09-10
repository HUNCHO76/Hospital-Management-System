import { CancelLaboratoryOrderDialog } from '@/components/clinical-encounters/cancel-laboratory-order-dialog';
import { CancelLaboratoryOrderItemDialog } from '@/components/clinical-encounters/cancel-laboratory-order-item-dialog';
import InputError from '@/components/input-error';
import { LaboratoryOrderTestSelector } from '@/components/laboratory/laboratory-order-test-selector';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { usePermissions } from '@/hooks/use-permissions';
import { laboratoryOrderPriorityLabel, laboratoryOrderStatusLabel, laboratoryOrderStatusVariant } from '@/lib/laboratory-status';
import { type Consultation } from '@/types/consultation';
import {
    type LaboratoryOrder,
    type LaboratoryOrderFormData,
    type LaboratoryOrderPriority,
    type LaboratoryTestSearchResult,
} from '@/types/laboratory';
import { Link, useForm } from '@inertiajs/react';
import { ClipboardPlus, LoaderCircle, XCircle } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

interface LaboratoryOrdersSectionProps {
    encounterId: number;
    consultation: Consultation | null;
    laboratoryOrders: LaboratoryOrder[];
}

const PRIORITIES: LaboratoryOrderPriority[] = ['routine', 'urgent', 'stat'];

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * Laboratory is its own sibling branch off the clinical encounter (Phase 4,
 * Step 30) — embedded on the encounter screen, mirroring
 * DiagnosisSection/CarePlanSection, never a standalone create/edit page.
 * Requires the consultation to be in_progress or completed, same as
 * Diagnosis — but deliberately has no diagnosis requirement, since urgent
 * laboratory testing must not be blocked on a final diagnosis not yet being
 * entered.
 */
export function LaboratoryOrdersSection({ encounterId, consultation, laboratoryOrders }: LaboratoryOrdersSectionProps) {
    const { can } = usePermissions();
    const consultationEngaged = consultation?.status === 'in_progress' || consultation?.status === 'completed';

    if (!consultationEngaged) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Laboratory</CardTitle>
                </CardHeader>
                <CardContent>
                    <p className="text-muted-foreground text-sm">A consultation must be started before a laboratory order can be placed.</p>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Laboratory</CardTitle>
            </CardHeader>
            <CardContent className="space-y-6">
                {laboratoryOrders.length > 0 ? (
                    <ul className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                        {laboratoryOrders.map((order) => (
                            <LaboratoryOrderRow key={order.id} encounterId={encounterId} order={order} />
                        ))}
                    </ul>
                ) : (
                    <p className="text-muted-foreground text-sm">No laboratory orders have been placed for this encounter yet.</p>
                )}

                {can('laboratory-orders.create') && <AddLaboratoryOrderForm encounterId={encounterId} />}
            </CardContent>
        </Card>
    );
}

function LaboratoryOrderRow({ encounterId, order }: { encounterId: number; order: LaboratoryOrder }) {
    const { can } = usePermissions();
    const mutable = order.status === 'ordered';

    return (
        <li className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <Link href={route('laboratory.orders.show', order.id)} className="font-medium hover:underline">
                        {order.order_number}
                    </Link>
                    <Badge variant={laboratoryOrderStatusVariant[order.status]}>{laboratoryOrderStatusLabel[order.status]}</Badge>
                    <span className="text-muted-foreground text-xs">{laboratoryOrderPriorityLabel[order.priority]} priority</span>
                </div>

                {mutable && can('laboratory-orders.cancel') && (
                    <CancelLaboratoryOrderDialog
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
                            {item.test_code} — {item.test_name}
                        </span>

                        {mutable && item.status === 'ordered' && can('laboratory-orders.update') && (
                            <CancelLaboratoryOrderItemDialog
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

function AddLaboratoryOrderForm({ encounterId }: { encounterId: number }) {
    const [selectedTests, setSelectedTests] = useState<LaboratoryTestSearchResult[]>([]);
    const { data, setData, post, processing, errors, reset } = useForm<LaboratoryOrderFormData>({
        test_ids: [],
        priority: '',
        clinical_indication: '',
        notes: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('clinical-encounters.laboratory-orders.store', encounterId), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setSelectedTests([]);
            },
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4 border-t pt-4">
            <h3 className="text-sm font-medium">New Laboratory Order</h3>

            <div className="grid gap-1.5">
                <label className="text-sm font-medium">Tests</label>
                <LaboratoryOrderTestSelector
                    selected={selectedTests}
                    onChange={(tests) => {
                        setSelectedTests(tests);
                        setData(
                            'test_ids',
                            tests.map((t) => t.id),
                        );
                    }}
                />
                <InputError message={errors.test_ids} />
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-1.5">
                    <label className="text-sm font-medium">Priority</label>
                    <Select value={data.priority} onValueChange={(value) => setData('priority', value as LaboratoryOrderPriority)}>
                        <SelectTrigger>
                            <SelectValue placeholder="Routine" />
                        </SelectTrigger>
                        <SelectContent>
                            {PRIORITIES.map((priority) => (
                                <SelectItem key={priority} value={priority}>
                                    {laboratoryOrderPriorityLabel[priority]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.priority} />
                </div>
            </div>

            <div className="grid gap-1.5">
                <label className="text-sm font-medium" htmlFor="lab_order_clinical_indication">
                    Clinical Indication
                </label>
                <Textarea
                    id="lab_order_clinical_indication"
                    rows={2}
                    value={data.clinical_indication}
                    onChange={(e) => setData('clinical_indication', e.target.value)}
                />
                <InputError message={errors.clinical_indication} />
            </div>

            <div className="grid gap-1.5">
                <label className="text-sm font-medium" htmlFor="lab_order_notes">
                    Notes
                </label>
                <Textarea id="lab_order_notes" rows={2} value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                <InputError message={errors.notes} />
            </div>

            <div className="flex justify-end">
                <Button type="submit" size="sm" disabled={processing || selectedTests.length === 0}>
                    {processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <ClipboardPlus className="h-4 w-4" />}
                    Create Laboratory Order
                </Button>
            </div>
        </form>
    );
}
