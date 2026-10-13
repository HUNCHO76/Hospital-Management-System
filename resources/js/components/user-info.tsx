import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { type SharedData, type User } from '@/types';
import { usePage } from '@inertiajs/react';

export function formatRoleName(role: string): string {
    return role
        .replace(/[-_]/g, ' ')
        .split(' ')
        .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
        .join(' ');
}

export function UserInfo({ user, showEmail = false, roles }: { user: User; showEmail?: boolean; roles?: string[] }) {
    const getInitials = useInitials();
    const page = usePage<SharedData>();
    const userRoles = roles ?? page.props.auth?.roles ?? [];
    const formattedRoles = userRoles.map(formatRoleName).join(' • ');

    return (
        <>
            <Avatar className="h-8 w-8 overflow-hidden rounded-full shrink-0">
                <AvatarImage src={user.avatar} alt={user.UserName} />
                <AvatarFallback className="rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white font-medium text-xs">
                    {getInitials(user.UserName)}
                </AvatarFallback>
            </Avatar>
            <div className="grid flex-1 text-left text-sm leading-tight min-w-0">
                <span className="truncate font-medium">{user.UserName}</span>
                {formattedRoles && (
                    <span className="text-muted-foreground truncate text-xs font-normal">
                        {formattedRoles}
                    </span>
                )}
                {showEmail && <span className="text-muted-foreground/80 truncate text-[11px]">{user.email}</span>}
            </div>
        </>
    );
}

