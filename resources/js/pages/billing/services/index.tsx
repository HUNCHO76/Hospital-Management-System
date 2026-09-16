import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { serviceCategoryLabel, serviceStatusLabel, serviceStatusVariant } from '@/lib/service-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type Service, type ServiceCategory, type ServiceFilters } from '@/types/service';
import { Head, Link, router } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing', href: '/billing' },
    { title: 'Services', href: '/billing/services' },
];

interface BillingServicesIndexProps {
    services: Paginated<Service>;
    filters: ServiceFilters;
    categories: ServiceCategory[];
}

/**
 * The central billing service catalog list (Phase 7, Step 53, spec §33) —
 * every filter is applied server-side by
 * ServiceCatalogSearchService::servicesQuery(), mirroring
 * MedicationsIndex/StockItemsIndex's own shape exactly.
 */
export default function BillingServicesIndex({ services, filters, categories }: BillingServicesIndexProps) {
    const { can } = usePermissions();

    const applyFilters = (overrides: Partial<ServiceFilters>) => {
        const params: Record<string, string> = { ...filters, ...overrides };
        router.get(route('billing-services.index'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(filters.search || filters.category || filters.status || filters.is_billable);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Service Catalog" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Service Catalog</h1>
                        <p className="text-muted-foreground text-sm">Every billable service this hospital can charge for.</p>
                    </div>
                    {can('billing-services.create') && (
                        <Button asChild>
                            <Link href={route('billing-services.create')}>New Service</Link>
                        </Button>
                    )}
                </div>

                <div className="flex flex-col gap-3">
                    <Input
                        value={filters.search || ''}
                        onChange={(e) => applyFilters({ search: e.target.value })}
                        placeholder="Search by code or name"
                        aria-label="Search services"
                        className="max-w-sm"
                    />

                    <div className="flex flex-wrap items-center gap-2">
                        <Select value={filters.category || 'all'} onValueChange={(value) => applyFilters({ category: value === 'all' ? '' : value })}>
                            <SelectTrigger className="w-[180px]">
                                <SelectValue placeholder="All categories" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All categories</SelectItem>
                                {categories.map((category) => (
                                    <SelectItem key={category} value={category}>
                                        {serviceCategoryLabel[category]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select value={filters.status || 'all'} onValueChange={(value) => applyFilters({ status: value === 'all' ? '' : value })}>
                            <SelectTrigger className="w-[160px]">
                                <SelectValue placeholder="All statuses" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All statuses</SelectItem>
                                <SelectItem value="active">Active</SelectItem>
                                <SelectItem value="inactive">Inactive</SelectItem>
                            </SelectContent>
                        </Select>

                        {hasFilters && (
                            <Button variant="ghost" size="sm" onClick={() => router.get(route('billing-services.index'))}>
                                Clear filters
                            </Button>
                        )}
                    </div>
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Code</TableHead>
                            <TableHead>Name</TableHead>
                            <TableHead>Category</TableHead>
                            <TableHead>Billable</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead className="text-right">Action</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {services.data.map((service) => (
                            <TableRow key={service.id}>
                                <TableCell>{service.code}</TableCell>
                                <TableCell>{service.name}</TableCell>
                                <TableCell>{serviceCategoryLabel[service.category]}</TableCell>
                                <TableCell>{service.is_billable ? 'Yes' : 'No'}</TableCell>
                                <TableCell>
                                    <Badge variant={serviceStatusVariant[service.status]}>{serviceStatusLabel[service.status]}</Badge>
                                </TableCell>
                                <TableCell className="text-right">
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={route('billing-services.show', service.id)}>View</Link>
                                    </Button>
                                </TableCell>
                            </TableRow>
                        ))}

                        {services.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={6} className="p-0">
                                    <EmptyState
                                        icon={ClipboardList}
                                        title={hasFilters ? 'No services match your search or filters' : 'No services yet'}
                                        description={
                                            hasFilters
                                                ? 'Try a different search term or clear your filters.'
                                                : 'Services created here become chargeable across the hospital.'
                                        }
                                    />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>

                <Pagination links={services.links} />
            </div>
        </AppLayout>
    );
}
