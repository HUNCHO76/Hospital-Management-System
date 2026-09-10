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
import { type Department, type DepartmentFilters, type DepartmentHospital } from '@/types/department';
import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Departments', href: '/departments' }];

interface DepartmentsIndexProps {
    departments: Paginated<Department>;
    filters: DepartmentFilters;
    hospitals: DepartmentHospital[];
}

export default function DepartmentsIndex({ departments, filters, hospitals }: DepartmentsIndexProps) {
    const { can } = usePermissions();
    const [search, setSearch] = useState(filters.search ?? '');

    const applyFilters = (overrides: Partial<DepartmentFilters>) => {
        router.get(
            route('departments.index'),
            {
                search,
                hospital_id: filters.hospital_id ?? '',
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

    const hasFilters = Boolean(filters.search || filters.hospital_id || filters.status);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Departments" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-semibold">Departments</h1>

                    <Can permission="departments.create">
                        <Button asChild>
                            <Link href={route('departments.create')}>
                                <Plus className="h-4 w-4" />
                                Add Department
                            </Link>
                        </Button>
                    </Can>
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    <form onSubmit={submitSearch} className="flex min-w-[240px] flex-1 gap-2">
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search by name, code or hospital" />
                        <Button type="submit" variant="secondary">
                            Search
                        </Button>
                    </form>

                    <Select
                        value={filters.hospital_id || 'all'}
                        onValueChange={(value) => applyFilters({ hospital_id: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-[220px]">
                            <SelectValue placeholder="Hospital" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All hospitals</SelectItem>
                            {hospitals.map((hospital) => (
                                <SelectItem key={hospital.id} value={String(hospital.id)}>
                                    {hospital.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

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
                                router.get(route('departments.index'));
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
                                <th className="px-4 py-3 font-medium">Department Name</th>
                                <th className="px-4 py-3 font-medium">Code</th>
                                <th className="px-4 py-3 font-medium">Hospital</th>
                                <th className="px-4 py-3 font-medium">Description</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                                <th className="px-4 py-3 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {departments.data.map((department) => (
                                <tr key={department.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b last:border-0">
                                    <td className="px-4 py-3">
                                        <Link href={route('departments.show', department.id)} className="font-medium hover:underline">
                                            {department.name}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3">{department.code}</td>
                                    <td className="px-4 py-3">{department.hospital?.name ?? '—'}</td>
                                    <td className="max-w-xs truncate px-4 py-3">{department.description ?? '—'}</td>
                                    <td className="px-4 py-3">
                                        <Badge variant={department.status === 'active' ? 'default' : 'secondary'}>{department.status}</Badge>
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-1">
                                            {can('departments.update') && (
                                                <Button variant="ghost" size="icon" asChild>
                                                    <Link href={route('departments.edit', department.id)} aria-label={`Edit ${department.name}`}>
                                                        <Pencil className="h-4 w-4" />
                                                    </Link>
                                                </Button>
                                            )}
                                            {can('departments.delete') && (
                                                <ConfirmDeleteDialog
                                                    title="Delete department"
                                                    description={`Are you sure you want to delete "${department.name}"? Historical records linked to it will not be removed, but this department will no longer be selectable.`}
                                                    deleteUrl={route('departments.destroy', department.id)}
                                                    trigger={
                                                        <Button variant="ghost" size="icon" aria-label={`Delete ${department.name}`}>
                                                            <Trash2 className="h-4 w-4" />
                                                        </Button>
                                                    }
                                                />
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}

                            {departments.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="px-4 py-12 text-center">
                                        <p className="text-muted-foreground mb-3">
                                            {hasFilters ? 'No departments match your search or filters.' : 'No departments found.'}
                                        </p>
                                        {!hasFilters && (
                                            <Can permission="departments.create">
                                                <Button asChild size="sm">
                                                    <Link href={route('departments.create')}>
                                                        <Plus className="h-4 w-4" />
                                                        Add Department
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

                <Pagination links={departments.links} />
            </div>
        </AppLayout>
    );
}
