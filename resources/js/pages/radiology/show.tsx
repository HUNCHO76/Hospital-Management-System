import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import {
    radiologyAppointmentStatusLabel,
    radiologyAppointmentStatusVariant,
    radiologyBodySiteLabel,
    radiologyModalityLabel,
    radiologyOrderPriorityLabel,
    radiologyOrderStatusLabel,
    radiologyOrderStatusVariant,
} from '@/lib/radiology-status';
import { type BreadcrumbItem } from '@/types';
import { type RadiologyOrder } from '@/types/radiology';
import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, CircleDot, XCircle } from 'lucide-react';

interface RadiologyShowProps {
    order: RadiologyOrder;
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/**
 * Radiology order detail — the minimum context this step implements (Phase
 * 5, Step 36, Part 32). Examination/findings/report fields are
 * deliberately absent — no fake result data is fabricated here; scheduling/
 * examination/reporting/release are Steps 39-44's job, mirroring how
 * LaboratoryShow looked before Steps 33-35 added result-status display.
 */
export default function RadiologyShow({ order }: RadiologyShowProps) {
    const patientName = order.patient
        ? [order.patient.first_name, order.patient.middle_name, order.patient.last_name].filter(Boolean).join(' ')
        : '—';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Radiology', href: '/radiology' },
        { title: order.order_number, href: `/radiology/orders/${order.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Radiology Order ${order.order_number}`} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{order.order_number}</h1>
                        <p className="text-muted-foreground text-sm">{patientName}</p>
                    </div>
                    <Badge variant={radiologyOrderStatusVariant[order.status]}>{radiologyOrderStatusLabel[order.status]}</Badge>
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
                                    <dd>{radiologyOrderPriorityLabel[order.priority]}</dd>
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
                        <CardTitle className="text-base">Procedures</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul className="divide-sidebar-border/70 dark:divide-sidebar-border divide-y">
                            {order.items.map((item) => (
                                <li key={item.id} className="flex flex-wrap items-start justify-between gap-2 py-2 first:pt-0 last:pb-0">
                                    <div className={item.status === 'cancelled' ? 'text-muted-foreground line-through' : ''}>
                                        <p>
                                            {item.procedure_code} — {item.procedure_name}
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            {item.modality_snapshot && radiologyModalityLabel[item.modality_snapshot]}
                                            {item.modality_snapshot && item.body_site_snapshot?.length ? ' · ' : ''}
                                            {item.body_site_snapshot?.map((site) => radiologyBodySiteLabel[site]).join(', ')}
                                        </p>
                                    </div>

                                    <div className="flex items-center gap-2">
                                        {item.status === 'cancelled' ? (
                                            <Badge variant={radiologyOrderStatusVariant[item.status]}>{radiologyOrderStatusLabel[item.status]}</Badge>
                                        ) : item.appointment ? (
                                            <Badge variant={radiologyAppointmentStatusVariant[item.appointment.status]}>
                                                {radiologyAppointmentStatusLabel[item.appointment.status]}
                                            </Badge>
                                        ) : (
                                            <Badge variant="outline">Not Scheduled</Badge>
                                        )}
                                    </div>
                                </li>
                            ))}

                            {order.items.length === 0 && <p className="text-muted-foreground text-sm">No procedures on this order.</p>}
                        </ul>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Timeline</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ol className="space-y-3 text-sm">
                            <li className="flex items-center gap-2">
                                <CircleDot className="text-muted-foreground h-4 w-4" />
                                <span>Created — {formatDateTime(order.created_at)}</span>
                            </li>
                            <li className="flex items-center gap-2">
                                <CheckCircle2 className="h-4 w-4 text-green-600" />
                                <span>Ordered — {formatDateTime(order.ordered_at)}</span>
                            </li>
                            {order.status === 'cancelled' && order.cancelled_at && (
                                <li className="flex items-center gap-2">
                                    <XCircle className="text-destructive h-4 w-4" />
                                    <span>Cancelled — {formatDateTime(order.cancelled_at)}</span>
                                </li>
                            )}
                        </ol>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
