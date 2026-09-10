import { cn } from '@/lib/utils';
import * as React from 'react';

/**
 * An accessible radio group built on native radio inputs — no extra
 * dependency. Grouping is by the shared `name`, keeping keyboard behaviour and
 * form submission native.
 */
export function RadioGroup({ className, role = 'radiogroup', ...props }: React.HTMLAttributes<HTMLDivElement>) {
    return <div role={role} className={cn('flex flex-col gap-2', className)} {...props} />;
}

export interface RadioProps extends Omit<React.InputHTMLAttributes<HTMLInputElement>, 'type'> {
    label: string;
}

export const Radio = React.forwardRef<HTMLInputElement, RadioProps>(({ className, label, id, ...props }, ref) => {
    const inputId = id ?? `${props.name}-${String(props.value)}`;

    return (
        <label htmlFor={inputId} className={cn('flex cursor-pointer items-center gap-2 text-sm', props.disabled && 'cursor-not-allowed opacity-60', className)}>
            <input
                ref={ref}
                id={inputId}
                type="radio"
                className="border-input text-primary focus-visible:ring-ring h-4 w-4 focus-visible:ring-2 focus-visible:ring-offset-2"
                {...props}
            />
            <span>{label}</span>
        </label>
    );
});
Radio.displayName = 'Radio';
