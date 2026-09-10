import { useToast } from '@/hooks/use-toast';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * Bridges Laravel session flashes (shared on every Inertia response) into the
 * toast system, so controllers keep using `->with('success', ...)` and the
 * frontend surfaces them consistently. Pages never wire this up themselves.
 */
export function FlashToaster() {
    const { flash } = usePage<SharedData>().props;
    const { toast } = useToast();

    useEffect(() => {
        if (flash.success) {
            toast({ variant: 'success', description: flash.success });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [flash.success]);

    useEffect(() => {
        if (flash.error) {
            toast({ variant: 'error', description: flash.error });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [flash.error]);

    useEffect(() => {
        if (flash.warning) {
            toast({ variant: 'warning', description: flash.warning });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [flash.warning]);

    useEffect(() => {
        if (flash.info) {
            toast({ variant: 'info', description: flash.info });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [flash.info]);

    return null;
}
