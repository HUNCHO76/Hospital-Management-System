import { cn } from '@/lib/utils';
import { Loader2 } from 'lucide-react';

interface SpinnerProps {
    className?: string;
    /** Accessible label announced to screen readers. */
    label?: string;
}

/**
 * A minimal loading spinner. Renders a screen-reader label so a bare spinner
 * still communicates its meaning to assistive technology.
 */
export function Spinner({ className, label = 'Loading' }: SpinnerProps) {
    return (
        <span role="status" className="inline-flex items-center">
            <Loader2 className={cn('h-4 w-4 animate-spin', className)} aria-hidden="true" />
            <span className="sr-only">{label}</span>
        </span>
    );
}
