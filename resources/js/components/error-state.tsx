import { Button } from '@/components/ui/button';
import { AlertTriangle } from 'lucide-react';

interface ErrorStateProps {
    title?: string;
    description?: string;
    /** When provided, renders a retry button. */
    onRetry?: () => void;
    retryLabel?: string;
}

/**
 * A reusable error state with an optional retry action. Shows only safe,
 * user-facing copy — never stack traces, SQL, or internal details.
 */
export function ErrorState({
    title = 'Something went wrong',
    description = 'We could not complete your request. Please try again.',
    onRetry,
    retryLabel = 'Try again',
}: ErrorStateProps) {
    return (
        <div className="flex flex-col items-center justify-center gap-3 px-4 py-12 text-center">
            <AlertTriangle className="h-8 w-8 text-amber-500" aria-hidden="true" />
            <div className="space-y-1">
                <p className="font-medium">{title}</p>
                <p className="text-muted-foreground text-sm">{description}</p>
            </div>
            {onRetry && (
                <Button variant="outline" onClick={onRetry}>
                    {retryLabel}
                </Button>
            )}
        </div>
    );
}
