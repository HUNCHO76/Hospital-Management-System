import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { usePermissions } from '@/hooks/use-permissions';
import { type Consultation } from '@/types/consultation';
import { type NhifInsuranceSummary, type NhifPreApprovalSession } from '@/types/nhif';
import {
    PROCEDURE_STATUS_LABELS,
    PROCEDURE_STATUS_VARIANTS,
    PROCEDURE_TYPE_LABELS,
    PROCEDURE_URGENCY_LABELS,
    type ProcedureCatalogueItem,
    type ProcedureOrder,
    type ProcedureType,
    type ProcedureUrgency,
} from '@/types/procedure';
import { useForm } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    CheckCircle2,
    Clock,
    FileText,
    LoaderCircle,
    PlusCircle,
    Search,
    ShieldAlert,
    ShieldCheck,
    Stethoscope,
    XCircle,
} from 'lucide-react';
import { type FormEventHandler, useEffect, useState } from 'react';
import { CancelProcedureOrderDialog } from './cancel-procedure-order-dialog';
import { ExecuteProcedureDialog } from './execute-procedure-dialog';

interface ProcedureOrdersSectionProps {
    encounterId: number;
    consultation: Consultation | null;
    procedureOrders: ProcedureOrder[];
    nhifEligible?: boolean;
    nhifInsurance?: NhifInsuranceSummary | null;
    nhifPreApprovalSessions?: NhifPreApprovalSession[];
    patientName?: string;
}

export function ProcedureOrdersSection({
    encounterId,
    consultation,
    procedureOrders,
    nhifEligible,
    nhifInsurance,
    nhifPreApprovalSessions,
    patientName,
}: ProcedureOrdersSectionProps) {
    const { can } = usePermissions();
    const consultationEngaged = consultation?.status === 'in_progress' || consultation?.status === 'completed';

    const [catalogueSearch, setCatalogueSearch] = useState('');
    const [catalogueItems, setCatalogueItems] = useState<ProcedureCatalogueItem[]>([]);
    const [searching, setSearching] = useState(false);
    const [selectedItem, setSelectedItem] = useState<ProcedureCatalogueItem | null>(null);

    const { data, setData, post, processing, errors, reset } = useForm({
        service_id: '',
        procedure_name: '',
        procedure_type: 'bedside_procedure' as ProcedureType,
        urgency: 'elective' as ProcedureUrgency,
        quantity: 1,
        clinical_indication: '',
        scheduled_at: '',
        notes: '',
        payment_mode: (nhifEligible ? 'nhif' : 'cash') as 'cash' | 'nhif' | 'insurance' | 'exempt',
        patient_consent_self_pay: false,
        send_preapproval: true,
        is_emergency_exemption: false,
        exemption_reason: '',
    });

    // Search hospital procedure catalogue
    useEffect(() => {
        let active = true;
        const fetchItems = async () => {
            setSearching(true);
            try {
                const url = route('clinical-encounters.procedure-catalogue.search', {
                    clinicalEncounter: encounterId,
                    q: catalogueSearch,
                });
                const res = await fetch(url, { headers: { Accept: 'application/json' } });
                if (res.ok && active) {
                    const data = await res.json();
                    setCatalogueItems(data);
                }
            } catch (err) {
                console.error('Failed to search procedures:', err);
            } finally {
                if (active) setSearching(false);
            }
        };

        const timeout = setTimeout(fetchItems, 300);
        return () => {
            active = false;
            clearTimeout(timeout);
        };
    }, [catalogueSearch, encounterId]);

    const handleSelectProcedure = (item: ProcedureCatalogueItem) => {
        setSelectedItem(item);
        setData((prev) => ({
            ...prev,
            service_id: String(item.id),
            procedure_name: item.name,
            payment_mode: nhifEligible && item.is_covered_under_scheme ? 'nhif' : 'cash',
            patient_consent_self_pay: nhifEligible && !item.is_covered_under_scheme,
        }));
    };

    const submitOrder: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('clinical-encounters.procedure-orders.store', encounterId), {
            onSuccess: () => {
                reset();
                setSelectedItem(null);
                setCatalogueSearch('');
            },
            preserveScroll: true,
        });
    };

    if (!consultationEngaged) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle className="text-base flex items-center gap-2">
                        <Stethoscope className="h-4 w-4" />
                        Clinical Procedures
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <p className="text-muted-foreground text-sm">
                        A consultation must be started before clinical procedures can be ordered.
                    </p>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle className="text-base flex items-center gap-2">
                        <Stethoscope className="h-5 w-5 text-primary" />
                        Clinical Procedures & Minor Operations
                    </CardTitle>
                    <Badge variant="outline">{procedureOrders.length} Ordered</Badge>
                </div>
            </CardHeader>
            <CardContent className="space-y-6">
                {/* ── Order Placement Form ── */}
                {can('procedures.order') && (
                    <form onSubmit={submitOrder} className="rounded-lg border bg-card p-4 space-y-4 shadow-sm">
                        <div className="flex items-center gap-2 text-sm font-semibold">
                            <PlusCircle className="h-4 w-4 text-primary" />
                            Prescribe Clinical Procedure
                        </div>

                        {/* Searchable Procedure Selector */}
                        <div className="space-y-2">
                            <Label htmlFor="search_procedure">Search Procedure Catalogue *</Label>
                            <div className="relative">
                                <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    id="search_procedure"
                                    placeholder="Type procedure name or code (e.g. Lord's, Circlage, Excision, Suture, Catheter...)"
                                    className="pl-9"
                                    value={catalogueSearch}
                                    onChange={(e) => setCatalogueSearch(e.target.value)}
                                />
                                {searching && <LoaderCircle className="absolute right-3 top-2.5 h-4 w-4 animate-spin text-muted-foreground" />}
                            </div>

                            {/* Dropdown Results */}
                            {catalogueItems.length > 0 && !selectedItem && (
                                <div className="max-h-56 overflow-y-auto rounded-md border bg-popover shadow-md divide-y text-sm">
                                    {catalogueItems.map((item) => (
                                        <button
                                            type="button"
                                            key={item.id}
                                            onClick={() => handleSelectProcedure(item)}
                                            className="w-full text-left p-2.5 hover:bg-muted/80 flex flex-col gap-1 transition-colors"
                                        >
                                            <div className="flex items-center justify-between font-medium">
                                                <span>{item.name}</span>
                                                <span className="text-xs text-muted-foreground font-mono">Code: {item.code}</span>
                                            </div>
                                            <div className="flex items-center gap-3 text-xs">
                                                <span className="font-semibold text-primary">
                                                    Cash: TZS {item.cash_price.toLocaleString()}
                                                </span>
                                                {nhifEligible ? (
                                                    item.is_covered_under_scheme ? (
                                                        <span className="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400 font-medium">
                                                            <ShieldCheck className="h-3.5 w-3.5" />
                                                            Covered under Scheme
                                                            {item.requires_preauth && ' (Pre-auth required)'}
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center gap-1 text-amber-600 dark:text-amber-400 font-medium">
                                                            <ShieldAlert className="h-3.5 w-3.5" />
                                                            Not Covered by Scheme (Self-Pay)
                                                        </span>
                                                    )
                                                ) : (
                                                    <span className="text-muted-foreground">Self-Pay / Cash</span>
                                                )}
                                            </div>
                                        </button>
                                    ))}
                                </div>
                            )}

                            {/* Selected Procedure Summary Card */}
                            {selectedItem && (
                                <div className="rounded-md border p-3 bg-primary/5 flex flex-wrap items-center justify-between gap-3">
                                    <div className="space-y-1">
                                        <div className="font-medium flex items-center gap-2">
                                            <span>{selectedItem.name}</span>
                                            <Badge variant="outline" className="text-xs">
                                                {selectedItem.code}
                                            </Badge>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-3 text-xs">
                                            <span>
                                                Hospital Price: <strong>TZS {selectedItem.cash_price.toLocaleString()}</strong>
                                            </span>
                                            {nhifEligible ? (
                                                selectedItem.is_covered_under_scheme ? (
                                                    <Badge variant="default" className="bg-emerald-600 text-white">
                                                        NHIF Scheme Covered
                                                        {selectedItem.requires_preauth ? ' (Pre-auth Required)' : ' (Standard)'}
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="destructive">
                                                        Uncovered by NHIF Scheme
                                                    </Badge>
                                                )
                                            ) : (
                                                <Badge variant="secondary">Cash / Self-Pay</Badge>
                                            )}
                                        </div>
                                    </div>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => {
                                            setSelectedItem(null);
                                            setData('service_id', '');
                                            setData('procedure_name', '');
                                        }}
                                    >
                                        Change
                                    </Button>
                                </div>
                            )}

                            <InputError message={errors.service_id ?? errors.procedure_name} />
                        </div>

                        {/* Uncovered Scheme Patient Warning & Consent */}
                        {nhifEligible && selectedItem && !selectedItem.is_covered_under_scheme && (
                            <div className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 space-y-2 text-sm text-amber-900 dark:text-amber-200">
                                <div className="flex items-start gap-2">
                                    <AlertTriangle className="h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400 mt-0.5" />
                                    <div>
                                        <p className="font-semibold">NHIF Scheme Exclusion / Uncovered Procedure</p>
                                        <p className="text-xs">
                                            This procedure is not covered under the patient's active NHIF scheme (Scheme ID:{' '}
                                            {nhifInsurance?.nhif_scheme_id ?? 'Unknown'}). To proceed, hospital policy requires
                                            documented patient consent to be billed as a cash/self-pay service (TZS{' '}
                                            {selectedItem.cash_price.toLocaleString()}).
                                        </p>
                                    </div>
                                </div>
                                <div className="flex items-center space-x-2 pt-1">
                                    <Checkbox
                                        id="consent_self_pay"
                                        checked={data.patient_consent_self_pay}
                                        onCheckedChange={(checked) => setData('patient_consent_self_pay', Boolean(checked))}
                                    />
                                    <Label htmlFor="consent_self_pay" className="text-xs font-medium cursor-pointer">
                                        Patient consents to self-pay/cash billing for this uncovered procedure
                                    </Label>
                                </div>
                            </div>
                        )}

                        {/* Details grid: Type, Urgency, Quantity, Scheduled At */}
                        <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-4">
                            <div className="space-y-1.5">
                                <Label htmlFor="procedure_type">Setting / Type *</Label>
                                <Select
                                    value={data.procedure_type}
                                    onValueChange={(val) => setData('procedure_type', val as ProcedureType)}
                                >
                                    <SelectTrigger id="procedure_type">
                                        <SelectValue placeholder="Select type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(PROCEDURE_TYPE_LABELS).map(([k, label]) => (
                                            <SelectItem key={k} value={k}>
                                                {label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.procedure_type} />
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="urgency">Urgency *</Label>
                                <Select
                                    value={data.urgency}
                                    onValueChange={(val) => setData('urgency', val as ProcedureUrgency)}
                                >
                                    <SelectTrigger id="urgency">
                                        <SelectValue placeholder="Select urgency" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(PROCEDURE_URGENCY_LABELS).map(([k, label]) => (
                                            <SelectItem key={k} value={k}>
                                                {label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.urgency} />
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="quantity">Quantity</Label>
                                <Input
                                    id="quantity"
                                    type="number"
                                    min={1}
                                    max={100}
                                    value={data.quantity}
                                    onChange={(e) => setData('quantity', parseInt(e.target.value) || 1)}
                                />
                                <InputError message={errors.quantity} />
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="scheduled_at">Scheduled Time (Optional)</Label>
                                <Input
                                    id="scheduled_at"
                                    type="datetime-local"
                                    value={data.scheduled_at}
                                    onChange={(e) => setData('scheduled_at', e.target.value)}
                                />
                                <InputError message={errors.scheduled_at} />
                            </div>
                        </div>

                        {/* Clinical Indication */}
                        <div className="space-y-1.5">
                            <Label htmlFor="clinical_indication">Clinical Indication & Diagnosis *</Label>
                            <Textarea
                                id="clinical_indication"
                                placeholder="State clinical indication, diagnosis code, symptoms, or anatomical location..."
                                value={data.clinical_indication}
                                onChange={(e) => setData('clinical_indication', e.target.value)}
                                rows={2}
                            />
                            <InputError message={errors.clinical_indication} />
                        </div>

                        {/* Notes */}
                        <div className="space-y-1.5">
                            <Label htmlFor="notes">Special Instructions & Consumables (Optional)</Label>
                            <Input
                                id="notes"
                                placeholder="e.g. Local anesthesia 2% Lignocaine, sterile pack, post-procedure observation 2h"
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                            />
                            <InputError message={errors.notes} />
                        </div>

                        <div className="flex items-center justify-between pt-2">
                            <div className="text-xs text-muted-foreground">
                                {data.payment_mode === 'cash' ? (
                                    <span>
                                        Payment Mode: <strong>Cash / Self-Pay</strong> (Creates patient bill at cashier)
                                    </span>
                                ) : (
                                    <span>
                                        Payment Mode: <strong>NHIF Insurance</strong> (Routes through pre-authorization / claim)
                                    </span>
                                )}
                            </div>

                            <Button type="submit" disabled={processing || (!selectedItem && !data.procedure_name)}>
                                {processing && <LoaderCircle className="mr-1.5 h-4 w-4 animate-spin" />}
                                <PlusCircle className="mr-1.5 h-4 w-4" />
                                Place Procedure Order
                            </Button>
                        </div>
                    </form>
                )}

                {/* ── Ordered Procedures List ── */}
                <div className="space-y-3">
                    <h3 className="text-sm font-semibold text-muted-foreground uppercase tracking-wider">
                        Encounter Procedure Orders ({procedureOrders.length})
                    </h3>

                    {procedureOrders.length === 0 ? (
                        <div className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">
                            No clinical procedures ordered for this encounter yet.
                        </div>
                    ) : (
                        <div className="divide-y rounded-md border">
                            {procedureOrders.map((order) => (
                                <div key={order.id} className="p-4 space-y-3">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="space-y-1">
                                            <div className="flex items-center gap-2">
                                                <span className="font-semibold text-base">{order.procedure_name}</span>
                                                <span className="text-xs font-mono text-muted-foreground">
                                                    #{order.order_number}
                                                </span>
                                                <Badge variant={PROCEDURE_STATUS_VARIANTS[order.status]}>
                                                    {PROCEDURE_STATUS_LABELS[order.status]}
                                                </Badge>
                                                {order.urgency !== 'elective' && (
                                                    <Badge variant="destructive" className="capitalize text-xs">
                                                        {order.urgency}
                                                    </Badge>
                                                )}
                                            </div>

                                            <p className="text-sm text-muted-foreground">
                                                <strong>Indication:</strong> {order.clinical_indication}
                                            </p>

                                            <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                                <span>Setting: {PROCEDURE_TYPE_LABELS[order.procedure_type]}</span>
                                                <span>Qty: {order.quantity}</span>
                                                <span>Total: TZS {order.total_price.toLocaleString()}</span>
                                                {order.prescribed_by && <span>Ordered by: {order.prescribed_by.UserName}</span>}
                                            </div>
                                        </div>

                                        {/* Financial & Pre-approval Status Badge */}
                                        <div className="flex flex-col items-end gap-1.5">
                                            {order.is_emergency_exemption ? (
                                                <Badge variant="outline" className="border-red-500 text-red-700 dark:text-red-400">
                                                    Emergency Exemption Cleared
                                                </Badge>
                                            ) : order.payment_mode === 'cash' ? (
                                                order.is_paid ? (
                                                    <Badge variant="default" className="bg-emerald-600 text-white flex items-center gap-1">
                                                        <CheckCircle2 className="h-3 w-3" />
                                                        Cash Paid (Confirmed)
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="secondary" className="border-amber-500/50 text-amber-700 dark:text-amber-300 flex items-center gap-1">
                                                        <Clock className="h-3 w-3" />
                                                        Awaiting Cashier Payment
                                                    </Badge>
                                                )
                                            ) : order.payment_mode === 'nhif' ? (
                                                order.billing_status === 'rejected' ? (
                                                    <Badge variant="destructive" className="flex items-center gap-1">
                                                        <XCircle className="h-3 w-3" />
                                                        NHIF Authorization Rejected
                                                    </Badge>
                                                ) : order.is_authorized ? (
                                                    <Badge variant="default" className="bg-emerald-600 text-white flex items-center gap-1">
                                                        <ShieldCheck className="h-3 w-3" />
                                                        NHIF Authorized & Cleared
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="secondary" className="border-amber-500/50 text-amber-700 dark:text-amber-300 flex items-center gap-1">
                                                        <Clock className="h-3 w-3" />
                                                        Awaiting NHIF Approval
                                                    </Badge>
                                                )
                                            ) : null}

                                            {/* Action Buttons */}
                                            {order.status !== 'completed' && order.status !== 'cancelled' && (
                                                <div className="flex items-center gap-2 pt-1">
                                                    <ExecuteProcedureDialog
                                                        encounterId={encounterId}
                                                        order={order}
                                                        trigger={
                                                            <Button
                                                                size="sm"
                                                                variant={order.can_be_executed ? 'default' : 'outline'}
                                                                className="gap-1.5"
                                                            >
                                                                <Stethoscope className="h-4 w-4" />
                                                                Document Execution
                                                            </Button>
                                                        }
                                                    />

                                                    {can('procedures.order') && (
                                                        <CancelProcedureOrderDialog
                                                            encounterId={encounterId}
                                                            orderId={order.id}
                                                            orderNumber={order.order_number}
                                                            procedureName={order.procedure_name}
                                                            trigger={
                                                                <Button size="sm" variant="ghost" className="text-destructive hover:bg-destructive/10">
                                                                    Cancel
                                                                </Button>
                                                            }
                                                        />
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    </div>

                                    {/* Completed Record Details (Performer, Findings, Results) */}
                                    {order.status === 'completed' && (
                                        <div className="rounded-md border p-3 bg-muted/30 space-y-2 text-xs">
                                            <div className="flex flex-wrap items-center justify-between font-medium">
                                                <span className="text-emerald-700 dark:text-emerald-400 flex items-center gap-1">
                                                    <CheckCircle2 className="h-3.5 w-3.5" />
                                                    Procedure Completed by {order.performer?.UserName ?? 'Practitioner'}
                                                </span>
                                                {order.performed_at && (
                                                    <span className="text-muted-foreground">
                                                        {new Date(order.performed_at).toLocaleString()}
                                                    </span>
                                                )}
                                            </div>

                                            {order.procedure_notes && (
                                                <div>
                                                    <span className="font-semibold">Procedure Notes:</span> {order.procedure_notes}
                                                </div>
                                            )}

                                            {order.findings && (
                                                <div>
                                                    <span className="font-semibold">Findings:</span> {order.findings}
                                                </div>
                                            )}

                                            {order.results && (
                                                <div>
                                                    <span className="font-semibold">Results:</span> {order.results}
                                                </div>
                                            )}

                                            {order.complications && (
                                                <div className="text-destructive">
                                                    <span className="font-semibold">Complications:</span> {order.complications}
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    {/* Rejection explanation if any */}
                                    {order.nhif_rejection_reason && (
                                        <div className="text-xs text-destructive bg-destructive/10 p-2 rounded">
                                            <strong>NHIF Rejection Reason:</strong> {order.nhif_rejection_reason}
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
