import { type LaboratorySampleHistoryEntry } from '@/types/laboratory';

interface SampleTimelineProps {
    history: LaboratorySampleHistoryEntry[];
}

const EVENT_LABELS: Record<LaboratorySampleHistoryEntry['event'], string> = {
    created: 'Sample created',
    collected: 'Collected',
    labelled: 'Label generated',
    received: 'Received by laboratory',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
};

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * A simple derived timeline from the sample's own lifecycle timestamps
 * (spec §37-38) — the full forensic audit trail lives in the existing
 * Audit Log viewer; this is only the at-a-glance traceability view.
 */
export function SampleTimeline({ history }: SampleTimelineProps) {
    if (history.length === 0) {
        return <p className="text-muted-foreground text-sm">No events recorded yet.</p>;
    }

    return (
        <ol className="space-y-3">
            {history.map((entry, index) => (
                <li key={index} className="flex gap-3 text-sm">
                    <span className="text-muted-foreground w-40 shrink-0">{formatDateTime(entry.at)}</span>
                    <div>
                        <span className="font-medium">{EVENT_LABELS[entry.event]}</span>
                        {entry.actor && <span className="text-muted-foreground"> — {entry.actor}</span>}
                        {entry.reason && <span className="text-muted-foreground block text-xs">Reason: {entry.reason}</span>}
                    </div>
                </li>
            ))}
        </ol>
    );
}
