import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { type ReactNode, useId } from 'react';

interface FieldProps {
    label: string;
    /** The input control. Receives the generated id via `htmlFor` on the label. */
    children: (props: { id: string; 'aria-invalid': boolean; 'aria-describedby'?: string }) => ReactNode;
    required?: boolean;
    hint?: string;
    error?: string;
    className?: string;
}

/**
 * A standardized labeled form field: label (with a required indicator), the
 * control, an optional hint, and the backend validation error. The error is
 * associated with the control via aria-describedby for screen readers.
 *
 * @example
 * <Field label="First name" required error={errors.first_name}>
 *   {(props) => <Input {...props} value={data.first_name} onChange={...} />}
 * </Field>
 */
export function Field({ label, children, required = false, hint, error, className }: FieldProps) {
    const id = useId();
    const hintId = hint ? `${id}-hint` : undefined;
    const errorId = error ? `${id}-error` : undefined;
    const describedBy = [hintId, errorId].filter(Boolean).join(' ') || undefined;

    return (
        <div className={cn('space-y-1.5', className)}>
            <Label htmlFor={id}>
                {label}
                {required && (
                    <span className="ml-0.5 text-red-600 dark:text-red-400" aria-hidden="true">
                        *
                    </span>
                )}
            </Label>

            {children({ id, 'aria-invalid': Boolean(error), 'aria-describedby': describedBy })}

            {hint && !error && (
                <p id={hintId} className="text-muted-foreground text-xs">
                    {hint}
                </p>
            )}

            {error && <InputError id={errorId} message={error} />}
        </div>
    );
}
