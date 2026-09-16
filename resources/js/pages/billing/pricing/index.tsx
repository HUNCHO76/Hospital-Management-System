import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/billing-status';
import { servicePriceStatusLabel, servicePriceStatusVariant } from '@/lib/service-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type ServicePrice, type ServicePriceFilters } from '@/types/service';
import { Head, Link, router } from '@inertiajs/react';
import { Receipt } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing', href: '/billing' },
    { title: 'Pricing', href: '/billing/pricing' },
];

interface BillingPricingIndexProps {
    prices: Paginated<ServicePrice>;
    filters: ServicePriceFilters;
}

/**
 * The pricing management list (Phase 7, Step 53, spec §34) — every service's
 * price across every facility and effective period, newest first.
 */
export default function BillingPricingIndex({ prices, filters }: BillingPricingIndexProps) {
    const { can } = usePermissions();

    const applyFilters = (overrides: Partial<ServicePriceFilters>) => {
        const params: Record<string, string> = { ...filters, ...overrides };
        router.get(route('billing-pricing.index'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(filters.search || filters.status);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Service Pricing" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Pricing</h1>
                        <p className="text-muted-foreground text-sm">Every service price, across every facility and effective period.</p>
                    </div>
                    {can('billing-pricing.create') && (
                        <Button asChild>
                            <Link href={route('billing-pricing.create')}>New Price</Link>
                        </Button>
                    )}
                </div>

                <Input
                    value={filters.search || ''}
                    onChange={(e) => applyFilters({ search: e.target.value })}
                    placeholder="Search by service code or name"
                    aria-label="Search pricing"
                    className="max-w-sm"
                />

                {hasFilters && (
                    <Button variant="ghost" size="sm" className="w-fit" onClick={() => router.get(route('billing-pricing.index'))}>
                        Clear filters
                    </Button>
                )}

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Service</TableHead>
                            <TableHead>Facility</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                            <TableHead>Effective From</TableHead>
                            <TableHead>Effective To</TableHead>
                            <TableHead>Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {prices.data.map((price) => (
                            <TableRow key={price.id}>
                                <TableCell>
                                    {price.service ? (
                                        <Link href={route('billing-services.show', price.service.id)} className="hover:underline">
                                            {price.service.code} — {price.service.name}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </TableCell>
                                <TableCell>{price.hospital?.name ?? '—'}</TableCell>
                                <TableCell className="text-right">{formatMoney(price.amount, price.currency)}</TableCell>
                                <TableCell>{price.effective_from}</TableCell>
                                <TableCell>{price.effective_to ?? 'Ongoing'}</TableCell>
                                <TableCell>
                                    <div className="flex items-center gap-2">
                                        <Badge variant={servicePriceStatusVariant[price.status]}>{servicePriceStatusLabel[price.status]}</Badge>
                                        {price.is_current && <Badge variant="secondary">Current</Badge>}
                                    </div>
                                </TableCell>
                            </TableRow>
                        ))}

                        {prices.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={6} className="p-0">
                                    <EmptyState
                                        icon={Receipt}
                                        title={hasFilters ? 'No prices match your search' : 'No prices configured yet'}
                                        description={
                                            hasFilters ? 'Try a different search term or clear your filters.' : 'Prices set here apply immediately.'
                                        }
                                    />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>

                <Pagination links={prices.links} />
            </div>
        </AppLayout>
    );
}
