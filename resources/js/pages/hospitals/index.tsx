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
import { type Hospital, type HospitalFilters } from '@/types/hospital';
import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Hospitals', href: '/hospitals' }];

interface HospitalsIndexProps {
    hospitals: Paginated<Hospital>;
    filters: HospitalFilters;
}

export default function HospitalsIndex({ hospitals, filters }: HospitalsIndexProps) {
    const { can } = usePermissions();
    const [search, setSearch] = useState(filters.search ?? '');

    const applyFilters = (overrides: Partial<HospitalFilters>) => {
        router.get(
            route('hospitals.index'),
            {
                search,
                status: filters.status ?? '',
                region: filters.region ?? '',
                district: filters.district ?? '',
                ...overrides,
            },
            { preserveState: true, replace: true },
        );
    };

    const submitSearch: FormEventHandler = (e) => {
        e.preventDefault();
        applyFilters({ search });
    };

    const hasFilters = Boolean(filters.search || filters.status || filters.region || filters.district);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Hospitals" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-semibold">Hospitals</h1>

                    <Can permission="hospitals.create">
                        <Button asChild>
                            <Link href={route('hospitals.create')}>
                                <Plus className="h-4 w-4" />
                                Add Hospital
                            </Link>
                        </Button>
                    </Can>
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    <form onSubmit={submitSearch} className="flex flex-1 min-w-[240px] gap-2">
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search by name, code, registration number, region or district"
                        />
                        <Button type="submit" variant="secondary">
                            Search
                        </Button>
                    </form>

                    <Select value={filters.status || 'all'} onValueChange={(value) => applyFilters({ status: value === 'all' ? '' : value })}>
                        <SelectTrigger className="w-[160px]">
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
                                router.get(route('hospitals.index'));
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
                                <th className="px-4 py-3 font-medium">Registration No.</th>
                                <th className="px-4 py-3 font-medium">Phone</th>
                                <th className="px-4 py-3 font-medium">Email</th>
                                <th className="px-4 py-3 font-medium">Location</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                                <th className="px-4 py-3 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {hospitals.data.map((hospital) => (
                                <tr key={hospital.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b last:border-0">
                                    <td className="px-4 py-3">
                                        <Link href={route('hospitals.show', hospital.id)} className="font-medium hover:underline">
                                            {hospital.name}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3">{hospital.code}</td>
                                    <td className="px-4 py-3">{hospital.registration_number ?? '—'}</td>
                                    <td className="px-4 py-3">{hospital.phone ?? '—'}</td>
                                    <td className="px-4 py-3">{hospital.email ?? '—'}</td>
                                    <td className="px-4 py-3">{[hospital.district, hospital.region].filter(Boolean).join(', ') || '—'}</td>
                                    <td className="px-4 py-3">
                                        <Badge variant={hospital.status === 'active' ? 'default' : 'secondary'}>{hospital.status}</Badge>
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-1">
                                            {can('hospitals.update') && (
                                                <Button variant="ghost" size="icon" asChild>
                                                    <Link href={route('hospitals.edit', hospital.id)} aria-label={`Edit ${hospital.name}`}>
                                                        <Pencil className="h-4 w-4" />
                                                    </Link>
                                                </Button>
                                            )}
                                            {can('hospitals.delete') && (
                                                <ConfirmDeleteDialog
                                                    title="Delete hospital"
                                                    description={`Are you sure you want to delete "${hospital.name}"? Departments and historical records linked to it will not be removed, but this hospital will no longer be selectable.`}
                                                    deleteUrl={route('hospitals.destroy', hospital.id)}
                                                    trigger={
                                                        <Button variant="ghost" size="icon" aria-label={`Delete ${hospital.name}`}>
                                                            <Trash2 className="h-4 w-4" />
                                                        </Button>
                                                    }
                                                />
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}

                            {hospitals.data.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="px-4 py-12 text-center">
                                        <p className="text-muted-foreground mb-3">
                                            {hasFilters ? 'No hospitals match your search or filters.' : 'No hospitals found.'}
                                        </p>
                                        <Can permission="hospitals.create">
                                            {!hasFilters && (
                                                <Button asChild size="sm">
                                                    <Link href={route('hospitals.create')}>
                                                        <Plus className="h-4 w-4" />
                                                        Add Hospital
                                                    </Link>
                                                </Button>
                                            )}
                                        </Can>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination links={hospitals.links} />
            </div>
        </AppLayout>
    );
}
