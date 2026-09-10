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
import { type InsuranceProviderSummary } from '@/types/insurance-provider';
import { type InsuranceScheme, type InsuranceSchemeFilters } from '@/types/insurance-scheme';
import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Insurance Schemes', href: '/insurance/schemes' }];

interface SchemesIndexProps {
    schemes: Paginated<InsuranceScheme>;
    filters: InsuranceSchemeFilters;
    providers: InsuranceProviderSummary[];
}

export default function SchemesIndex({ schemes, filters, providers }: SchemesIndexProps) {
    const { can } = usePermissions();
    const [search, setSearch] = useState(filters.search ?? '');

    const applyFilters = (overrides: Partial<InsuranceSchemeFilters>) => {
        router.get(
            route('insurance.schemes.index'),
            { search, insurance_provider_id: filters.insurance_provider_id ?? '', status: filters.status ?? '', ...overrides },
            { preserveState: true, replace: true },
        );
    };

    const submitSearch: FormEventHandler = (e) => {
        e.preventDefault();
        applyFilters({ search });
    };

    const hasFilters = Boolean(filters.search || filters.insurance_provider_id || filters.status);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Insurance Schemes" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-semibold">Insurance Schemes</h1>

                    <Can permission="insurance-schemes.create">
                        <Button asChild>
                            <Link href={route('insurance.schemes.create')}>
                                <Plus className="h-4 w-4" />
                                Add Scheme
                            </Link>
                        </Button>
                    </Can>
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    <form onSubmit={submitSearch} className="flex min-w-[240px] flex-1 gap-2">
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search by name, code or provider" />
                        <Button type="submit" variant="secondary">
                            Search
                        </Button>
                    </form>

                    <Select
                        value={filters.insurance_provider_id || 'all'}
                        onValueChange={(value) => applyFilters({ insurance_provider_id: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-[220px]">
                            <SelectValue placeholder="Provider" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All providers</SelectItem>
                            {providers.map((provider) => (
                                <SelectItem key={provider.id} value={String(provider.id)}>
                                    {provider.name}
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
                                router.get(route('insurance.schemes.index'));
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
                                <th className="px-4 py-3 font-medium">Scheme Name</th>
                                <th className="px-4 py-3 font-medium">Code</th>
                                <th className="px-4 py-3 font-medium">Provider</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                                <th className="px-4 py-3 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {schemes.data.map((scheme) => (
                                <tr key={scheme.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b last:border-0">
                                    <td className="px-4 py-3">
                                        <Link href={route('insurance.schemes.show', scheme.id)} className="font-medium hover:underline">
                                            {scheme.name}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3">{scheme.code}</td>
                                    <td className="px-4 py-3">{scheme.insurance_provider?.name ?? '—'}</td>
                                    <td className="px-4 py-3">
                                        <Badge variant={scheme.status === 'active' ? 'default' : 'secondary'}>{scheme.status}</Badge>
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-1">
                                            {can('insurance-schemes.update') && (
                                                <Button variant="ghost" size="icon" asChild>
                                                    <Link href={route('insurance.schemes.edit', scheme.id)} aria-label="Edit scheme">
                                                        <Pencil className="h-4 w-4" />
                                                    </Link>
                                                </Button>
                                            )}
                                            {can('insurance-schemes.delete') && (
                                                <ConfirmDeleteDialog
                                                    title="Delete insurance scheme"
                                                    description={`Are you sure you want to delete "${scheme.name}"? This is only possible while no patients hold insurance under it.`}
                                                    deleteUrl={route('insurance.schemes.destroy', scheme.id)}
                                                    trigger={
                                                        <Button variant="ghost" size="icon" aria-label="Delete scheme">
                                                            <Trash2 className="h-4 w-4" />
                                                        </Button>
                                                    }
                                                />
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}

                            {schemes.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="px-4 py-12 text-center">
                                        <p className="text-muted-foreground mb-3">
                                            {hasFilters ? 'No schemes match your search or filters.' : 'No insurance schemes found.'}
                                        </p>
                                        {!hasFilters && (
                                            <Can permission="insurance-schemes.create">
                                                <Button asChild size="sm">
                                                    <Link href={route('insurance.schemes.create')}>
                                                        <Plus className="h-4 w-4" />
                                                        Add Scheme
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

                <Pagination links={schemes.links} />
            </div>
        </AppLayout>
    );
}
