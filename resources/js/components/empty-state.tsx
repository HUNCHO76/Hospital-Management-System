import { type LucideIcon } from 'lucide-react';
import { Inbox } from 'lucide-react';
import { type ReactNode } from 'react';

interface EmptyStateProps {
    title: string;
    description?: string;
    icon?: LucideIcon;
    /** Optional next-step action, e.g. a "Register patient" button. */
    action?: ReactNode;
}

/**
 * A reusable empty state that explains what happened and what the user can do
 * next. Used inside tables and lists when there is no data to show.
 */
export function EmptyState({ title, description, icon: Icon = Inbox, action }: EmptyStateProps) {
    return (
        <div className="flex flex-col items-center justify-center gap-3 px-4 py-12 text-center">
            <Icon className="text-muted-foreground h-8 w-8" aria-hidden="true" />
            <div className="space-y-1">
                <p className="font-medium">{title}</p>
                {description && <p className="text-muted-foreground text-sm">{description}</p>}
            </div>
            {action}
        </div>
    );
}
