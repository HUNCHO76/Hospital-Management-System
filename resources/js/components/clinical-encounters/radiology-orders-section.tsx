import { CancelRadiologyOrderDialog } from '@/components/clinical-encounters/cancel-radiology-order-dialog';
import { CancelRadiologyOrderItemDialog } from '@/components/clinical-encounters/cancel-radiology-order-item-dialog';
import InputError from '@/components/input-error';
import { BiometricIssueDialog } from '@/components/nhif/biometric-issue-dialog';
import { RadiologyOrderProcedureSelector } from '@/components/radiology/radiology-order-procedure-selector';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { usePermissions } from '@/hooks/use-permissions';
import { useToast } from '@/hooks/use-toast';
import { radiologyModalityLabel, radiologyOrderPriorityLabel, radiologyOrderStatusLabel, radiologyOrderStatusVariant } from '@/lib/radiology-status';
import { type Consultation } from '@/types/consultation';
import { type NhifInsuranceSummary, type NhifPreApprovalSession } from '@/types/nhif';
import {
    type RadiologyOrder,
    type RadiologyOrderFormData,
    type RadiologyOrderPriority,
    type RadiologyProcedureSearchResult,
} from '@/types/radiology';
import { Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, ClipboardPlus, Fingerprint, LoaderCircle, Send, XCircle } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

interface RadiologyOrdersSectionProps {
    encounterId: number;
    consultation: Consultation | null;
    radiologyOrders: RadiologyOrder[];
    nhifEligible?: boolean;
    nhifInsurance?: NhifInsuranceSummary | null;
    nhifPreApprovalSessions?: NhifPreApprovalSession[];
    patientName?: string;
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
export function RadiologyOrdersSection({
    encounterId,
    consultation,
    radiologyOrders,
    nhifEligible,
    nhifInsurance,
    nhifPreApprovalSessions,
    patientName,
}: RadiologyOrdersSectionProps) {
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

    const hasPendingNhif = Boolean(
        nhifEligible &&
        radiologyOrders.some(
            (order) =>
                order.nhif_pre_approval?.status === 'nhif_pending_request' ||
                order.nhif_pre_approval?.status === 'nhif_sent_not_issued'
        )
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Radiology</CardTitle>
            </CardHeader>
            <CardContent className="space-y-6">
                {hasPendingNhif && (
                    <div className="flex items-start gap-3 rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-amber-900 dark:text-amber-200 text-sm">
                        <AlertTriangle className="h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400 mt-0.5" />
                        <div className="space-y-1">
                            <p className="font-medium">NHIF Pre-Approval Required</p>
                            <p className="text-xs text-muted-foreground">
                                Radiology orders for NHIF patients must have their pre-approval requested and issued with biometric verification before scheduling and examination.
                            </p>
                        </div>
                    </div>
                )}

                {radiologyOrders.length > 0 ? (
                    <ul className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                        {radiologyOrders.map((order) => (
                            <RadiologyOrderRow
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
                    <p className="text-muted-foreground text-sm">No radiology orders have been placed for this encounter yet.</p>
                )}

                {can('radiology-orders.create') && (
                    <AddRadiologyOrderForm
                        encounterId={encounterId}
                        nhifEligible={nhifEligible}
                    />
                )}
            </CardContent>
        </Card>
    );
}

function RadiologyOrderRow({
    encounterId,
    order,
    nhifEligible,
    nhifInsurance,
    sessions,
}: {
    encounterId: number;
    order: RadiologyOrder;
    nhifEligible?: boolean;
    nhifInsurance?: NhifInsuranceSummary | null;
    sessions?: NhifPreApprovalSession[];
}) {
    const { can } = usePermissions();
    const { toast } = useToast();
    const mutable = order.status === 'ordered';
    const [submittingPreApproval, setSubmittingPreApproval] = useState(false);
    const [issueDialogOpen, setIssueDialogOpen] = useState(false);

    const preApproval = order.nhif_pre_approval;
    const activeItemIds = order.items.filter((item) => item.status === 'ordered').map((item) => item.id);
    const sessionId = preApproval?.session_id ?? sessions?.find((s) => s.items.some((i) => i.source_type === 'radiology' && activeItemIds.includes(i.source_id)))?.id;

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
                radiology_order_item_ids: activeItemIds,
                clinical_notes: order.clinical_indication || order.notes || undefined,
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

    return (
        <li className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <Link href={route('radiology.orders.show', order.id)} className="font-medium hover:underline">
                        {order.order_number}
                    </Link>
                    <Badge variant={radiologyOrderStatusVariant[order.status]}>{radiologyOrderStatusLabel[order.status]}</Badge>
                    <span className="text-muted-foreground text-xs">{radiologyOrderPriorityLabel[order.priority]} priority</span>
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
                    {preApproval?.status === 'nhif_pending_request' && mutable && can('radiology-orders.update') && (
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

function AddRadiologyOrderForm({
    encounterId,
    nhifEligible,
}: {
    encounterId: number;
    nhifEligible?: boolean;
}) {
    const [selectedProcedures, setSelectedProcedures] = useState<RadiologyProcedureSearchResult[]>([]);
    const { data, setData, post, processing, errors, reset } = useForm<RadiologyOrderFormData>({
        procedure_ids: [],
        priority: '',
        clinical_indication: '',
        notes: '',
        send_preapproval: nhifEligible ? true : false,
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
                    clinicalEncounterId={encounterId}
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

            {nhifEligible && (
                <div className="flex items-center gap-2 rounded-md border border-amber-500/30 bg-amber-500/5 p-2.5 text-sm">
                    <Checkbox
                        id="radiology_send_preapproval"
                        checked={data.send_preapproval !== false}
                        onCheckedChange={(checked) => setData('send_preapproval', Boolean(checked))}
                    />
                    <label htmlFor="radiology_send_preapproval" className="cursor-pointer select-none text-xs">
                        <span className="font-semibold text-foreground">Submit NHIF Pre-Approval Request immediately</span>
                        <span className="block text-muted-foreground">Automatically packages radiology procedures and sends pre-approval request to NHIF upon placing order.</span>
                    </label>
                </div>
            )}

            <div className="flex justify-end">
                <Button type="submit" size="sm" disabled={processing || selectedProcedures.length === 0}>
                    {processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <ClipboardPlus className="h-4 w-4" />}
                    Create Radiology Order
                </Button>
            </div>
        </form>
    );
}
