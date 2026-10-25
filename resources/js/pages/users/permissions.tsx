import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import {
    type OverrideEffect,
    type PermissionItem,
    type PermissionMatrix,
} from '@/types/user-permission';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowLeft,
    Ban,
    Check,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    Filter,
    Info,
    RotateCcw,
    Save,
    Search,
    Shield,
    ShieldAlert,
    ShieldCheck,
    Undo2,
    X,
} from 'lucide-react';
import React, { useMemo, useState } from 'react';

interface Props {
    matrix: PermissionMatrix;
    canManage: boolean;
}

export default function UserPermissionsPage({ matrix, canManage }: Props) {
    const { user, permission_groups, counts } = matrix;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Staff', href: '/staff' },
        {
            title: user.staff?.full_name ?? user.username,
            href: user.staff ? `/staff/${user.staff.id}` : '#',
        },
        { title: 'User Permissions', href: '#' },
    ];

    // State for overrides staged locally: permission_id -> 'allow' | 'deny' | 'inherit'
    // 'inherit' means removing any existing override
    const [stagedOverrides, setStagedOverrides] = useState<Record<number, OverrideEffect>>({});
    const [reason, setReason] = useState('');
    const [searchQuery, setSearchQuery] = useState('');
    const [statusFilter, setStatusFilter] = useState<'all' | 'overrides' | 'effective_allowed' | 'effective_denied' | 'inherited'>('all');
    const [selectedGroup, setSelectedGroup] = useState<string>('all');
    const [collapsedGroups, setCollapsedGroups] = useState<Record<string, boolean>>({});
    const [reviewDialogOpen, setReviewDialogOpen] = useState(false);
    const [resetDialogOpen, setResetDialogOpen] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);

    // Flatten all permissions for quick lookups and stats
    const allPermissionsMap = useMemo(() => {
        const map = new Map<number, PermissionItem>();
        for (const grp of permission_groups) {
            for (const mod of grp.modules) {
                for (const p of mod.permissions) {
                    map.set(p.id, p);
                }
            }
        }
        return map;
    }, [permission_groups]);

    // Handle staged override change
    const handleSetOverride = (permission: PermissionItem, desired: OverrideEffect) => {
        // If current saved override matches desired, remove from staged
        const savedOverride = permission.override ?? 'inherit';
        if (savedOverride === desired) {
            setStagedOverrides((prev) => {
                const next = { ...prev };
                delete next[permission.id];
                return next;
            });
            return;
        }

        setStagedOverrides((prev) => ({
            ...prev,
            [permission.id]: desired,
        }));
    };

    // Calculate effective status considering staged overrides
    const getResolvedState = (permission: PermissionItem) => {
        const staged = stagedOverrides[permission.id];
        const effectiveOverride = staged !== undefined ? staged : permission.override;

        let effective = false;
        if (effectiveOverride === 'deny') {
            effective = false;
        } else if (effectiveOverride === 'allow') {
            effective = true;
        } else {
            effective = permission.is_inherited || permission.is_direct;
        }

        const isChanged = staged !== undefined && staged !== (permission.override ?? 'inherit');

        return {
            override: effectiveOverride,
            isEffective: effective,
            isChanged,
            stagedEffect: staged,
        };
    };

    // Calculate staged statistics
    const stagedChangesList = useMemo(() => {
        return Object.entries(stagedOverrides).map(([permIdStr, effect]) => {
            const permId = Number(permIdStr);
            const perm = allPermissionsMap.get(permId);
            const originalEffect = perm?.override ?? 'inherit';
            return {
                permission: perm,
                originalEffect,
                newEffect: effect,
            };
        });
    }, [stagedOverrides, allPermissionsMap]);

    const hasStagedChanges = stagedChangesList.length > 0;

    // Filter groups and permissions
    const filteredGroups = useMemo(() => {
        const q = searchQuery.toLowerCase().trim();

        return permission_groups
            .map((grp) => {
                if (selectedGroup !== 'all' && grp.group !== selectedGroup) {
                    return null;
                }

                const filteredModules = grp.modules
                    .map((mod) => {
                        const filteredPermissions = mod.permissions.filter((p) => {
                            const { override, isEffective } = getResolvedState(p);

                            // Text search filter
                            if (q) {
                                const matchesName = p.name.toLowerCase().includes(q);
                                const matchesTitle = p.title.toLowerCase().includes(q);
                                const matchesDesc = p.description.toLowerCase().includes(q);
                                const matchesModule = mod.module.toLowerCase().includes(q);
                                if (!matchesName && !matchesTitle && !matchesDesc && !matchesModule) {
                                    return false;
                                }
                            }

                            // Status filter
                            if (statusFilter === 'overrides') {
                                return override === 'allow' || override === 'deny';
                            }
                            if (statusFilter === 'effective_allowed') {
                                return isEffective;
                            }
                            if (statusFilter === 'effective_denied') {
                                return !isEffective;
                            }
                            if (statusFilter === 'inherited') {
                                return p.is_inherited && !override;
                            }

                            return true;
                        });

                        if (filteredPermissions.length === 0) return null;

                        return {
                            ...mod,
                            permissions: filteredPermissions,
                        };
                    })
                    .filter((m): m is NonNullable<typeof m> => m !== null);

                if (filteredModules.length === 0) return null;

                return {
                    ...grp,
                    modules: filteredModules,
                };
            })
            .filter((g): g is NonNullable<typeof g> => g !== null);
    }, [permission_groups, searchQuery, selectedGroup, statusFilter, stagedOverrides]);

    const toggleGroupCollapse = (groupName: string) => {
        setCollapsedGroups((prev) => ({
            ...prev,
            [groupName]: !prev[groupName],
        }));
    };

    const handleSave = () => {
        if (!hasStagedChanges) return;

        setIsSubmitting(true);
        const payload = Object.entries(stagedOverrides).map(([id, effect]) => ({
            permission_id: Number(id),
            effect,
        }));

        router.put(
            route('users.permissions.update', user.id),
            {
                overrides: payload,
                reason: reason.trim() || undefined,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setStagedOverrides({});
                    setReason('');
                    setReviewDialogOpen(false);
                    setIsSubmitting(false);
                },
                onError: () => {
                    setIsSubmitting(false);
                },
            },
        );
    };

    const handleResetAll = () => {
        setIsSubmitting(true);
        router.delete(route('users.permissions.reset', user.id), {
            preserveScroll: true,
            onSuccess: () => {
                setStagedOverrides({});
                setResetDialogOpen(false);
                setIsSubmitting(false);
            },
            onError: () => {
                setIsSubmitting(false);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Permissions: ${user.staff?.full_name ?? user.username}`} />

            <div className="flex flex-col gap-6 p-4 md:p-6 max-w-7xl mx-auto w-full pb-28">
                {/* Header with back button */}
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <Button variant="ghost" size="icon" asChild>
                            <Link href={user.staff ? route('staff.show', user.staff.id) : route('staff.index')}>
                                <ArrowLeft className="h-5 w-5" />
                            </Link>
                        </Button>
                        <div>
                            <div className="flex items-center gap-2">
                                <h1 className="text-2xl font-bold tracking-tight">
                                    {user.staff?.full_name ?? user.username}
                                </h1>
                                <Badge variant={user.status === 'active' ? 'default' : 'secondary'}>
                                    {user.status}
                                </Badge>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                Individual User Permission Overrides Management · @{user.username}
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        {(counts.overridden_allow_count > 0 || counts.overridden_deny_count > 0) && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setResetDialogOpen(true)}
                                className="text-destructive hover:text-destructive hover:bg-destructive/10"
                            >
                                <RotateCcw className="h-4 w-4 mr-1.5" />
                                Reset All to Role Defaults
                            </Button>
                        )}
                    </div>
                </div>

                {/* Important notice banner */}
                <div className="flex items-start gap-3 rounded-lg border border-blue-500/30 bg-blue-500/10 p-4 text-sm text-blue-950 dark:text-blue-200">
                    <Info className="h-5 w-5 shrink-0 text-blue-600 dark:text-blue-400 mt-0.5" />
                    <div>
                        <p className="font-semibold">Granular User Permission Control</p>
                        <p className="text-muted-foreground mt-0.5">
                            Permissions defined on shared roles apply to all users assigned to those roles. Changes made
                            here apply <strong>strictly to this individual user</strong> via targeted overrides without
                            modifying role definitions. Explicit denials always take absolute precedence over role inheritance.
                        </p>
                    </div>
                </div>

                {/* Summary Card */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Assigned Roles</CardDescription>
                            <CardTitle className="text-lg">
                                {user.roles.length > 0 ? (
                                    <div className="flex flex-wrap gap-1 mt-1">
                                        {user.roles.map((r) => (
                                            <Badge key={r} variant="outline" className="font-mono text-xs">
                                                {r}
                                            </Badge>
                                        ))}
                                    </div>
                                ) : (
                                    <span className="text-muted-foreground text-sm font-normal">None</span>
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-xs text-muted-foreground">
                                {user.staff?.department ? `${user.staff.department} · ` : ''}
                                {user.staff?.job_title ?? 'Staff Account'}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Inherited via Roles</CardDescription>
                            <CardTitle className="text-2xl font-bold flex items-center gap-2">
                                <ShieldCheck className="h-6 w-6 text-primary" />
                                {counts.inherited_count}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-xs text-muted-foreground">Base permissions from assigned roles</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Explicitly Allowed Overrides</CardDescription>
                            <CardTitle className="text-2xl font-bold flex items-center gap-2 text-emerald-600 dark:text-emerald-400">
                                <CheckCircle2 className="h-6 w-6" />
                                +{counts.overridden_allow_count}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-xs text-muted-foreground">Extra permissions granted to this user</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Explicitly Denied Overrides</CardDescription>
                            <CardTitle className="text-2xl font-bold flex items-center gap-2 text-rose-600 dark:text-rose-400">
                                <Ban className="h-6 w-6" />
                                -{counts.overridden_deny_count}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-xs text-muted-foreground">Role permissions blocked for this user</p>
                        </CardContent>
                    </Card>
                </div>

                {/* Search & Filter Toolbar */}
                <div className="flex flex-col sm:flex-row flex-wrap items-stretch sm:items-center justify-between gap-3 bg-card p-3 rounded-lg border">
                    <div className="flex flex-1 items-center gap-2 min-w-[260px]">
                        <Search className="h-4 w-4 text-muted-foreground shrink-0" />
                        <Input
                            placeholder="Filter permissions by name, title, or module..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="h-9 border-none bg-transparent shadow-none focus-visible:ring-0"
                        />
                        {searchQuery && (
                            <Button
                                variant="ghost"
                                size="icon"
                                className="h-7 w-7"
                                onClick={() => setSearchQuery('')}
                            >
                                <X className="h-3.5 w-3.5" />
                            </Button>
                        )}
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {/* Group Filter */}
                        <Select value={selectedGroup} onValueChange={setSelectedGroup}>
                            <SelectTrigger className="h-9 w-[180px] text-xs">
                                <SelectValue placeholder="All Functional Areas" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Functional Areas</SelectItem>
                                {permission_groups.map((g) => (
                                    <SelectItem key={g.group} value={g.group}>
                                        {g.group}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        {/* Status Filter */}
                        <Select
                            value={statusFilter}
                            onValueChange={(v) =>
                                setStatusFilter(v as 'all' | 'overrides' | 'effective_allowed' | 'effective_denied' | 'inherited')
                            }
                        >
                            <SelectTrigger className="h-9 w-[170px] text-xs">
                                <SelectValue placeholder="All Permissions" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Permissions</SelectItem>
                                <SelectItem value="overrides">Has Override Only</SelectItem>
                                <SelectItem value="effective_allowed">Effective Allowed</SelectItem>
                                <SelectItem value="effective_denied">Effective Denied</SelectItem>
                                <SelectItem value="inherited">Inherited Unmodified</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                {/* Permission Groups & Modules */}
                <div className="flex flex-col gap-6">
                    {filteredGroups.length === 0 ? (
                        <div className="p-12 text-center border rounded-lg bg-card">
                            <ShieldAlert className="h-10 w-10 text-muted-foreground mx-auto mb-2 opacity-50" />
                            <h3 className="font-semibold text-base">No matching permissions found</h3>
                            <p className="text-sm text-muted-foreground mt-1">
                                Try adjusting your search query or status filter.
                            </p>
                        </div>
                    ) : (
                        filteredGroups.map((group) => {
                            const isCollapsed = collapsedGroups[group.group] ?? false;

                            return (
                                <div
                                    key={group.group}
                                    className="border rounded-xl bg-card overflow-hidden shadow-sm"
                                >
                                    {/* Group Header */}
                                    <button
                                        type="button"
                                        onClick={() => toggleGroupCollapse(group.group)}
                                        className="w-full flex items-center justify-between p-4 bg-muted/40 hover:bg-muted/60 transition-colors text-left font-medium border-b"
                                    >
                                        <div className="flex items-center gap-2">
                                            {isCollapsed ? (
                                                <ChevronRight className="h-4 w-4 text-muted-foreground" />
                                            ) : (
                                                <ChevronDown className="h-4 w-4 text-muted-foreground" />
                                            )}
                                            <span className="font-semibold text-base">{group.group}</span>
                                            <Badge variant="secondary" className="text-xs">
                                                {group.modules.reduce((acc, m) => acc + m.permissions.length, 0)} permissions
                                            </Badge>
                                        </div>
                                    </button>

                                    {!isCollapsed && (
                                        <div className="divide-y divide-border">
                                            {group.modules.map((module) => (
                                                <div key={module.module} className="p-4 sm:p-5">
                                                    <h4 className="text-xs font-bold uppercase tracking-wider text-muted-foreground mb-3 flex items-center gap-2">
                                                        <span>{module.module}</span>
                                                        <span className="h-px bg-border flex-1" />
                                                    </h4>

                                                    <div className="grid gap-3">
                                                        {module.permissions.map((perm) => {
                                                            const { override, isEffective, isChanged, stagedEffect } =
                                                                getResolvedState(perm);

                                                            return (
                                                                <div
                                                                    key={perm.id}
                                                                    className={`flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3.5 rounded-lg border transition-all ${
                                                                        isChanged
                                                                            ? 'border-amber-400 bg-amber-500/5 ring-1 ring-amber-400/50'
                                                                            : override === 'deny'
                                                                              ? 'border-rose-200 dark:border-rose-900/50 bg-rose-500/5'
                                                                              : override === 'allow'
                                                                                ? 'border-emerald-200 dark:border-emerald-900/50 bg-emerald-500/5'
                                                                                : 'border-border/70 bg-card hover:bg-muted/20'
                                                                    }`}
                                                                >
                                                                    {/* Left: Permission Info */}
                                                                    <div className="space-y-1 max-w-xl">
                                                                        <div className="flex flex-wrap items-center gap-2">
                                                                            <span className="font-semibold text-sm">
                                                                                {perm.title}
                                                                            </span>
                                                                            <code className="text-[11px] px-1.5 py-0.5 bg-muted rounded font-mono text-muted-foreground">
                                                                                {perm.name}
                                                                            </code>

                                                                            {/* Effective Badge */}
                                                                            <Badge
                                                                                variant={isEffective ? 'default' : 'secondary'}
                                                                                className={`text-[10px] uppercase font-bold tracking-wider ${
                                                                                    isEffective
                                                                                        ? 'bg-emerald-600 hover:bg-emerald-600'
                                                                                        : 'bg-muted text-muted-foreground'
                                                                                }`}
                                                                            >
                                                                                {isEffective ? 'Effective: Allowed' : 'Effective: Denied'}
                                                                            </Badge>

                                                                            {/* Override Origin Badge */}
                                                                            {override === 'deny' && (
                                                                                <Badge variant="destructive" className="text-[10px]">
                                                                                    Explicitly Denied
                                                                                </Badge>
                                                                            )}
                                                                            {override === 'allow' && (
                                                                                <Badge className="text-[10px] bg-emerald-600">
                                                                                    Explicitly Allowed
                                                                                </Badge>
                                                                            )}
                                                                            {!override && perm.is_inherited && (
                                                                                <Badge variant="outline" className="text-[10px]">
                                                                                    Inherited ({perm.inherited_from_roles.join(', ')})
                                                                                </Badge>
                                                                            )}
                                                                            {!override && !perm.is_inherited && (
                                                                                <Badge variant="outline" className="text-[10px] text-muted-foreground">
                                                                                    Not Inherited
                                                                                </Badge>
                                                                            )}

                                                                            {isChanged && (
                                                                                <Badge className="text-[10px] bg-amber-500 text-white animate-pulse">
                                                                                    Staged: {stagedEffect}
                                                                                </Badge>
                                                                            )}
                                                                        </div>

                                                                        <p className="text-xs text-muted-foreground">
                                                                            {perm.description}
                                                                        </p>

                                                                        {perm.override_reason && (
                                                                            <p className="text-[11px] text-muted-foreground italic flex items-center gap-1">
                                                                                <span>Audit note:</span> {perm.override_reason}
                                                                            </p>
                                                                        )}
                                                                    </div>

                                                                    {/* Right: Override Control Buttons */}
                                                                    <div className="flex items-center gap-1 self-end sm:self-center shrink-0">
                                                                        <div className="inline-flex rounded-md shadow-sm border bg-muted/40 p-0.5">
                                                                            {/* Inherit / Reset */}
                                                                            <button
                                                                                type="button"
                                                                                onClick={() => handleSetOverride(perm, 'inherit')}
                                                                                className={`px-2.5 py-1 text-xs font-medium rounded-sm transition-colors ${
                                                                                    override === null || override === undefined
                                                                                        ? 'bg-background text-foreground shadow-sm'
                                                                                        : 'text-muted-foreground hover:text-foreground'
                                                                                }`}
                                                                                title="Inherit from role assignments"
                                                                            >
                                                                                Inherit
                                                                            </button>

                                                                            {/* Allow */}
                                                                            <button
                                                                                type="button"
                                                                                onClick={() => handleSetOverride(perm, 'allow')}
                                                                                className={`px-2.5 py-1 text-xs font-medium rounded-sm transition-colors flex items-center gap-1 ${
                                                                                    override === 'allow'
                                                                                        ? 'bg-emerald-600 text-white shadow-sm font-semibold'
                                                                                        : 'text-muted-foreground hover:text-emerald-600'
                                                                                }`}
                                                                                title="Explicitly allow for this user"
                                                                            >
                                                                                <Check className="h-3 w-3" />
                                                                                Allow
                                                                            </button>

                                                                            {/* Deny */}
                                                                            <button
                                                                                type="button"
                                                                                onClick={() => handleSetOverride(perm, 'deny')}
                                                                                className={`px-2.5 py-1 text-xs font-medium rounded-sm transition-colors flex items-center gap-1 ${
                                                                                    override === 'deny'
                                                                                        ? 'bg-rose-600 text-white shadow-sm font-semibold'
                                                                                        : 'text-muted-foreground hover:text-rose-600'
                                                                                }`}
                                                                                title="Explicitly deny/block for this user"
                                                                            >
                                                                                <Ban className="h-3 w-3" />
                                                                                Deny
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            );
                                                        })}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            );
                        })
                    )}
                </div>
            </div>

            {/* Sticky Staged Changes Floating Bar */}
            {hasStagedChanges && (
                <div className="fixed bottom-0 inset-x-0 bg-background/95 backdrop-blur border-t shadow-2xl p-4 z-40 animate-in slide-in-from-bottom duration-200">
                    <div className="max-w-7xl mx-auto flex flex-wrap items-center justify-between gap-4">
                        <div className="flex items-center gap-3">
                            <div className="h-9 w-9 rounded-full bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center font-bold">
                                {stagedChangesList.length}
                            </div>
                            <div>
                                <p className="font-semibold text-sm">
                                    You have {stagedChangesList.length} unsaved permission override change(s) staged
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Review the adjustments before applying them to this user's account.
                                </p>
                            </div>
                        </div>

                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setStagedOverrides({})}
                                disabled={isSubmitting}
                            >
                                <Undo2 className="h-4 w-4 mr-1.5" />
                                Discard
                            </Button>

                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={() => setReviewDialogOpen(true)}
                                disabled={isSubmitting}
                            >
                                Review Changes
                            </Button>

                            <Button
                                size="sm"
                                onClick={handleSave}
                                disabled={isSubmitting}
                                className="bg-primary"
                            >
                                <Save className="h-4 w-4 mr-1.5" />
                                {isSubmitting ? 'Saving...' : 'Save Overrides'}
                            </Button>
                        </div>
                    </div>
                </div>
            )}

            {/* Review Changes Dialog */}
            <Dialog open={reviewDialogOpen} onOpenChange={setReviewDialogOpen}>
                <DialogContent className="max-w-2xl max-h-[85vh] flex flex-col">
                    <DialogHeader>
                        <DialogTitle>Review Staged Permission Changes</DialogTitle>
                        <DialogDescription>
                            Confirm the individual permission modifications for user{' '}
                            <strong>{user.username}</strong> before persisting.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="flex-1 overflow-y-auto space-y-3 py-2 pr-1">
                        <div className="divide-y rounded-lg border">
                            {stagedChangesList.map(({ permission, originalEffect, newEffect }) => (
                                <div key={permission?.id} className="p-3 flex items-center justify-between gap-3 text-sm">
                                    <div>
                                        <p className="font-medium">{permission?.title}</p>
                                        <p className="text-xs font-mono text-muted-foreground">{permission?.name}</p>
                                    </div>
                                    <div className="flex items-center gap-2 text-xs">
                                        <Badge variant="outline" className="capitalize">
                                            {originalEffect}
                                        </Badge>
                                        <span className="text-muted-foreground">→</span>
                                        <Badge
                                            className={
                                                newEffect === 'allow'
                                                    ? 'bg-emerald-600'
                                                    : newEffect === 'deny'
                                                      ? 'bg-rose-600'
                                                      : 'bg-muted text-foreground'
                                            }
                                        >
                                            {newEffect === 'inherit' ? 'Reset to Inherited' : `Explicit ${newEffect}`}
                                        </Badge>
                                    </div>
                                </div>
                            ))}
                        </div>

                        <div className="space-y-1.5 pt-2">
                            <Label htmlFor="audit_reason" className="text-xs font-semibold">
                                Audit Reason (Optional / Recommended)
                            </Label>
                            <Textarea
                                id="audit_reason"
                                placeholder="Explain why these overrides are granted or revoked (e.g., Temporary clinical supervisor cover)..."
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                                rows={2}
                                className="text-sm"
                            />
                        </div>
                    </div>

                    <DialogFooter className="gap-2 sm:gap-0">
                        <Button variant="outline" onClick={() => setReviewDialogOpen(false)}>
                            Keep Editing
                        </Button>
                        <Button onClick={handleSave} disabled={isSubmitting}>
                            {isSubmitting ? 'Saving...' : 'Confirm & Save Overrides'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Reset All Overrides Confirmation Dialog */}
            <Dialog open={resetDialogOpen} onOpenChange={setResetDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-destructive">
                            <AlertCircle className="h-5 w-5" />
                            Reset All User Permission Overrides
                        </DialogTitle>
                        <DialogDescription>
                            Are you sure you want to remove all custom permission overrides for{' '}
                            <strong>{user.username}</strong>?
                        </DialogDescription>
                    </DialogHeader>

                    <div className="text-sm text-muted-foreground space-y-2 py-2">
                        <p>
                            This will purge all explicit <strong>allow</strong> and <strong>deny</strong> overrides
                            associated with this user account.
                        </p>
                        <p>
                            Their effective permissions will immediately return to standard inheritance from their
                            assigned roles ({user.roles.join(', ') || 'no roles'}).
                        </p>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setResetDialogOpen(false)} disabled={isSubmitting}>
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={handleResetAll}
                            disabled={isSubmitting}
                        >
                            {isSubmitting ? 'Resetting...' : 'Yes, Reset to Role Defaults'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
