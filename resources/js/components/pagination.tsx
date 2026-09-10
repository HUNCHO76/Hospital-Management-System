import { cn } from '@/lib/utils';
import { type PaginationLink } from '@/types';
import { Link } from '@inertiajs/react';

interface PaginationProps {
    links: PaginationLink[];
}

/**
 * Renders Laravel's paginator `links` array. Labels come from Laravel
 * pre-escaped with HTML entities (e.g. "&laquo; Previous"), hence the
 * dangerouslySetInnerHTML — this is trusted, server-generated markup, never
 * user input.
 */
export function Pagination({ links }: PaginationProps) {
    if (links.length <= 3) {
        return null;
    }

    return (
        <nav className="flex flex-wrap items-center gap-1" aria-label="Pagination">
            {links.map((link, index) =>
                link.url ? (
                    <Link
                        key={index}
                        href={link.url}
                        preserveScroll
                        className={cn(
                            'rounded-md px-3 py-1.5 text-sm',
                            link.active ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-accent',
                        )}
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ) : (
                    <span
                        key={index}
                        className="text-muted-foreground/50 px-3 py-1.5 text-sm"
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ),
            )}
        </nav>
    );
}
