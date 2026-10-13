import { AddServicePriceForm } from '@/components/billing/add-service-price-form';
import { EditServicePriceForm } from '@/components/billing/edit-service-price-form';
import { ServiceForm } from '@/components/billing/service-form';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/billing-status';
import { type BreadcrumbItem } from '@/types';
import { type Service, type ServiceCategory, type ServicePrice } from '@/types/service';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface BillingServiceEditProps {
    service: Service;
    categories: ServiceCategory[];
    currentPrices: ServicePrice[];
    hospitals: { id: number; name: string }[];
    practitioners: { id: number; practitioner_number: string; staff?: { first_name: string; last_name: string } }[];
    canCreatePrice: boolean;
    canUpdatePrice: boolean;
    canCancelPrice: boolean;
}

export default function BillingServiceEdit({
    service,
    categories,
    currentPrices,
    hospitals,
    practitioners,
    canCreatePrice,
    canUpdatePrice,
    canCancelPrice,
}: BillingServiceEditProps) {
    const [editingId, setEditingId] = useState<number | null>(null);
    const canManagePrices = canCreatePrice || canUpdatePrice || canCancelPrice;

    const cancelPrice = (price: ServicePrice) => {
        const reason = window.prompt('Reason for cancelling this price?');
        if (reason && reason.trim() !== '') {
            router.post(route('billing-pricing.cancel', price.id), { reason }, { preserveScroll: true });
        }
    };

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

                {canManagePrices && (
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
                                            {(canUpdatePrice || canCancelPrice) && <TableHead className="text-right">Actions</TableHead>}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {currentPrices.map((price) => (
                                            <TableRow key={price.id}>
                                                <TableCell>{price.hospital?.name ?? '—'}</TableCell>
                                                <TableCell>{price.practitioner ? price.practitioner.name : 'Facility-wide'}</TableCell>
                                                <TableCell className="text-right">{formatMoney(price.amount, price.currency)}</TableCell>
                                                <TableCell>{price.effective_from}</TableCell>
                                                {(canUpdatePrice || canCancelPrice) && (
                                                    <TableCell className="space-x-2 text-right">
                                                        {canUpdatePrice && price.effective_to === null && (
                                                            <Button type="button" size="sm" variant="outline" onClick={() => setEditingId(price.id)}>
                                                                Edit
                                                            </Button>
                                                        )}
                                                        {canCancelPrice && (
                                                            <Button type="button" size="sm" variant="destructive" onClick={() => cancelPrice(price)}>
                                                                Cancel
                                                            </Button>
                                                        )}
                                                    </TableCell>
                                                )}
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}

                            {editingId !== null && (
                                <div className="rounded-md border p-4">
                                    <EditServicePriceForm
                                        key={editingId}
                                        priceId={editingId}
                                        amount={String(currentPrices.find((p) => p.id === editingId)?.amount ?? '')}
                                        onDone={() => setEditingId(null)}
                                    />
                                </div>
                            )}

                            {canCreatePrice && (
                                <div>
                                    <p className="text-muted-foreground mb-4 text-sm">
                                        Use Edit above to correct the current price. To schedule a different price from a later date, add a new one
                                        here: it automatically supersedes the current open-ended price, effective the day before this one starts.
                                    </p>
                                    <AddServicePriceForm serviceId={service.id} hospitals={hospitals} practitioners={practitioners} />
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
