import { NhifPreApprovalForm } from '@/components/nhif/nhif-pre-approval-form';
import { NhifPreApprovalSessionCard } from '@/components/nhif/nhif-pre-approval-session-card';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { type ClinicalEncounterPractitioner } from '@/types/clinical-encounter';
import { type NhifInsuranceSummary, type NhifPendingItems, type NhifPreApprovalSession } from '@/types/nhif';
import { Deferred } from '@inertiajs/react';

interface NhifPreApprovalSectionProps {
    encounterId: number;
    insurance: NhifInsuranceSummary | null;
    pendingItems: NhifPendingItems | null;
    sessions: NhifPreApprovalSession[];
    patientName: string;
    practitioner: ClinicalEncounterPractitioner | null;
}

function LoadingSkeleton() {
    return (
        <Card>
            <CardHeader className="pb-3">
                <Skeleton className="h-5 w-64" />
            </CardHeader>
            <CardContent className="space-y-3">
                <Skeleton className="h-4 w-full" />
                <Skeleton className="h-4 w-3/4" />
                <Skeleton className="h-4 w-1/2" />
            </CardContent>
        </Card>
    );
}

export function NhifPreApprovalSection({
    encounterId,
    insurance,
    pendingItems,
    sessions,
    patientName,
    practitioner,
}: NhifPreApprovalSectionProps) {
    if (!insurance) {
        return null;
    }

    const hasSessions = sessions.length > 0;

    return (
        <div className="space-y-4">
            <h2 className="text-lg font-semibold">NHIF Pre-Approval</h2>

            {hasSessions &&
                sessions.map((session) => (
                    <NhifPreApprovalSessionCard key={session.id} session={session} encounterId={encounterId} />
                ))}

            <Deferred data="nhifPendingItems" fallback={<LoadingSkeleton />}>
                <PendingItemsForms
                    pendingItems={pendingItems}
                    insurance={insurance}
                    encounterId={encounterId}
                    patientName={patientName}
                    practitioner={practitioner}
                />
            </Deferred>
        </div>
    );
}

function PendingItemsForms({
    pendingItems,
    insurance,
    encounterId,
    patientName,
    practitioner,
}: {
    pendingItems: NhifPendingItems | null;
    insurance: NhifInsuranceSummary;
    encounterId: number;
    patientName: string;
    practitioner: ClinicalEncounterPractitioner | null;
}) {
    if (!pendingItems) {
        return null;
    }

    const hasLabItems = pendingItems.lab_items.length > 0;
    const hasMedItems = pendingItems.medication_items.length > 0;

    if (!hasLabItems && !hasMedItems) {
        return (
            <Card>
                <CardContent className="py-4">
                    <p className="text-muted-foreground text-sm">No pending items require NHIF pre-approval.</p>
                </CardContent>
            </Card>
        );
    }

    return (
        <>
            {hasLabItems && (
                <NhifPreApprovalForm
                    items={pendingItems.lab_items}
                    insurance={insurance}
                    encounterId={encounterId}
                    patientName={patientName}
                    practitioner={practitioner}
                    sourceLabel="Laboratory"
                    sourceType="lab"
                />
            )}
            {hasMedItems && (
                <NhifPreApprovalForm
                    items={pendingItems.medication_items}
                    insurance={insurance}
                    encounterId={encounterId}
                    patientName={patientName}
                    practitioner={practitioner}
                    sourceLabel="Pharmacy"
                    sourceType="medication"
                />
            )}
        </>
    );
}
