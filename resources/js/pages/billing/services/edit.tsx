import { AddServicePriceForm } from '@/components/billing/add-service-price-form';
import { ServiceForm } from '@/components/billing/service-form';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/billing-status';
import { type BreadcrumbItem } from '@/types';
import { type Service, type ServiceCategory, type ServicePrice } from '@/types/service';
import { Head } from '@inertiajs/react';

interface BillingServiceEditProps {
    service: Service;
    categories: ServiceCategory[];
    currentPrices: ServicePrice[];
    hospitals: { id: number; name: string }[];
    practitioners: { id: number; practitioner_number: string; staff?: { first_name: string; last_name: string } }[];
    canCreatePrice: boolean;
}

export default function BillingServiceEdit({ service, categories, currentPrices, hospitals, practitioners, canCreatePrice }: BillingServiceEditProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Billing', href: '/billing' },
        { title: 'Services', href: '/billing/services' },
        { title: service.name, href: `/billing/services/${service.id}` },
        { title: 'Edit', href: `/billing/services/${service.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${service.name}`} />

            <div className="flex flex-col gap-6 p-4">
                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Edit Service</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ServiceForm service={service} categories={categories} />
                    </CardContent>
                </Card>

                {canCreatePrice && (
                    <Card className="max-w-2xl">
                        <CardHeader>
                            <CardTitle>Pricing</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            {currentPrices.length > 0 && (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Facility</TableHead>
                                            <TableHead>Practitioner</TableHead>
                                            <TableHead className="text-right">Amount</TableHead>
                                            <TableHead>Effective From</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {currentPrices.map((price) => (
                                            <TableRow key={price.id}>
                                                <TableCell>{price.hospital?.name ?? '—'}</TableCell>
                                                <TableCell>{price.practitioner ? price.practitioner.name : 'Facility-wide'}</TableCell>
                                                <TableCell className="text-right">{formatMoney(price.amount, price.currency)}</TableCell>
                                                <TableCell>{price.effective_from}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}

                            <div>
                                <p className="text-muted-foreground mb-4 text-sm">
                                    Saving a new price here automatically supersedes the current open-ended price for the same facility, effective the
                                    day before this one starts — a price is never edited in place.
                                </p>
                                <AddServicePriceForm serviceId={service.id} hospitals={hospitals} practitioners={practitioners} />
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
