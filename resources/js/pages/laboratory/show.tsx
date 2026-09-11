import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import {
    laboratoryOrderPriorityLabel,
    laboratoryOrderStatusLabel,
    laboratoryOrderStatusVariant,
    laboratoryResultStatusLabel,
    laboratoryResultStatusVariant,
    orderResultsSummary,
} from '@/lib/laboratory-status';
import { type BreadcrumbItem } from '@/types';
import { type LaboratoryOrder } from '@/types/laboratory';
import { Head, Link } from '@inertiajs/react';

interface LaboratoryShowProps {
    order: LaboratoryOrder;
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * Laboratory order detail — the minimum context spec §25/39 asks for.
 * Each item's own `status` never advances past "result_pending" once
 * processing completes (Steps 33-35 track submit/verify/authorize/release
 * entirely on the linked LaboratoryResult) — so once a result exists, its
 * own status is shown instead, linking through to the result detail page.
 */
export default function LaboratoryShow({ order }: LaboratoryShowProps) {
    const { can } = usePermissions();
    const patientName = order.patient
        ? [order.patient.first_name, order.patient.middle_name, order.patient.last_name].filter(Boolean).join(' ')
        : '—';
    const resultsSummary = orderResultsSummary(order.items);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Laboratory', href: '/laboratory' },
        { title: order.order_number, href: `/laboratory/orders/${order.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Laboratory Order ${order.order_number}`} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{order.order_number}</h1>
                        <p className="text-muted-foreground text-sm">{patientName}</p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Badge variant={laboratoryOrderStatusVariant[order.status]}>{laboratoryOrderStatusLabel[order.status]}</Badge>
                        {resultsSummary === 'released' && <Badge variant={laboratoryResultStatusVariant.released}>Results Released</Badge>}
                        {resultsSummary === 'in_progress' && <Badge variant="outline">Results In Progress</Badge>}
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Order Details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Priority</dt>
                                    <dd>{laboratoryOrderPriorityLabel[order.priority]}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Ordering User</dt>
                                    <dd>{order.ordered_by?.name ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Ordered At</dt>
                                    <dd>{formatDateTime(order.ordered_at)}</dd>
                                </div>
                                {order.clinical_indication && (
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Clinical Indication</dt>
                                        <dd className="text-right">{order.clinical_indication}</dd>
                                    </div>
                                )}
                                {order.notes && (
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-muted-foreground">Notes</dt>
                                        <dd className="text-right">{order.notes}</dd>
                                    </div>
                                )}
                                {order.status === 'cancelled' && (
                                    <>
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-muted-foreground">Cancelled At</dt>
                                            <dd>{order.cancelled_at ? formatDateTime(order.cancelled_at) : '—'}</dd>
                                        </div>
                                        <div className="flex justify-between gap-4">
                                            <dt className="text-muted-foreground">Cancellation Reason</dt>
                                            <dd className="text-right">{order.cancellation_reason ?? '—'}</dd>
                                        </div>
                                    </>
                                )}
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Patient & Encounter</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Patient</dt>
                                    <dd>
                                        {order.patient ? (
                                            <Link href={route('patients.show', order.patient.id)} className="font-medium hover:underline">
                                                {patientName}
                                            </Link>
                                        ) : (
                                            '—'
                                        )}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Patient Number</dt>
                                    <dd>{order.patient?.patient_number ?? '—'}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-muted-foreground">Encounter</dt>
                                    <dd>
                                        {order.encounter ? (
                                            <Button asChild variant="link" size="sm" className="h-auto p-0">
                                                <Link href={route('clinical-encounters.show', order.encounter.id)}>
                                                    {order.encounter.encounter_number}
                                                </Link>
                                            </Button>
                                        ) : (
                                            '—'
                                        )}
                                    </dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Tests</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                            {order.items.map((item) => (
                                <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 py-2 first:pt-0 last:pb-0">
                                    <span className={item.status === 'cancelled' ? 'text-muted-foreground line-through' : ''}>
                                        {item.test_code} — {item.test_name}
                                    </span>

                                    {item.result ? (
                                        can('laboratory-results.view') ? (
                                            <Link href={route('laboratory.results.show', item.result.id)}>
                                                <Badge variant={laboratoryResultStatusVariant[item.result.status]} className="hover:underline">
                                                    {laboratoryResultStatusLabel[item.result.status]}
                                                </Badge>
                                            </Link>
                                        ) : (
                                            <Badge variant={laboratoryResultStatusVariant[item.result.status]}>
                                                {laboratoryResultStatusLabel[item.result.status]}
                                            </Badge>
                                        )
                                    ) : (
                                        <Badge variant={laboratoryOrderStatusVariant[item.status]}>{laboratoryOrderStatusLabel[item.status]}</Badge>
                                    )}
                                </li>
                            ))}

                            {order.items.length === 0 && <p className="text-muted-foreground text-sm">No tests on this order.</p>}
                        </ul>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
