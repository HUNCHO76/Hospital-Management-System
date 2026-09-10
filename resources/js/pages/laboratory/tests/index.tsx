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
import { laboratoryResultTypeLabel, laboratorySpecimenTypeLabel, laboratoryTestStatusVariant } from '@/lib/laboratory-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import {
    type LaboratoryResultType,
    type LaboratorySpecimenType,
    type LaboratoryTest,
    type LaboratoryTestCategoryOption,
    type LaboratoryTestFilters,
} from '@/types/laboratory';
import { Head, Link, router } from '@inertiajs/react';
import { FlaskConical, Plus } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Laboratory', href: '/laboratory' },
    { title: 'Test Catalog', href: '/laboratory/tests' },
];

interface LaboratoryTestIndexProps {
    tests: Paginated<LaboratoryTest>;
    filters: LaboratoryTestFilters;
    categories: LaboratoryTestCategoryOption[];
    specimenTypes: LaboratorySpecimenType[];
    resultTypes: LaboratoryResultType[];
}

export default function LaboratoryTestIndex({ tests, filters, categories, specimenTypes, resultTypes }: LaboratoryTestIndexProps) {
    const { can } = usePermissions();
    const [search, setSearch] = useState(filters.search ?? '');

    const applyFilters = (overrides: Partial<LaboratoryTestFilters>) => {
        router.get(
            route('laboratory-tests.index'),
            {
                search,
                category_id: filters.category_id ?? '',
                department_id: filters.department_id ?? '',
                specimen_type: filters.specimen_type ?? '',
                result_type: filters.result_type ?? '',
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

    const hasFilters = Boolean(filters.search || filters.category_id || filters.specimen_type || filters.result_type || filters.status);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Laboratory Test Catalog" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Laboratory Test Catalog</h1>
                        <p className="text-muted-foreground text-sm">The hospital's authoritative laboratory test definitions.</p>
                    </div>

                    <Can permission="laboratory-tests.create">
                        <Button asChild>
                            <Link href={route('laboratory-tests.create')}>
                                <Plus className="h-4 w-4" />
                                Add Test
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

                    <Select
                        value={filters.category_id || 'all'}
                        onValueChange={(value) => applyFilters({ category_id: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-[200px]">
                            <SelectValue placeholder="All categories" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All categories</SelectItem>
                            {categories.map((category) => (
                                <SelectItem key={category.id} value={String(category.id)}>
                                    {category.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.specimen_type || 'all'}
                        onValueChange={(value) => applyFilters({ specimen_type: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-[180px]">
                            <SelectValue placeholder="All specimens" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All specimens</SelectItem>
                            {specimenTypes.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {laboratorySpecimenTypeLabel[type]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.result_type || 'all'}
                        onValueChange={(value) => applyFilters({ result_type: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-[180px]">
                            <SelectValue placeholder="All result types" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All result types</SelectItem>
                            {resultTypes.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {laboratoryResultTypeLabel[type]}
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
                                router.get(route('laboratory-tests.index'));
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
                            <TableHead>Test Name</TableHead>
                            <TableHead>Category</TableHead>
                            <TableHead>Specimen</TableHead>
                            <TableHead>Result Type</TableHead>
                            <TableHead>TAT</TableHead>
                            <TableHead>Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {tests.data.map((test) => (
                            <TableRow key={test.id}>
                                <TableCell>
                                    <Link href={route('laboratory-tests.show', test.id)} className="font-medium hover:underline">
                                        {test.code}
                                    </Link>
                                </TableCell>
                                <TableCell>{test.name}</TableCell>
                                <TableCell>{test.category?.name ?? '—'}</TableCell>
                                <TableCell>{test.specimen_type ? laboratorySpecimenTypeLabel[test.specimen_type] : '—'}</TableCell>
                                <TableCell>{test.result_type ? laboratoryResultTypeLabel[test.result_type] : '—'}</TableCell>
                                <TableCell>{test.turnaround_time_minutes ? `${test.turnaround_time_minutes} min` : '—'}</TableCell>
                                <TableCell>
                                    <Badge variant={laboratoryTestStatusVariant[test.status]}>
                                        {test.status === 'active' ? 'Active' : 'Inactive'}
                                    </Badge>
                                </TableCell>
                            </TableRow>
                        ))}

                        {tests.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={7} className="p-0">
                                    <EmptyState
                                        icon={FlaskConical}
                                        title={hasFilters ? 'No tests match your search or filters' : 'No laboratory tests configured yet'}
                                        description={
                                            hasFilters
                                                ? 'Try a different search term or clear your filters.'
                                                : "Add the hospital's first laboratory test to the catalog."
                                        }
                                        action={
                                            !hasFilters && can('laboratory-tests.create') ? (
                                                <Button asChild size="sm">
                                                    <Link href={route('laboratory-tests.create')}>
                                                        <Plus className="h-4 w-4" />
                                                        Add Test
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

                <Pagination links={tests.links} />
            </div>
        </AppLayout>
    );
}
