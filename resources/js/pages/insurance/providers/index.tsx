import { Can } from '@/components/authorization/can';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type InsuranceProvider, type InsuranceProviderFilters } from '@/types/insurance-provider';
import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Insurance Providers', href: '/insurance/providers' }];

interface ProvidersIndexProps {
    providers: Paginated<InsuranceProvider>;
    filters: InsuranceProviderFilters;
    types: string[];
}

export default function ProvidersIndex({ providers, filters, types }: ProvidersIndexProps) {
    const { can } = usePermissions();
    const [search, setSearch] = useState(filters.search ?? '');

    const applyFilters = (overrides: Partial<InsuranceProviderFilters>) => {
        router.get(
            route('insurance.providers.index'),
            { search, type: filters.type ?? '', status: filters.status ?? '', ...overrides },
            { preserveState: true, replace: true },
        );
    };

    const submitSearch: FormEventHandler = (e) => {
        e.preventDefault();
        applyFilters({ search });
    };

    const hasFilters = Boolean(filters.search || filters.type || filters.status);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Insurance Providers" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-semibold">Insurance Providers</h1>

                    <Can permission="insurance-providers.create">
                        <Button asChild>
                            <Link href={route('insurance.providers.create')}>
                                <Plus className="h-4 w-4" />
                                Add Provider
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

                    <Select value={filters.type || 'all'} onValueChange={(value) => applyFilters({ type: value === 'all' ? '' : value })}>
                        <SelectTrigger className="w-[160px]">
                            <SelectValue placeholder="Type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All types</SelectItem>
                            {types.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {type}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select value={filters.status || 'all'} onValueChange={(value) => applyFilters({ status: value === 'all' ? '' : value })}>
                        <SelectTrigger className="w-[150px]">
                            <SelectValue placeholder="Status" />
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
                                router.get(route('insurance.providers.index'));
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
                                <th className="px-4 py-3 font-medium">Type</th>
                                <th className="px-4 py-3 font-medium">Schemes</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                                <th className="px-4 py-3 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {providers.data.map((provider) => (
                                <tr key={provider.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b last:border-0">
                                    <td className="px-4 py-3">
                                        <Link href={route('insurance.providers.show', provider.id)} className="font-medium hover:underline">
                                            {provider.name}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3">{provider.code}</td>
                                    <td className="px-4 py-3 capitalize">{provider.type}</td>
                                    <td className="px-4 py-3">{provider.schemes_count ?? 0}</td>
                                    <td className="px-4 py-3">
                                        <Badge variant={provider.status === 'active' ? 'default' : 'secondary'}>{provider.status}</Badge>
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-1">
                                            {can('insurance-providers.update') && (
                                                <Button variant="ghost" size="icon" asChild>
                                                    <Link href={route('insurance.providers.edit', provider.id)} aria-label="Edit provider">
                                                        <Pencil className="h-4 w-4" />
                                                    </Link>
                                                </Button>
                                            )}
                                            {can('insurance-providers.delete') && (
                                                <ConfirmDeleteDialog
                                                    title="Delete insurance provider"
                                                    description={`Are you sure you want to delete "${provider.name}"? This is only possible while it has no schemes or patient insurance records.`}
                                                    deleteUrl={route('insurance.providers.destroy', provider.id)}
                                                    trigger={
                                                        <Button variant="ghost" size="icon" aria-label="Delete provider">
                                                            <Trash2 className="h-4 w-4" />
                                                        </Button>
                                                    }
                                                />
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}

                            {providers.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="px-4 py-12 text-center">
                                        <p className="text-muted-foreground mb-3">
                                            {hasFilters ? 'No providers match your search or filters.' : 'No insurance providers found.'}
                                        </p>
                                        {!hasFilters && (
                                            <Can permission="insurance-providers.create">
                                                <Button asChild size="sm">
                                                    <Link href={route('insurance.providers.create')}>
                                                        <Plus className="h-4 w-4" />
                                                        Add Provider
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

                <Pagination links={providers.links} />
            </div>
        </AppLayout>
    );
}
