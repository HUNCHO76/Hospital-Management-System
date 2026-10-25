import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { type ProcedureOrder } from '@/types/procedure';
import { useForm } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, LoaderCircle, Stethoscope } from 'lucide-react';
import { type FormEventHandler, type ReactNode, useState } from 'react';

interface ExecuteProcedureDialogProps {
    encounterId: number;
    order: ProcedureOrder;
    trigger: ReactNode;
}

export function ExecuteProcedureDialog({
    encounterId,
    order,
    trigger,
}: ExecuteProcedureDialogProps) {
    const [open, setOpen] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        performed_at: new Date().toISOString().slice(0, 16),
        procedure_notes: order.procedure_notes ?? '',
        findings: order.findings ?? '',
        results: order.results ?? '',
        complications: order.complications ?? '',
        is_emergency_exemption: order.is_emergency_exemption ?? false,
        exemption_reason: order.exemption_reason ?? '',
    });

    const isBlocked = !order.can_be_executed && !data.is_emergency_exemption;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('clinical-encounters.procedure-orders.execute', [encounterId, order.id]), {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
            preserveScroll: true,
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <div className="flex items-center gap-2">
                            <Stethoscope className="h-5 w-5 text-primary" />
                            <DialogTitle>Document Procedure Execution & Results</DialogTitle>
                        </div>
                        <DialogDescription>
                            Record clinical performance, execution findings, and interpretation for order{' '}
                            <strong>{order.order_number}</strong> ({order.procedure_name}).
                        </DialogDescription>
                    </DialogHeader>

                    {/* Financial & Authorization Gate Alert */}
                    {!order.can_be_executed && (
                        <div className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-900 dark:text-amber-200">
                            <div className="flex items-start gap-2">
                                <AlertCircle className="h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400 mt-0.5" />
                                <div className="space-y-1">
                                    <p className="font-semibold">
                                        {order.payment_mode === 'cash'
                                            ? 'Cash Payment Pending'
                                            : order.billing_status === 'rejected'
                                            ? 'NHIF Pre-authorization Rejected'
                                            : 'NHIF Pre-authorization Pending'}
                                    </p>
                                    <p className="text-xs">
                                        {order.payment_mode === 'cash'
                                            ? `This procedure is billed at TZS ${order.total_price.toLocaleString()}. Cashier payment confirmation is required before execution.`
                                            : order.billing_status === 'rejected'
                                            ? `NHIF rejected this procedure request: ${order.nhif_rejection_reason ?? 'Rejected'}. It cannot proceed under NHIF.`
                                            : 'This procedure is awaiting NHIF pre-authorization approval.'}
                                    </p>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Emergency Clinical Exemption Toggle */}
                    {!order.can_be_executed && (
                        <div className="rounded-md border p-3 bg-muted/40 space-y-3">
                            <div className="flex items-center space-x-2">
                                <Checkbox
                                    id="emergency_exemption"
                                    checked={data.is_emergency_exemption}
                                    onCheckedChange={(checked) => setData('is_emergency_exemption', Boolean(checked))}
                                />
                                <Label htmlFor="emergency_exemption" className="text-sm font-medium cursor-pointer">
                                    Authorize Emergency / Clinical Exemption
                                </Label>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Only check this in urgent clinical situations where immediate execution is necessary to prevent severe harm or death.
                            </p>

                            {data.is_emergency_exemption && (
                                <div className="space-y-1.5 pt-1">
                                    <Label htmlFor="exemption_reason" className="text-xs font-semibold text-destructive">
                                        Emergency Justification *
                                    </Label>
                                    <Input
                                        id="exemption_reason"
                                        placeholder="e.g. Acute distress, immediate life-saving decompression required"
                                        value={data.exemption_reason}
                                        onChange={(e) => setData('exemption_reason', e.target.value)}
                                    />
                                    <InputError message={errors.exemption_reason} />
                                </div>
                            )}
                        </div>
                    )}

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="performed_at">Performance Timestamp *</Label>
                            <Input
                                id="performed_at"
                                type="datetime-local"
                                value={data.performed_at}
                                onChange={(e) => setData('performed_at', e.target.value)}
                            />
                            <InputError message={errors.performed_at} />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="complications">Complications (if any)</Label>
                            <Input
                                id="complications"
                                placeholder="None observed, or describe any adverse event"
                                value={data.complications}
                                onChange={(e) => setData('complications', e.target.value)}
                            />
                            <InputError message={errors.complications} />
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="procedure_notes">Procedure Notes & Technique *</Label>
                        <Textarea
                            id="procedure_notes"
                            placeholder="Describe anesthesia, sterile prep, step-by-step technique, patient tolerance..."
                            value={data.procedure_notes}
                            onChange={(e) => setData('procedure_notes', e.target.value)}
                            rows={3}
                        />
                        <InputError message={errors.procedure_notes} />
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="findings">Intra-Procedure Findings</Label>
                            <Textarea
                                id="findings"
                                placeholder="Anatomical observations, pathology observed, tissue appearance..."
                                value={data.findings}
                                onChange={(e) => setData('findings', e.target.value)}
                                rows={2}
                            />
                            <InputError message={errors.findings} />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="results">Clinical Results & Follow-up Plan</Label>
                            <Textarea
                                id="results"
                                placeholder="Outcome, specimens collected, immediate recovery instructions..."
                                value={data.results}
                                onChange={(e) => setData('results', e.target.value)}
                                rows={2}
                            />
                            <InputError message={errors.results} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing || isBlocked}>
                            {processing && <LoaderCircle className="mr-1.5 h-4 w-4 animate-spin" />}
                            <CheckCircle2 className="mr-1.5 h-4 w-4" />
                            Complete & Document Execution
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
