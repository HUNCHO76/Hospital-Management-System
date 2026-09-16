import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';

interface ReleaseBatchButtonProps {
    stockBatchId: number;
}

/**
 * Release a quarantined batch back into active, dispensable inventory
 * (Phase 6, Step 49). No reason required — unlike quarantining or adjusting,
 * releasing is not itself a correction that needs justifying; the backend
 * still refuses if the batch has since expired (StockService::release()).
 */
export function ReleaseBatchButton({ stockBatchId }: ReleaseBatchButtonProps) {
    return (
        <Button
            type="button"
            size="sm"
            variant="outline"
            onClick={() => router.post(route('pharmacy-stock.batches.release', stockBatchId), {}, { preserveScroll: true })}
        >
            Release
        </Button>
    );
}
