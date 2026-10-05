import { BiometricIssueDialog } from '@/components/nhif/biometric-issue-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { type NhifPreApprovalSession } from '@/types/nhif';
import { router } from '@inertiajs/react';
import { useState } from 'react';

interface NhifPreApprovalSessionCardProps {
    session: NhifPreApprovalSession;
    encounterId: number;
}

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-TZ', { style: 'currency', currency: 'TZS', minimumFractionDigits: 0 }).format(amount);
}

const STATUS_VARIANTS: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    draft: 'outline',
    submitted: 'secondary',
    accepted: 'default',
    approved: 'default',
    partially_approved: 'secondary',
    rejected: 'destructive',
    issued: 'default',
    cancelled: 'destructive',
};

const APPROVAL_STATUS_VARIANTS: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    pending: 'outline',
    accepted: 'default',
    pending_approval: 'secondary',
    approved: 'default',
    rejected: 'destructive',
    issued: 'default',
    unknown: 'outline',
};

export function NhifPreApprovalSessionCard({ session, encounterId }: NhifPreApprovalSessionCardProps) {
    const [processing, setProcessing] = useState<string | null>(null);
    const [issueDialogOpen, setIssueDialogOpen] = useState(false);

    const sessionAction = (action: string) => {
        setProcessing(action);
        router.post(route(`clinical-encounters.nhif-pre-approval.${action}`, [encounterId, session.id]), {}, {
            preserveScroll: true,
            onFinish: () => setProcessing(null),
        });
    };

    const canSubmit = session.status === 'draft';
    const canSync = ['submitted', 'partially_approved'].includes(session.status);
    const canIssue = ['accepted', 'approved', 'partially_approved'].includes(session.status);
    const canCancel = !['issued', 'cancelled'].includes(session.status);

    const hasLabItems = session.items.some((i) => i.source_type === 'lab');
    const hasMedItems = session.items.some((i) => i.source_type === 'medication');
    const isIssued = session.status === 'issued';

    const total = session.items.reduce((sum, i) => sum + i.unit_price * i.quantity, 0);

    return (
        <Card>
            <CardHeader className="pb-3">
                <div className="flex items-center justify-between gap-2">
                    <CardTitle className="text-base">
                        Pre-Approval #{session.id}
                        {session.nhif_request_no && (
                            <span className="text-muted-foreground ml-2 text-sm font-normal">
                                NHIF Ref: {session.nhif_request_no}
                            </span>
                        )}
                    </CardTitle>
                    <Badge variant={STATUS_VARIANTS[session.status] ?? 'outline'}>
                        {session.status.replace(/_/g, ' ')}
                    </Badge>
                </div>
            </CardHeader>
            <CardContent>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left">
                                <th className="pb-2 pr-4">Item</th>
                                <th className="pb-2 pr-4">NHIF Code</th>
                                <th className="pb-2 pr-4">Type</th>
                                <th className="pb-2 pr-4 text-right">Qty</th>
                                <th className="pb-2 pr-4 text-right">Price</th>
                                <th className="pb-2 text-right">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {session.items.map((item) => (
                                <tr key={item.id} className="border-b last:border-0">
                                    <td className="py-2 pr-4">{item.nhif_item_name ?? '—'}</td>
                                    <td className="py-2 pr-4">
                                        <Badge variant="outline">{item.nhif_item_code}</Badge>
                                    </td>
                                    <td className="py-2 pr-4 capitalize">{item.source_type}</td>
                                    <td className="py-2 pr-4 text-right">{item.quantity}</td>
                                    <td className="py-2 pr-4 text-right">{formatCurrency(item.unit_price)}</td>
                                    <td className="py-2 text-right">
                                        <Badge variant={APPROVAL_STATUS_VARIANTS[item.approval_status] ?? 'outline'} className="text-xs">
                                            {item.approval_status.replace(/_/g, ' ')}
                                        </Badge>
                                        {item.rejection_reason && (
                                            <p className="text-destructive mt-1 text-xs">{item.rejection_reason}</p>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colSpan={4} className="pt-2 text-right font-medium">
                                    Total:
                                </td>
                                <td className="pt-2 text-right font-medium">{formatCurrency(total)}</td>
                                <td />
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div className="mt-4 flex flex-wrap gap-2">
                    {canSubmit && (
                        <Button size="sm" onClick={() => sessionAction('submit')} disabled={processing !== null}>
                            {processing === 'submit' ? 'Submitting...' : 'Submit to NHIF'}
                        </Button>
                    )}
                    {canSync && (
                        <Button size="sm" variant="outline" onClick={() => sessionAction('sync')} disabled={processing !== null}>
                            {processing === 'sync' ? 'Syncing...' : 'Sync Status'}
                        </Button>
                    )}
                    {canIssue && (
                        <Button size="sm" onClick={() => setIssueDialogOpen(true)} disabled={processing !== null}>
                            Issue Approved
                        </Button>
                    )}
                    {canCancel && (
                        <Button size="sm" variant="destructive" onClick={() => sessionAction('cancel')} disabled={processing !== null}>
                            {processing === 'cancel' ? 'Cancelling...' : 'Cancel'}
                        </Button>
                    )}
                    {isIssued && hasLabItems && (
                        <Badge variant="default" className="ml-auto">
                            Lab items issued — proceed with laboratory processing
                        </Badge>
                    )}
                    {isIssued && hasMedItems && (
                        <Badge variant="default" className="ml-auto">
                            Medication items issued — proceed to dispensing
                        </Badge>
                    )}
                </div>
            </CardContent>
            {canIssue && (
                <BiometricIssueDialog
                    open={issueDialogOpen}
                    onOpenChange={setIssueDialogOpen}
                    issueUrl={route('clinical-encounters.nhif-pre-approval.issue', [encounterId, session.id])}
                />
            )}
        </Card>
    );
}
