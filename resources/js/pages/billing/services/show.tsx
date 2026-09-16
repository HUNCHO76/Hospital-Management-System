import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/billing-status';
import {
    serviceCategoryLabel,
    servicePriceStatusLabel,
    servicePriceStatusVariant,
    serviceStatusLabel,
    serviceStatusVariant,
} from '@/lib/service-status';
import { type BreadcrumbItem } from '@/types';
import { type Service, type ServicePrice } from '@/types/service';
import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';

interface BillingServiceShowProps {
    service: Service;
    prices: ServicePrice[];
    canCreatePrice: boolean;
}

/**
 * A single service's detail page (Phase 7, Step 53) — its own definition
 * plus its complete pricing history across every facility (spec §17/§36),
 * newest first. No "current price" summary is fabricated up top: the
 * `is_current` badge on each row already shows which one applies today.
 */
export default function BillingServiceShow({ service, prices, canCreatePrice }: BillingServiceShowProps) {
    const { can } = usePermissions();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Billing', href: '/billing' },
        { title: 'Services', href: '/billing/services' },
        { title: service.name, href: `/billing/services/${service.id}` },
    ];

    const toggleStatus = () => {
        const action = service.status === 'active' ? 'deactivate' : 'activate';
        router.post(route(`billing-services.${action}`, service.id), {}, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={service.name} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{service.name}</h1>
                        <p className="text-muted-foreground text-sm">
                            {service.code} · {serviceCategoryLabel[service.category]} · {service.is_billable ? 'Billable' : 'Not billable'}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Badge variant={serviceStatusVariant[service.status]}>{serviceStatusLabel[service.status]}</Badge>
                        {can('billing-services.update') && (
                            <Button asChild variant="outline">
                                <Link href={route('billing-services.edit', service.id)}>
                                    <Pencil className="h-4 w-4" />
                                    Edit
                                </Link>
                            </Button>
                        )}
                        {can(service.status === 'active' ? 'billing-services.deactivate' : 'billing-services.activate') && (
                            <Button variant="outline" onClick={toggleStatus}>
                                {service.status === 'active' ? 'Deactivate' : 'Activate'}
                            </Button>
                        )}
                    </div>
                </div>

                {service.description && (
                    <Card>
                        <CardContent className="p-5 text-sm">{service.description}</CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="text-base">Pricing History</CardTitle>
                        {canCreatePrice && (
                            <Button asChild size="sm">
                                <Link href={route('billing-pricing.create', { service_id: service.id })}>
                                    <Plus className="h-4 w-4" />
                                    Add Price
                                </Link>
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Facility</TableHead>
                                    <TableHead className="text-right">Amount</TableHead>
                                    <TableHead>Effective From</TableHead>
                                    <TableHead>Effective To</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {prices.map((price) => (
                                    <TableRow key={price.id}>
                                        <TableCell>{price.hospital?.name ?? '—'}</TableCell>
                                        <TableCell className="text-right">{formatMoney(price.amount, price.currency)}</TableCell>
                                        <TableCell>{price.effective_from}</TableCell>
                                        <TableCell>{price.effective_to ?? 'Ongoing'}</TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <Badge variant={servicePriceStatusVariant[price.status]}>
                                                    {servicePriceStatusLabel[price.status]}
                                                </Badge>
                                                {price.is_current && <Badge variant="secondary">Current</Badge>}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}

                                {prices.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={5} className="text-muted-foreground py-6 text-center text-sm">
                                            No price has been configured for this service yet.
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
