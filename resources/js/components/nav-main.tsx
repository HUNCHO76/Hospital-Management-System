import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { type NavGroup, type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import * as React from 'react';

interface NavMainProps {
    groups?: NavGroup[];
    items?: NavItem[];
    searchQuery?: string;
}

function isRouteActive(itemUrl: string, currentPath: string): boolean {
    if (!itemUrl || !currentPath) return false;
    const cleanCurrent = currentPath.split('?')[0].split('#')[0];
    const cleanItem = itemUrl.split('?')[0].split('#')[0];

    if (cleanCurrent === cleanItem) return true;

    // For non-root and non-dashboard routes, match nested child paths
    if (cleanItem !== '/' && cleanItem !== '/dashboard' && cleanCurrent.startsWith(`${cleanItem}/`)) {
        return true;
    }

    return false;
}

function CollapsibleNavItem({
    item,
    currentPath,
    onLinkClick,
    forceOpen = false,
}: {
    item: NavItem;
    currentPath: string;
    onLinkClick: () => void;
    forceOpen?: boolean;
}) {
    const hasActiveChild = item.items?.some((child) => isRouteActive(child.url, currentPath)) ?? false;
    const isParentActive = isRouteActive(item.url, currentPath);
    const [isOpen, setIsOpen] = React.useState(forceOpen || hasActiveChild || isParentActive);

    React.useEffect(() => {
        if (forceOpen || hasActiveChild || isParentActive) {
            setIsOpen(true);
        }
    }, [forceOpen, hasActiveChild, isParentActive]);

    return (
        <Collapsible open={isOpen} onOpenChange={setIsOpen} className="group/collapsible">
            <SidebarMenuItem>
                <CollapsibleTrigger asChild>
                    <SidebarMenuButton
                        tooltip={item.title}
                        isActive={isParentActive || hasActiveChild}
                        className="w-full justify-between select-none"
                    >
                        <div className="flex items-center gap-2 min-w-0">
                            {item.icon && <item.icon className="size-4 shrink-0" />}
                            <span className="truncate">{item.title}</span>
                        </div>
                        <ChevronRight className="ml-auto size-4 shrink-0 transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90 text-sidebar-foreground/60" />
                    </SidebarMenuButton>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <SidebarMenuSub>
                        {item.items?.map((subItem) => {
                            const isSubActive = isRouteActive(subItem.url, currentPath);
                            return (
                                <SidebarMenuSubItem key={subItem.url + subItem.title}>
                                    <SidebarMenuSubButton asChild isActive={isSubActive}>
                                        <Link href={subItem.url} onClick={onLinkClick}>
                                            {subItem.icon && <subItem.icon className="size-3.5 shrink-0" />}
                                            <span className="truncate">{subItem.title}</span>
                                        </Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            );
                        })}
                    </SidebarMenuSub>
                </CollapsibleContent>
            </SidebarMenuItem>
        </Collapsible>
    );
}

function SingleNavItem({
    item,
    currentPath,
    onLinkClick,
}: {
    item: NavItem;
    currentPath: string;
    onLinkClick: () => void;
}) {
    const isActive = isRouteActive(item.url, currentPath);

    return (
        <SidebarMenuItem>
            <SidebarMenuButton asChild isActive={isActive} tooltip={item.title}>
                <Link href={item.url} onClick={onLinkClick}>
                    {item.icon && <item.icon className="size-4 shrink-0" />}
                    <span className="truncate">{item.title}</span>
                </Link>
            </SidebarMenuButton>
        </SidebarMenuItem>
    );
}

export function NavMain({ groups = [], items = [], searchQuery = '' }: NavMainProps) {
    const page = usePage();
    const { isMobile, setOpenMobile } = useSidebar();

    const handleLinkClick = React.useCallback(() => {
        if (isMobile) {
            setOpenMobile(false);
        }
    }, [isMobile, setOpenMobile]);

    const normalizedQuery = searchQuery.trim().toLowerCase();
    const isSearching = normalizedQuery.length > 0;

    const resolvedGroups: NavGroup[] = React.useMemo(() => {
        const sourceGroups = groups.length > 0 ? groups : items.length > 0 ? [{ title: 'Platform', items }] : [];

        if (!isSearching) {
            return sourceGroups;
        }

        return sourceGroups
            .map((group) => {
                const matchingItems = group.items
                    .map((item) => {
                        const itemMatches = item.title.toLowerCase().includes(normalizedQuery);
                        const matchingChildren = item.items?.filter((child) =>
                            child.title.toLowerCase().includes(normalizedQuery),
                        );

                        if (itemMatches) {
                            return item;
                        }

                        if (matchingChildren && matchingChildren.length > 0) {
                            return {
                                ...item,
                                items: matchingChildren,
                            };
                        }

                        return null;
                    })
                    .filter((item): item is NavItem => item !== null);

                return {
                    ...group,
                    items: matchingItems,
                };
            })
            .filter((group) => group.items.length > 0);
    }, [groups, items, normalizedQuery, isSearching]);

    if (resolvedGroups.length === 0 && isSearching) {
        return (
            <div className="px-4 py-8 text-center text-xs text-muted-foreground">
                No navigation found for &ldquo;{searchQuery}&rdquo;
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-1">
            {resolvedGroups.map((group) => {
                if (!group.items || group.items.length === 0) return null;

                return (
                    <SidebarGroup key={group.title} className="px-2 py-1">
                        <SidebarGroupLabel className="font-semibold tracking-wider uppercase text-[11px] text-sidebar-foreground/60 select-none">
                            {group.title}
                        </SidebarGroupLabel>
                        <SidebarMenu>
                            {group.items.map((item) =>
                                item.items && item.items.length > 0 ? (
                                    <CollapsibleNavItem
                                        key={item.title + item.url}
                                        item={item}
                                        currentPath={page.url}
                                        onLinkClick={handleLinkClick}
                                        forceOpen={isSearching}
                                    />
                                ) : (
                                    <SingleNavItem
                                        key={item.title + item.url}
                                        item={item}
                                        currentPath={page.url}
                                        onLinkClick={handleLinkClick}
                                    />
                                ),
                            )}
                        </SidebarMenu>
                    </SidebarGroup>
                );
            })}
        </div>
    );
}
