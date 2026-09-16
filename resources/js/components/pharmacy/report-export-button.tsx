import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { Download } from 'lucide-react';

interface ReportExportButtonProps {
    /** The current report route name (e.g. 'pharmacy-reports.stock-batches'). */
    routeName: string;
    /** The current filters — exported CSV always reflects exactly what is on screen (spec §18). */
    filters: Record<string, string | undefined>;
}

/**
 * Streams the current report's own filtered/scoped query as CSV (Phase 6,
 * Step 50, spec §18) — a plain link to the same report URL with
 * `export=1` appended, so the export can never drift from what is on
 * screen. Hidden entirely for a user without the distinct 'export'
 * ability, even though the backend re-checks it anyway (spec §19).
 */
export function ReportExportButton({ routeName, filters }: ReportExportButtonProps) {
    const { can } = usePermissions();

    if (!can('pharmacy-reports.export')) {
        return null;
    }

    return (
        <Button asChild variant="outline" size="sm">
            <a href={route(routeName, { ...filters, export: 1 })}>
                <Download className="h-4 w-4" />
                Export CSV
            </a>
        </Button>
    );
}
