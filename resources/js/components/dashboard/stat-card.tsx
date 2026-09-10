import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { type LucideIcon } from 'lucide-react';

interface StatCardProps {
    label: string;
    value: string | number;
    icon?: LucideIcon;
    /** Optional supporting line, e.g. "Active only" or a trend. */
    hint?: string;
    className?: string;
}

/**
 * A single dashboard statistic. Uses semantic theme tokens so it adopts the
 * brand primary colour automatically once brand tokens are configured.
 */
export function StatCard({ label, value, icon: Icon, hint, className }: StatCardProps) {
    return (
        <Card className={className}>
            <CardContent className="flex items-start justify-between gap-4 p-5">
                <div className="space-y-1">
                    <p className="text-muted-foreground text-sm font-medium">{label}</p>
                    <p className="text-2xl font-semibold tracking-tight">{value}</p>
                    {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
                </div>
                {Icon && (
                    <span className={cn('bg-primary/10 text-primary flex h-10 w-10 items-center justify-center rounded-lg')}>
                        <Icon className="h-5 w-5" aria-hidden="true" />
                    </span>
                )}
            </CardContent>
        </Card>
    );
}
