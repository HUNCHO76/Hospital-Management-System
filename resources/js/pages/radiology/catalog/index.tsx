import { Can } from '@/components/authorization/can';
import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { radiologyModalityLabel, radiologyProcedureStatusVariant } from '@/lib/radiology-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type RadiologyModality, type RadiologyProcedure, type RadiologyProcedureFilters } from '@/types/radiology';
import { Head, Link, router } from '@inertiajs/react';
import { Plus, Scan } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Radiology', href: '/radiology' },
    { title: 'Catalog', href: '/radiology/catalog' },
];

interface RadiologyCatalogIndexProps {
    procedures: Paginated<RadiologyProcedure>;
    filters: RadiologyProcedureFilters;
    modalities: RadiologyModality[];
}

export default function RadiologyCatalogIndex({ procedures, filters, modalities }: RadiologyCatalogIndexProps) {
    const { can } = usePermissions();
    const [search, setSearch] = useState(filters.search ?? '');

    const applyFilters = (overrides: Partial<RadiologyProcedureFilters>) => {
        router.get(
            route('radiology-catalog.index'),
            {
                search,
                modality: filters.modality ?? '',
                department_id: filters.department_id ?? '',
                status: filters.status ?? '',
                ...overrides,
            },
            { preserveState: true, replace: true },
        );
    };

    const submitSearch: FormEventHandler = (e) => {
        e.preventDefault();
        applyFilters({ search });
    };

    const hasFilters = Boolean(filters.search || filters.modality || filters.status);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Radiology Catalog" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Radiology Catalog</h1>
                        <p className="text-muted-foreground text-sm">The hospital's authoritative radiology procedure definitions.</p>
                    </div>

                    <Can permission="radiology-procedures.create">
                        <Button asChild>
                            <Link href={route('radiology-catalog.create')}>
                                <Plus className="h-4 w-4" />
                                Add Procedure
                            </Link>
                        </Button>
                    </Can>
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    <form onSubmit={submitSearch} className="flex min-w-[240px] flex-1 gap-2">
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search by code or name" />
                        <Button type="submit" variant="secondary">
                            Search
                        </Button>
                    </form>

                    <Select value={filters.modality || 'all'} onValueChange={(value) => applyFilters({ modality: value === 'all' ? '' : value })}>
                        <SelectTrigger className="w-[180px]">
                            <SelectValue placeholder="All categories" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All categories</SelectItem>
                            {modalities.map((modality) => (
                                <SelectItem key={modality} value={modality}>
                                    {radiologyModalityLabel[modality]}
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
                        <Button
                            variant="ghost"
                            onClick={() => {
                                setSearch('');
                                router.get(route('radiology-catalog.index'));
                            }}
                        >
                            Clear filters
                        </Button>
                    )}
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Code</TableHead>
                            <TableHead>Procedure</TableHead>
                            <TableHead>Modality</TableHead>
                            <TableHead>TAT</TableHead>
                            <TableHead>Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {procedures.data.map((procedure) => (
                            <TableRow key={procedure.id}>
                                <TableCell>
                                    <Link href={route('radiology-catalog.show', procedure.id)} className="font-medium hover:underline">
                                        {procedure.code}
                                    </Link>
                                </TableCell>
                                <TableCell>{procedure.name}</TableCell>
                                <TableCell>{procedure.modality ? radiologyModalityLabel[procedure.modality] : '—'}</TableCell>
                                <TableCell>{procedure.turnaround_time_minutes ? `${procedure.turnaround_time_minutes} min` : '—'}</TableCell>
                                <TableCell>
                                    <Badge variant={radiologyProcedureStatusVariant[procedure.status]}>
                                        {procedure.status === 'active' ? 'Active' : 'Inactive'}
                                    </Badge>
                                </TableCell>
                            </TableRow>
                        ))}

                        {procedures.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={5} className="p-0">
                                    <EmptyState
                                        icon={Scan}
                                        title={hasFilters ? 'No procedures match your search or filters' : 'No radiology procedures configured yet'}
                                        description={
                                            hasFilters
                                                ? 'Try a different search term or clear your filters.'
                                                : "Add the hospital's first radiology procedure to the catalog."
                                        }
                                        action={
                                            !hasFilters && can('radiology-procedures.create') ? (
                                                <Button asChild size="sm">
                                                    <Link href={route('radiology-catalog.create')}>
                                                        <Plus className="h-4 w-4" />
                                                        Add Procedure
                                                    </Link>
                                                </Button>
                                            ) : undefined
                                        }
                                    />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>

                <Pagination links={procedures.links} />
            </div>
        </AppLayout>
    );
}
