import { PageHeader } from '@/components/page-header';
import { StatCard } from '@/components/dashboard/stat-card';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Activity, ShieldCheck, Stethoscope, UserPlus, UsersRound } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

interface DashboardStats {
    patients: number;
    staff: number;
    practitioners: number;
    activeInsurance: number;
}

interface RecentActivityItem {
    id: number;
    action: string;
    module: string;
    user: string | null;
    created_at: string;
}

interface DashboardProps {
    stats: DashboardStats;
    recentActivity: RecentActivityItem[] | null;
}

function formatDateTime(value: string): string {
    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}

export default function Dashboard({ stats, recentActivity }: DashboardProps) {
    const { auth } = usePage<SharedData>().props;
    const { user } = auth;
    const { can } = usePermissions();

    const quickActions = [
        { label: 'Register Patient', href: route('patients.create'), permission: 'patients.create', icon: UserPlus },
        { label: 'Add Staff', href: route('staff.create'), permission: 'staff.create', icon: UsersRound },
        { label: 'Add Practitioner', href: route('practitioners.create'), permission: 'practitioners.create', icon: Stethoscope },
        { label: 'View Audit Logs', href: route('audit-logs.index'), permission: 'audit-logs.view', icon: Activity },
    ].filter((action) => can(action.permission));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />

            <div className="flex flex-col gap-6 p-4">
                <PageHeader title={`Welcome back, ${user.UserName}`} description="Here is an overview of activity across the hospital." />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard label="Registered Patients" value={stats.patients.toLocaleString()} icon={UsersRound} />
                    <StatCard label="Active Staff" value={stats.staff.toLocaleString()} icon={UsersRound} hint="Active only" />
                    <StatCard label="Practitioners" value={stats.practitioners.toLocaleString()} icon={Stethoscope} />
                    <StatCard label="Active Insurance Policies" value={stats.activeInsurance.toLocaleString()} icon={ShieldCheck} />
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    {quickActions.length > 0 && (
                        <Card className="lg:col-span-1">
                            <CardHeader>
                                <CardTitle className="text-base">Quick Actions</CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-2">
                                {quickActions.map((action) => (
                                    <Button key={action.label} asChild variant="outline" className="justify-start">
                                        <Link href={action.href}>
                                            <action.icon className="h-4 w-4" />
                                            {action.label}
                                        </Link>
                                    </Button>
                                ))}
                            </CardContent>
                        </Card>
                    )}

                    {recentActivity !== null && (
                        <Card className={quickActions.length > 0 ? 'lg:col-span-2' : 'lg:col-span-3'}>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0">
                                <CardTitle className="text-base">Recent Activity</CardTitle>
                                <Button asChild variant="ghost" size="sm">
                                    <Link href={route('audit-logs.index')}>View all</Link>
                                </Button>
                            </CardHeader>
                            <CardContent>
                                {recentActivity.length > 0 ? (
                                    <ul className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                                        {recentActivity.map((item) => (
                                            <li key={item.id} className="flex items-center justify-between gap-4 py-2.5 text-sm">
                                                <div className="flex items-center gap-3">
                                                    <span className="bg-primary h-2 w-2 shrink-0 rounded-full" aria-hidden="true" />
                                                    <div>
                                                        <p className="font-medium">{item.action}</p>
                                                        <p className="text-muted-foreground text-xs">
                                                            {item.module}
                                                            {item.user ? ` · ${item.user}` : ''}
                                                        </p>
                                                    </div>
                                                </div>
                                                <span className="text-muted-foreground shrink-0 text-xs whitespace-nowrap">
                                                    {formatDateTime(item.created_at)}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <EmptyState icon={Activity} title="No recent activity" description="System activity will appear here as it happens." />
                                )}
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
