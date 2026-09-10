import { type LaboratoryProcessingHistoryEntry } from '@/types/laboratory';

interface ProcessingTimelineProps {
    history: LaboratoryProcessingHistoryEntry[];
}

const EVENT_LABELS: Record<LaboratoryProcessingHistoryEntry['event'], string> = {
    received: 'Entered processing',
    accepted: 'Accepted',
    assigned: 'Assigned',
    started: 'Processing started',
    completed: 'Processing completed',
};

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * A simple derived timeline from a processing record's own lifecycle
 * timestamps (Phase 4, Step 32, spec §27) — the full forensic audit trail
 * lives in the existing Audit Log viewer.
 */
export function ProcessingTimeline({ history }: ProcessingTimelineProps) {
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
                    </div>
                </li>
            ))}
        </ol>
    );
}
