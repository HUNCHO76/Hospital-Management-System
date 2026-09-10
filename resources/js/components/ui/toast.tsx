import { useToast, type ToastVariant } from '@/hooks/use-toast';
import { cn } from '@/lib/utils';
import { AlertTriangle, CheckCircle2, Info, X, XCircle } from 'lucide-react';
import { type ComponentType } from 'react';

const variantStyles: Record<ToastVariant, string> = {
    success: 'border-green-600/30 bg-green-50 text-green-900 dark:border-green-500/30 dark:bg-green-950 dark:text-green-100',
    error: 'border-red-600/30 bg-red-50 text-red-900 dark:border-red-500/30 dark:bg-red-950 dark:text-red-100',
    warning: 'border-amber-600/30 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-950 dark:text-amber-100',
    info: 'border-blue-600/30 bg-blue-50 text-blue-900 dark:border-blue-500/30 dark:bg-blue-950 dark:text-blue-100',
};

const variantIcon: Record<ToastVariant, ComponentType<{ className?: string }>> = {
    success: CheckCircle2,
    error: XCircle,
    warning: AlertTriangle,
    info: Info,
};

/**
 * Renders the active toast stack. Uses an assertive live region for errors and
 * a polite one for everything else so screen readers announce them correctly.
 */
export function Toaster() {
    const { toasts, dismiss } = useToast();

    if (toasts.length === 0) {
        return null;
    }

    return (
        <div className="pointer-events-none fixed top-4 right-4 z-[100] flex w-full max-w-sm flex-col gap-2" aria-live="polite" aria-atomic="false">
            {toasts.map((toast) => {
                const Icon = variantIcon[toast.variant];

                return (
                    <div
                        key={toast.id}
                        role={toast.variant === 'error' ? 'alert' : 'status'}
                        className={cn('pointer-events-auto flex items-start gap-3 rounded-lg border p-4 shadow-md', variantStyles[toast.variant])}
                    >
                        <Icon className="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
                        <div className="flex-1 text-sm">
                            {toast.title && <p className="font-medium">{toast.title}</p>}
                            <p className={cn(toast.title && 'text-current/90')}>{toast.description}</p>
                        </div>
                        <button
                            type="button"
                            onClick={() => dismiss(toast.id)}
                            className="text-current/70 hover:text-current focus-visible:ring-ring rounded focus-visible:ring-2 focus-visible:outline-none"
                            aria-label="Dismiss notification"
                        >
                            <X className="h-4 w-4" />
                        </button>
                    </div>
                );
            })}
        </div>
    );
}
