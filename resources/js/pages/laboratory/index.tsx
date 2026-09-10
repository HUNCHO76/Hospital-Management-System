import { Can } from '@/components/authorization/can';
import { StatCard } from '@/components/dashboard/stat-card';
import { EmptyState } from '@/components/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { laboratoryOrderPriorityLabel, laboratoryOrderStatusLabel, laboratoryOrderStatusVariant } from '@/lib/laboratory-status';
import { type BreadcrumbItem } from '@/types';
import { type LaboratoryOrder, type LaboratoryOrderStatus } from '@/types/laboratory';
import { Head, Link } from '@inertiajs/react';
import { Beaker, ClipboardList, FlaskConical, ListOrdered, ListTree, Settings, TestTubes } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Laboratory', href: '/laboratory' }];

interface LaboratoryIndexProps {
    statusCounts: Partial<Record<LaboratoryOrderStatus, number>>;
    statuses: LaboratoryOrderStatus[];
    recentOrders: LaboratoryOrder[];
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * The Laboratory dashboard (Phase 4, Step 30, spec §33) — a landing page
 * with real status-distribution counts (never fabricated categories) and a
 * short recent-orders preview. The full filterable worklist lives on its
 * own page (laboratory.orders.index) — Dashboard/Orders/Tests are three
 * distinct nav items, not one collapsed page.
 */
export default function LaboratoryIndex({ statusCounts, statuses, recentOrders }: LaboratoryIndexProps) {
    const patientName = (order: LaboratoryOrder): string =>
        order.patient ? [order.patient.first_name, order.patient.middle_name, order.patient.last_name].filter(Boolean).join(' ') : '—';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Laboratory" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Laboratory</h1>
                        <p className="text-muted-foreground text-sm">Laboratory orders placed across all clinical encounters.</p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Can permission="laboratory-samples.view">
                            <Button asChild variant="outline">
                                <Link href={route('laboratory.collection.index')}>
                                    <TestTubes className="h-4 w-4" />
                                    Sample Collection
                                </Link>
                            </Button>
                        </Can>
                        <Can permission="laboratory-orders.view">
                            <Button asChild variant="outline">
                                <Link href={route('laboratory.orders.index')}>
                                    <ListOrdered className="h-4 w-4" />
                                    All Orders
                                </Link>
                            </Button>
                        </Can>
                        <Can permission="laboratory-processing.view">
                            <Button asChild variant="outline">
                                <Link href={route('laboratory.processing.index')}>
                                    <Beaker className="h-4 w-4" />
                                    Processing
                                </Link>
                            </Button>
                        </Can>
                        <Can permission="laboratory-results.view">
                            <Button asChild variant="outline">
                                <Link href={route('laboratory.results.index')}>
                                    <ClipboardList className="h-4 w-4" />
                                    Results
                                </Link>
                            </Button>
                        </Can>
                        <Can permission="laboratory-benches.view">
                            <Button asChild variant="outline">
                                <Link href={route('laboratory-benches.index')}>
                                    <Settings className="h-4 w-4" />
                                    Benches
                                </Link>
                            </Button>
                        </Can>
                        <Can permission="laboratory-tests.view">
                            <Button asChild variant="outline">
                                <Link href={route('laboratory-tests.index')}>
                                    <ListTree className="h-4 w-4" />
                                    Test Catalog
                                </Link>
                            </Button>
                        </Can>
                        <Can permission="laboratory-categories.view">
                            <Button asChild variant="outline">
                                <Link href={route('laboratory-categories.index')}>
                                    <Settings className="h-4 w-4" />
                                    Categories
                                </Link>
                            </Button>
                        </Can>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {statuses.map((status) => (
                        <StatCard key={status} label={laboratoryOrderStatusLabel[status]} value={statusCounts[status] ?? 0} icon={FlaskConical} />
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
                                    <TableHead>Tests</TableHead>
                                    <TableHead>Priority</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Ordered</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {recentOrders.map((order) => (
                                    <TableRow key={order.id}>
                                        <TableCell>
                                            <Link href={route('laboratory.orders.show', order.id)} className="font-medium hover:underline">
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
                                        <TableCell>{order.items.length}</TableCell>
                                        <TableCell>{laboratoryOrderPriorityLabel[order.priority]}</TableCell>
                                        <TableCell>
                                            <Badge variant={laboratoryOrderStatusVariant[order.status]}>
                                                {laboratoryOrderStatusLabel[order.status]}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>{formatDateTime(order.ordered_at)}</TableCell>
                                    </TableRow>
                                ))}

                                {recentOrders.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="p-0">
                                            <EmptyState
                                                icon={FlaskConical}
                                                title="No laboratory orders yet"
                                                description="Laboratory orders appear here once a doctor orders a test from a clinical encounter."
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
