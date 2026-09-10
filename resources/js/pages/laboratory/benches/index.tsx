import { Can } from '@/components/authorization/can';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { laboratoryTestStatusVariant } from '@/lib/laboratory-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type LaboratoryBench, type LaboratoryCategoryFilters } from '@/types/laboratory';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Pencil, Plus, XCircle } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Laboratory', href: '/laboratory' },
    { title: 'Benches', href: '/laboratory/benches' },
];

interface LaboratoryBenchIndexProps {
    benches: Paginated<LaboratoryBench>;
    filters: LaboratoryCategoryFilters;
}

/**
 * The laboratory work-area/bench catalog (Phase 4, Step 32, spec §11) —
 * mirrors laboratory/categories/index.tsx exactly, the same small
 * admin-managed catalog pattern.
 */
export default function LaboratoryBenchIndex({ benches, filters }: LaboratoryBenchIndexProps) {
    const { can } = usePermissions();
    const [search, setSearch] = useState(filters.search ?? '');

    const applyFilters = (overrides: Partial<LaboratoryCategoryFilters>) => {
        router.get(route('laboratory-benches.index'), { search, status: filters.status ?? '', ...overrides }, { preserveState: true, replace: true });
    };

    const submitSearch: FormEventHandler = (e) => {
        e.preventDefault();
        applyFilters({ search });
    };

    const toggleStatus = (bench: LaboratoryBench) => {
        const action = bench.status === 'active' ? 'deactivate' : 'activate';
        router.post(route(`laboratory-benches.${action}`, bench.id), {}, { preserveScroll: true });
    };

    const hasFilters = Boolean(filters.search || filters.status);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Laboratory Benches" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-semibold">Laboratory Benches</h1>

                    <Can permission="laboratory-benches.manage">
                        <Button asChild>
                            <Link href={route('laboratory-benches.create')}>
                                <Plus className="h-4 w-4" />
                                Add Bench
                            </Link>
                        </Button>
                    </Can>
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    <form onSubmit={submitSearch} className="flex min-w-[240px] flex-1 gap-2">
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search by name or code" />
                        <Button type="submit" variant="secondary">
                            Search
                        </Button>
                    </form>

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
                                router.get(route('laboratory-benches.index'));
                            }}
                        >
                            Clear filters
                        </Button>
                    )}
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                    <table className="w-full text-left text-sm">
                        <thead className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b">
                            <tr>
                                <th className="px-4 py-3 font-medium">Name</th>
                                <th className="px-4 py-3 font-medium">Code</th>
                                <th className="px-4 py-3 font-medium">Description</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                                <th className="px-4 py-3 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {benches.data.map((bench) => (
                                <tr key={bench.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b last:border-0">
                                    <td className="px-4 py-3 font-medium">{bench.name}</td>
                                    <td className="px-4 py-3">{bench.code}</td>
                                    <td className="max-w-xs truncate px-4 py-3">{bench.description ?? '—'}</td>
                                    <td className="px-4 py-3">
                                        <Badge variant={laboratoryTestStatusVariant[bench.status]}>
                                            {bench.status === 'active' ? 'Active' : 'Inactive'}
                                        </Badge>
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-1">
                                            {can('laboratory-benches.manage') && (
                                                <Button variant="ghost" size="icon" asChild>
                                                    <Link href={route('laboratory-benches.edit', bench.id)} aria-label={`Edit ${bench.name}`}>
                                                        <Pencil className="h-4 w-4" />
                                                    </Link>
                                                </Button>
                                            )}
                                            {can('laboratory-benches.manage') &&
                                                (bench.status === 'active' ? (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Deactivate ${bench.name}`}
                                                        onClick={() => toggleStatus(bench)}
                                                    >
                                                        <XCircle className="h-4 w-4" />
                                                    </Button>
                                                ) : (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Activate ${bench.name}`}
                                                        onClick={() => toggleStatus(bench)}
                                                    >
                                                        <CheckCircle2 className="h-4 w-4" />
                                                    </Button>
                                                ))}
                                        </div>
                                    </td>
                                </tr>
                            ))}

                            {benches.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="px-4 py-12 text-center">
                                        <p className="text-muted-foreground mb-3">
                                            {hasFilters ? 'No benches match your search or filters.' : 'No laboratory benches found.'}
                                        </p>
                                        {!hasFilters && (
                                            <Can permission="laboratory-benches.manage">
                                                <Button asChild size="sm">
                                                    <Link href={route('laboratory-benches.create')}>
                                                        <Plus className="h-4 w-4" />
                                                        Add Bench
                                                    </Link>
                                                </Button>
                                            </Can>
                                        )}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination links={benches.links} />
            </div>
        </AppLayout>
    );
}
