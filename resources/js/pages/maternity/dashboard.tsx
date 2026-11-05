import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    Baby,
    Bed,
    Calendar,
    CheckCircle2,
    Clock,
    HeartPulse,
    LineChart,
    Plus,
    Scale,
    ShieldAlert,
    Sparkles,
    Stethoscope,
    UserCheck,
    UserPlus,
    Users,
} from 'lucide-react';

function formatDateTime(value: string | null | undefined): string {
    if (!value) return '—';
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

function formatDate(value: string | null | undefined): string {
    if (!value) return '—';
    return new Date(value).toLocaleDateString(undefined, { dateStyle: 'medium' });
}

interface MetricSummary {
    total_visits_today: number;
    waiting_assessment: number;
    in_labour: number;
    admission_recommended: number;
    admitted_today: number;
    completed_today: number;
    high_risk_count: number;
    deliveries_this_week: number;
}

interface ActiveQueuePatient {
    id: number;
    visit_number: string;
    visit_purpose: string | null;
    maternity_status: string | null;
    created_at: string;
    patient: {
        id: number;
        patient_number: string;
        first_name: string;
        middle_name: string | null;
        last_name: string;
        gender: string;
        phone: string | null;
        date_of_birth: string | null;
    };
    practitioner?: {
        id: number;
        practitioner_number: string;
        staff?: {
            id: number;
            first_name: string;
            last_name: string;
        } | null;
    } | null;
}

interface RecentDeliveryRow {
    id: number;
    delivered_at: string;
    delivery_mode: string;
    baby_outcome: string;
    birth_weight_grams: number | null;
    apgar_1min: number | null;
    apgar_5min: number | null;
    patient: {
        id: number;
        patient_number: string;
        first_name: string;
        last_name: string;
    };
    babyPatient?: {
        id: number;
        patient_number: string;
        first_name: string;
        last_name: string;
        gender: string;
    } | null;
    conductedBy?: {
        id: number;
        UserName: string;
    } | null;
}

interface FollowUpRow {
    id: number;
    episode_number: string;
    follow_up_date: string;
    follow_up_purpose: string | null;
    follow_up_notes: string | null;
    patient: {
        id: number;
        patient_number: string;
        first_name: string;
        last_name: string;
        phone: string | null;
    };
    receptionVisit?: {
        id: number;
        visit_number: string;
    } | null;
}

interface MaternityDashboardProps {
    metrics: MetricSummary;
    activeQueue: ActiveQueuePatient[];
    recentDeliveries: RecentDeliveryRow[];
    upcomingFollowUps: FollowUpRow[];
    maternityStatuses: Record<string, string>;
    visitPurposes: Record<string, string>;
}

export default function MaternityDashboard({
    metrics,
    activeQueue = [],
    recentDeliveries = [],
    upcomingFollowUps = [],
    maternityStatuses = {},
    visitPurposes = {},
}: MaternityDashboardProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Maternity Dashboard', href: '/maternity/dashboard' },
    ];

    const getStatusBadge = (status: string | null) => {
        if (!status) return <Badge variant="outline">Waiting</Badge>;
        switch (status) {
            case 'waiting_for_assessment':
                return <Badge className="bg-amber-100 text-amber-800 hover:bg-amber-200 border-amber-300">Waiting Assessment</Badge>;
            case 'assessment_in_progress':
                return <Badge className="bg-blue-100 text-blue-800 hover:bg-blue-200 border-blue-300">Assessment in Progress</Badge>;
            case 'treatment_in_progress':
                return <Badge className="bg-rose-100 text-rose-800 hover:bg-rose-200 border-rose-300 font-semibold animate-pulse">In Labour / Care</Badge>;
            case 'admission_recommended':
                return <Badge variant="destructive" className="animate-bounce">Admission Recommended</Badge>;
            case 'admitted':
                return <Badge className="bg-purple-100 text-purple-800 hover:bg-purple-200 border-purple-300">Inpatient Admitted</Badge>;
            case 'completed':
                return <Badge className="bg-emerald-100 text-emerald-800 hover:bg-emerald-200 border-emerald-300">Completed</Badge>;
            default:
                return <Badge variant="secondary">{maternityStatuses[status] ?? status}</Badge>;
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Maternity Dashboard - St. Joseph DMI HMS" />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                {/* ── Top Header Banner ── */}
                <div className="rounded-xl border border-pink-200 bg-gradient-to-r from-pink-500/10 via-rose-500/10 to-purple-500/10 p-5 shadow-sm dark:border-pink-900/50">
                    <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                        <div>
                            <div className="flex items-center gap-2">
                                <span className="inline-flex items-center justify-center rounded-lg bg-pink-600 p-2 text-white shadow">
                                    <Baby className="h-6 w-6" />
                                </span>
                                <div>
                                    <h1 className="text-2xl font-bold tracking-tight text-foreground">
                                        Maternity Clinical Department
                                    </h1>
                                    <p className="text-sm text-muted-foreground">
                                        Dedicated Outpatient Maternity, Labour Monitoring, Delivery & Newborn Care
                                    </p>
                                </div>
                            </div>
                        </div>

                        {/* Top Action Shortcuts */}
                        <div className="flex flex-wrap items-center gap-2">
                            <Button asChild className="bg-pink-600 hover:bg-pink-700 text-white shadow-sm">
                                <Link href={route('maternity.queue')}>
                                    <Users className="mr-1.5 h-4 w-4" />
                                    Maternity Patient Queue
                                </Link>
                            </Button>

                            <Button variant="outline" asChild className="border-pink-300 text-pink-700 hover:bg-pink-50 dark:border-pink-800 dark:text-pink-300">
                                <Link href={route('reception.walk-in.create')}>
                                    <UserPlus className="mr-1.5 h-4 w-4" />
                                    Register Walk-in Patient
                                </Link>
                            </Button>
                        </div>
                    </div>
                </div>

                {/* ── Metric KPI Cards ── */}
                <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3.5">
                    <Card className="border-pink-200 dark:border-pink-900/40">
                        <CardContent className="p-4 flex items-center justify-between">
                            <div>
                                <p className="text-xs text-muted-foreground uppercase font-semibold">Today's Visits</p>
                                <p className="text-2xl font-bold text-foreground mt-0.5">{metrics.total_visits_today}</p>
                                <p className="text-[11px] text-muted-foreground">Outpatient registered</p>
                            </div>
                            <Users className="h-8 w-8 text-pink-500/30" />
                        </CardContent>
                    </Card>

                    <Card className="border-blue-200 dark:border-blue-900/40">
                        <CardContent className="p-4 flex items-center justify-between">
                            <div>
                                <p className="text-xs text-muted-foreground uppercase font-semibold">Awaiting Triage</p>
                                <p className="text-2xl font-bold text-blue-700 dark:text-blue-400 mt-0.5">{metrics.waiting_assessment}</p>
                                <p className="text-[11px] text-muted-foreground">Mother vitals pending</p>
                            </div>
                            <Stethoscope className="h-8 w-8 text-blue-500/30" />
                        </CardContent>
                    </Card>

                    <Card className="border-amber-200 dark:border-amber-900/40">
                        <CardContent className="p-4 flex items-center justify-between">
                            <div>
                                <p className="text-xs text-muted-foreground uppercase font-semibold">In Labour</p>
                                <p className="text-2xl font-bold text-amber-700 dark:text-amber-400 mt-0.5">{metrics.in_labour}</p>
                                <p className="text-[11px] text-muted-foreground">Partograph monitoring</p>
                            </div>
                            <Activity className="h-8 w-8 text-amber-500/30" />
                        </CardContent>
                    </Card>

                    <Card className="border-red-200 dark:border-red-900/40">
                        <CardContent className="p-4 flex items-center justify-between">
                            <div>
                                <p className="text-xs text-muted-foreground uppercase font-semibold">Admit Alert</p>
                                <p className="text-2xl font-bold text-red-700 dark:text-red-400 mt-0.5">{metrics.admission_recommended}</p>
                                <p className="text-[11px] text-muted-foreground">Action line crossed</p>
                            </div>
                            <AlertTriangle className="h-8 w-8 text-red-500/30" />
                        </CardContent>
                    </Card>

                    <Card className="border-purple-200 dark:border-purple-900/40">
                        <CardContent className="p-4 flex items-center justify-between">
                            <div>
                                <p className="text-xs text-muted-foreground uppercase font-semibold">High Risk Active</p>
                                <p className="text-2xl font-bold text-purple-700 dark:text-purple-400 mt-0.5">{metrics.high_risk_count}</p>
                                <p className="text-[11px] text-muted-foreground">Obstetric risk flagged</p>
                            </div>
                            <ShieldAlert className="h-8 w-8 text-purple-500/30" />
                        </CardContent>
                    </Card>

                    <Card className="border-emerald-200 dark:border-emerald-900/40">
                        <CardContent className="p-4 flex items-center justify-between">
                            <div>
                                <p className="text-xs text-muted-foreground uppercase font-semibold">Deliveries</p>
                                <p className="text-2xl font-bold text-emerald-700 dark:text-emerald-400 mt-0.5">{metrics.deliveries_this_week}</p>
                                <p className="text-[11px] text-muted-foreground">Live births this week</p>
                            </div>
                            <Sparkles className="h-8 w-8 text-emerald-500/30" />
                        </CardContent>
                    </Card>
                </div>

                {/* ── Active Maternity Queue Table ── */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <div>
                            <CardTitle className="text-base flex items-center gap-2">
                                <Users className="h-5 w-5 text-pink-600" />
                                Active Maternity Outpatient Queue
                            </CardTitle>
                            <CardDescription>
                                Walk-in and registered maternity patients currently receiving outpatient care.
                            </CardDescription>
                        </div>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={route('maternity.queue')}>
                                View Full Queue ({metrics.total_visits_today})
                                <ArrowRight className="ml-1.5 h-4 w-4" />
                            </Link>
                        </Button>
                    </CardHeader>
                    <CardContent>
                        {activeQueue.length === 0 ? (
                            <div className="py-8 text-center text-sm text-muted-foreground">
                                No active patients in the maternity queue right now.
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow className="bg-muted/40">
                                            <TableHead className="w-[120px]">Visit #</TableHead>
                                            <TableHead>Patient Details</TableHead>
                                            <TableHead>Visit Purpose</TableHead>
                                            <TableHead>Queue Status</TableHead>
                                            <TableHead>Assigned Clinician</TableHead>
                                            <TableHead>Time Registered</TableHead>
                                            <TableHead className="text-right">Action</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {activeQueue.map((item) => {
                                            const fullName = `${item.patient.first_name} ${item.patient.middle_name ? item.patient.middle_name + ' ' : ''}${item.patient.last_name}`;
                                            return (
                                                <TableRow key={item.id}>
                                                    <TableCell className="font-mono text-xs font-semibold">
                                                        #{item.visit_number}
                                                    </TableCell>
                                                    <TableCell>
                                                        <div>
                                                            <p className="font-bold text-sm text-foreground">{fullName}</p>
                                                            <p className="text-xs text-muted-foreground">
                                                                Patient #: {item.patient.patient_number} {item.patient.phone ? `• ${item.patient.phone}` : ''}
                                                            </p>
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge variant="outline" className="text-xs">
                                                            {visitPurposes[item.visit_purpose ?? ''] ?? item.visit_purpose ?? 'Maternity'}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell>
                                                        {getStatusBadge(item.maternity_status)}
                                                    </TableCell>
                                                    <TableCell className="text-xs text-muted-foreground">
                                                        {item.practitioner?.staff ? `${item.practitioner.staff.first_name} ${item.practitioner.staff.last_name}` : 'Unassigned'}
                                                    </TableCell>
                                                    <TableCell className="text-xs text-muted-foreground">
                                                        {formatDateTime(item.created_at)}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <Button size="sm" className="bg-pink-600 hover:bg-pink-700 text-white text-xs h-8" asChild>
                                                            <Link href={route('maternity.mother-assessment', item.id)}>
                                                                <Stethoscope className="mr-1 h-3.5 w-3.5" />
                                                                Assess Patient
                                                            </Link>
                                                        </Button>
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* ── Two Columns: Recent Deliveries & Upcoming Follow-ups ── */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* Recent Deliveries */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base flex items-center gap-2">
                                <Sparkles className="h-5 w-5 text-emerald-600" />
                                Recent Deliveries & Newborn Records
                            </CardTitle>
                            <CardDescription>
                                Independent clinical records created for newborns linked to mothers.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {recentDeliveries.length === 0 ? (
                                <div className="py-8 text-center text-xs text-muted-foreground">
                                    No deliveries recorded yet this week.
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    {recentDeliveries.map((del) => (
                                        <div key={del.id} className="rounded-lg border p-3 bg-muted/20 space-y-2">
                                            <div className="flex items-center justify-between">
                                                <div className="space-y-0.5">
                                                    <p className="text-sm font-bold text-foreground">
                                                        Mother: {del.patient.first_name} {del.patient.last_name}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        Delivered: {formatDateTime(del.delivered_at)} • Mode: <span className="capitalize">{del.delivery_mode.replace(/_/g, ' ')}</span>
                                                    </p>
                                                </div>
                                                <Badge className="bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 capitalize text-xs">
                                                    {del.baby_outcome.replace(/_/g, ' ')}
                                                </Badge>
                                            </div>

                                            {del.babyPatient && (
                                                <div className="rounded border bg-white dark:bg-gray-900/60 p-2 text-xs flex items-center justify-between">
                                                    <div className="space-y-0.5">
                                                        <span className="font-semibold text-foreground">
                                                            Newborn #{del.babyPatient.patient_number}
                                                        </span>
                                                        <p className="text-muted-foreground text-[11px]">
                                                            Sex: <strong className="capitalize">{del.babyPatient.gender}</strong> • Weight: <strong>{del.birth_weight_grams ? `${del.birth_weight_grams}g` : '—'}</strong> • APGAR: <strong>{del.apgar_1min ?? '—'}/10</strong> (1m)
                                                        </p>
                                                    </div>
                                                    <Badge variant="outline" className="border-cyan-300 text-cyan-700 text-[10px]">
                                                        Independent Record
                                                    </Badge>
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Upcoming Follow-up Schedule (Phase 9 & 10) */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base flex items-center gap-2">
                                <Calendar className="h-5 w-5 text-purple-600" />
                                Upcoming Maternity Follow-up Schedule
                            </CardTitle>
                            <CardDescription>
                                Scheduled antenatal, postnatal, and infant growth follow-up appointments.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {upcomingFollowUps.length === 0 ? (
                                <div className="py-8 text-center text-xs text-muted-foreground">
                                    No maternity follow-up appointments scheduled yet.
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    {upcomingFollowUps.map((fu) => (
                                        <div key={fu.id} className="rounded-lg border p-3 bg-muted/20 space-y-1 text-xs">
                                            <div className="flex items-center justify-between">
                                                <span className="font-bold text-sm text-foreground">
                                                    {fu.patient.first_name} {fu.patient.last_name}
                                                </span>
                                                <Badge variant="outline" className="border-purple-300 text-purple-700">
                                                    Due: {formatDate(fu.follow_up_date)}
                                                </Badge>
                                            </div>

                                            <div className="flex items-center justify-between text-muted-foreground text-[11px]">
                                                <span>Purpose: <strong className="text-foreground capitalize">{fu.follow_up_purpose?.replace(/_/g, ' ') || 'ANC Routine'}</strong></span>
                                                <span>Phone: {fu.patient.phone ?? '—'}</span>
                                            </div>

                                            {fu.follow_up_notes && (
                                                <p className="text-muted-foreground italic pt-1 border-t">
                                                    "{fu.follow_up_notes}"
                                                </p>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}

