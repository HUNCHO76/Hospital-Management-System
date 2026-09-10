import { type ReactNode } from 'react';

interface PageHeaderProps {
    title: string;
    description?: string;
    /** Optional action area, typically a primary button. */
    actions?: ReactNode;
}

/**
 * The standard page header: a title, an optional description, and an optional
 * actions area. Every major page uses this instead of repeating the markup.
 */
export function PageHeader({ title, description, actions }: PageHeaderProps) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-4">
            <div className="space-y-1">
                <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
                {description && <p className="text-muted-foreground text-sm">{description}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}
