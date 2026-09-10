import { cn } from '@/lib/utils';
import * as React from 'react';

export interface SwitchProps extends Omit<React.InputHTMLAttributes<HTMLInputElement>, 'type'> {
    className?: string;
}

/**
 * An accessible on/off switch built on a native checkbox — no extra
 * dependency. The visual track/thumb is styled from the peer checkbox state,
 * so keyboard focus, `checked`, and `disabled` behave natively.
 */
export const Switch = React.forwardRef<HTMLInputElement, SwitchProps>(({ className, ...props }, ref) => {
    return (
        <label className={cn('relative inline-flex cursor-pointer items-center', props.disabled && 'cursor-not-allowed opacity-60', className)}>
            <input ref={ref} type="checkbox" role="switch" className="peer sr-only" {...props} />
            <span className="bg-input peer-checked:bg-primary peer-focus-visible:ring-ring h-5 w-9 rounded-full transition-colors peer-focus-visible:ring-2 peer-focus-visible:ring-offset-2" />
            <span className="pointer-events-none absolute left-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform peer-checked:translate-x-4" />
        </label>
    );
});
Switch.displayName = 'Switch';
