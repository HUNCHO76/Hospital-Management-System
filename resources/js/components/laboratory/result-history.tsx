import { type LaboratoryResultHistoryEntry } from '@/types/laboratory';

interface ResultHistoryProps {
    history: LaboratoryResultHistoryEntry[];
}

const EVENT_LABELS: Record<LaboratoryResultHistoryEntry['event'], string> = {
    created: 'Ready for result entry',
    entered: 'Result entered (draft)',
    submitted: 'Submitted for verification',
    returned: 'Returned for correction',
    reviewed: 'Review started',
    verified: 'Verified',
    authorized: 'Authorized — ready for release',
    released: 'Released',
    amended: 'Amended',
};

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * A simple derived timeline from a result's own lifecycle timestamps (Phase
 * 4, Steps 33-35, spec §25/§31) — the full forensic audit trail lives in
 * the existing Audit Log viewer; this is only the current milestone per
 * event category (e.g. a second review-start overwrites the first here,
 * but both remain in the audit log).
 */
export function ResultHistory({ history }: ResultHistoryProps) {
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
