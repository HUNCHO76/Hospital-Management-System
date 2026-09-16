import { Can } from '@/components/authorization/can';
import { StatCard } from '@/components/dashboard/stat-card';
import { EmptyState } from '@/components/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import {
    radiologyAppointmentStatusLabel,
    radiologyAppointmentStatusVariant,
    radiologyOrderPriorityLabel,
    radiologyOrderStatusLabel,
    radiologyOrderStatusVariant,
} from '@/lib/radiology-status';
import { type BreadcrumbItem } from '@/types';
import { type RadiologyOrder, type RadiologyOrderStatus } from '@/types/radiology';
import { Head, Link } from '@inertiajs/react';
import {
    CalendarClock,
    ClipboardCheck,
    ClipboardList,
    FileCheck2,
    FileText,
    ListOrdered,
    ListTree,
    Scan,
    ShieldCheck,
    Stethoscope,
} from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Radiology', href: '/radiology' }];

interface RadiologyIndexProps {
    statusCounts: Partial<Record<RadiologyOrderStatus, number>>;
    statuses: RadiologyOrderStatus[];
    recentOrders: RadiologyOrder[];
}

function formatDateTime(value: string): string {
    const localValue = value.replace(/(Z|[+-]\d{2}:\d{2})$/, '');
    return new Date(localValue).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * The Radiology dashboard (Phase 5, Step 36, Part 30) — a landing page with
 * real status-distribution counts (never fabricated categories) and a
 * short recent-orders preview. Mirrors LaboratoryIndex exactly, scoped down
 * to only what this step actually builds: the order foundation. Scheduling
 * (Step 39), reception (Step 40), and examination (Step 41) quick links
 * were added incrementally as those surfaces were built; reporting/release
 * quick links belong to Step 42+, the same way Laboratory's own dashboard
 * grew its links incrementally across Steps 31-35.
 */
export default function RadiologyIndex({ statusCounts, statuses, recentOrders }: RadiologyIndexProps) {
    const patientName = (order: RadiologyOrder): string =>
        order.patient ? [order.patient.first_name, order.patient.middle_name, order.patient.last_name].filter(Boolean).join(' ') : '—';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Radiology" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Radiology</h1>
                        <p className="text-muted-foreground text-sm">Radiology orders placed across all clinical encounters.</p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Can permission="radiology-orders.view">
                            <Button asChild variant="outline">
                                <Link href={route('radiology.orders.index')}>
                                    <ListOrdered className="h-4 w-4" />
                                    All Orders
                                </Link>
                            </Button>
                        </Can>
                        <Can permission="radiology-procedures.view">
                            <Button asChild variant="outline">
                                <Link href={route('radiology-catalog.index')}>
                                    <ListTree className="h-4 w-4" />
                                    Catalog
                                </Link>
                            </Button>
                        </Can>
                        <Can permission="radiology-scheduling.view">
                            <Button asChild variant="outline">
                                <Link href={route('radiology-scheduling.index')}>
                                    <CalendarClock className="h-4 w-4" />
                                    Scheduling
                                </Link>
                            </Button>
                        </Can>
                        <Can permission="radiology-scheduling.view">
                            <Button asChild variant="outline">
                                <Link href={route('radiology-worklist.index')}>
                                    <ClipboardList className="h-4 w-4" />
                                    Worklist
                                </Link>
                            </Button>
                        </Can>
                        <Can permission="radiology-reception.view">
                            <Button asChild variant="outline">
                                <Link href={route('radiology-reception.index')}>
                                    <ClipboardCheck className="h-4 w-4" />
                                    Reception
                                </Link>
                            </Button>
                        </Can>
                        <Can permission="radiology-examination.view">
                            <Button asChild variant="outline">
                                <Link href={route('radiology-examinations.index')}>
                                    <Stethoscope className="h-4 w-4" />
                                    Examinations
                                </Link>
                            </Button>
                        </Can>
                        <Can permission="radiology-results.view">
                            <Button asChild variant="outline">
                                <Link href={route('radiology-results.index')}>
                                    <FileText className="h-4 w-4" />
                                    Results
                                </Link>
                            </Button>
                        </Can>
                        <Can permission="radiology-results.review">
                            <Button asChild variant="outline">
                                <Link href={route('radiology-verification.index')}>
                                    <ShieldCheck className="h-4 w-4" />
                                    Verification
                                </Link>
                            </Button>
                        </Can>
                        <Can permission="radiology-results.release">
                            <Button asChild variant="outline">
                                <Link href={route('radiology-release.index')}>
                                    <FileCheck2 className="h-4 w-4" />
                                    Release
                                </Link>
                            </Button>
                        </Can>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {statuses.map((status) => (
                        <StatCard key={status} label={radiologyOrderStatusLabel[status]} value={statusCounts[status] ?? 0} icon={Scan} />
                    ))}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Recent Orders</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Order No.</TableHead>
                                    <TableHead>Patient</TableHead>
                                    <TableHead>Procedures</TableHead>
                                    <TableHead>Priority</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Ordered</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {recentOrders.map((order) => (
                                    <TableRow key={order.id}>
                                        <TableCell>
                                            <Link href={route('radiology.orders.show', order.id)} className="font-medium hover:underline">
                                                {order.order_number}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-col">
                                                <span>{patientName(order)}</span>
                                                {order.patient && (
                                                    <span className="text-muted-foreground text-xs">{order.patient.patient_number}</span>
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-col gap-1">
                                                {order.items.map((item) => (
                                                    <div key={item.id} className="flex items-center gap-1.5">
                                                        <span className={item.status === 'cancelled' ? 'text-muted-foreground line-through' : ''}>
                                                            {item.procedure_name}
                                                        </span>
                                                        {item.status !== 'cancelled' && item.appointment && (
                                                            <Badge
                                                                variant={radiologyAppointmentStatusVariant[item.appointment.status]}
                                                                className="text-[10px]"
                                                            >
                                                                {radiologyAppointmentStatusLabel[item.appointment.status]}
                                                            </Badge>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        </TableCell>
                                        <TableCell>{radiologyOrderPriorityLabel[order.priority]}</TableCell>
                                        <TableCell>
                                            <Badge variant={radiologyOrderStatusVariant[order.status]}>
                                                {radiologyOrderStatusLabel[order.status]}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>{formatDateTime(order.ordered_at)}</TableCell>
                                    </TableRow>
                                ))}

                                {recentOrders.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="p-0">
                                            <EmptyState
                                                icon={Scan}
                                                title="No radiology orders yet"
                                                description="Radiology orders appear here once a doctor orders a procedure from a clinical encounter."
                                            />
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
