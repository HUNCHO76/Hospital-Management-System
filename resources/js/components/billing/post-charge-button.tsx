import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';

interface PostChargeButtonProps {
    chargeId: number;
}

/**
 * Post a PENDING charge — it becomes a real, immutable financial fact and
 * starts counting toward the account balance. No reason required, unlike
 * void/cancel — posting is the normal, expected next step, not a
 * correction.
 */
export function PostChargeButton({ chargeId }: PostChargeButtonProps) {
    return (
        <Button
            type="button"
            size="sm"
            variant="outline"
            onClick={() => router.post(route('billing.charges.post', chargeId), {}, { preserveScroll: true })}
        >
            Post
        </Button>
    );
}
