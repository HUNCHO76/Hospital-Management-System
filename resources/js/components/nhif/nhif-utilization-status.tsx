import { type NhifUtilization } from '@/types/nhif';
import { Link } from '@inertiajs/react';

const statusColors: Record<string, string> = {
    pending: 'bg-gray-100 text-gray-700',
    submitted: 'bg-blue-100 text-blue-700',
    accepted: 'bg-green-100 text-green-700',
    rejected: 'bg-red-100 text-red-700',
    reconciled: 'bg-purple-100 text-purple-700',
    failed: 'bg-orange-100 text-orange-700',
    excluded: 'bg-gray-100 text-gray-500',
};

interface NhifUtilizationStatusProps {
    utilization: NhifUtilization;
    /** Wraps the row in a link to the utilization's own detail page. */
    linkable?: boolean;
}

/**
 * A compact inline row showing a single NHIF utilization submission's status
 * — used on the lab result / pharmacy dispensing / radiology examination
 * show pages wherever a clinical event has a linked NHIF utilization
 * (Phase 8, Step 71). Mirrors the status vocabulary/colors already
 * established by `pages/nhif/utilizations/show.tsx`.
 */
export function NhifUtilizationStatus({ utilization, linkable }: NhifUtilizationStatusProps) {
    const content = (
        <div className="flex items-center justify-between gap-3 text-sm">
            <div className="flex flex-col">
                <span className="text-muted-foreground font-mono text-xs">{utilization.utilization_number}</span>
                <span>{utilization.nhif_item_name}</span>
            </div>
            <span className={`inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-xs font-medium ${statusColors[utilization.status] ?? ''}`}>
                {utilization.status}
            </span>
        </div>
    );

    if (linkable) {
        return (
            <Link href={route('nhif.utilization.show', utilization.id)} className="block rounded-md hover:underline">
                {content}
            </Link>
        );
    }

    return content;
}
