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
import { type DepartmentHospital } from '@/types/department';
import { type Staff, type StaffFilters } from '@/types/staff';
import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Plus, Shield, Trash2 } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Staff', href: '/staff' }];

interface StaffIndexProps {
    staff: Paginated<Staff>;
    filters: StaffFilters;
    hospitals: DepartmentHospital[];
}

export default function StaffIndex({ staff, filters, hospitals }: StaffIndexProps) {
    const { can } = usePermissions();
    const [search, setSearch] = useState(filters.search ?? '');

    const applyFilters = (overrides: Partial<StaffFilters>) => {
        router.get(
            route('staff.index'),
            {
                search,
                hospital_id: filters.hospital_id ?? '',
                status: filters.status ?? '',
                has_practitioner: filters.has_practitioner ?? '',
                ...overrides,
            },
            { preserveState: true, replace: true },
        );
    };

    const submitSearch: FormEventHandler = (e) => {
        e.preventDefault();
        applyFilters({ search });
    };

    const hasFilters = Boolean(filters.search || filters.hospital_id || filters.status || filters.has_practitioner);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Staff" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-semibold">Staff</h1>

                    <Can permission="staff.create">
                        <Button asChild>
                            <Link href={route('staff.create')}>
                                <Plus className="h-4 w-4" />
                                Add Staff
                            </Link>
                        </Button>
                    </Can>
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    <form onSubmit={submitSearch} className="flex min-w-[240px] flex-1 gap-2">
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search by name, staff number, phone, email or job title"
                        />
                        <Button type="submit" variant="secondary">
                            Search
                        </Button>
                    </form>

                    <Select
                        value={filters.hospital_id || 'all'}
                        onValueChange={(value) => applyFilters({ hospital_id: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-[200px]">
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
                        <SelectTrigger className="w-[150px]">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                            <SelectItem value="suspended">Suspended</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.has_practitioner || 'all'}
                        onValueChange={(value) => applyFilters({ has_practitioner: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-[170px]">
                            <SelectValue placeholder="Practitioner" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All staff</SelectItem>
                            <SelectItem value="1">Practitioners only</SelectItem>
                            <SelectItem value="0">Non-practitioners only</SelectItem>
                        </SelectContent>
                    </Select>

                    {hasFilters && (
                        <Button
                            variant="ghost"
                            onClick={() => {
                                setSearch('');
                                router.get(route('staff.index'));
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
                                <th className="px-4 py-3 font-medium">Staff Number</th>
                                <th className="px-4 py-3 font-medium">Name</th>
                                <th className="px-4 py-3 font-medium">Hospital</th>
                                <th className="px-4 py-3 font-medium">Department</th>
                                <th className="px-4 py-3 font-medium">Job Title</th>
                                <th className="px-4 py-3 font-medium">Phone</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                                <th className="px-4 py-3 font-medium">Practitioner</th>
                                <th className="px-4 py-3 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {staff.data.map((member) => (
                                <tr key={member.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b last:border-0">
                                    <td className="px-4 py-3">{member.staff_number}</td>
                                    <td className="px-4 py-3">
                                        <Link href={route('staff.show', member.id)} className="font-medium hover:underline">
                                            {member.first_name} {member.last_name}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3">{member.hospital?.name ?? '—'}</td>
                                    <td className="px-4 py-3">{member.department?.name ?? '—'}</td>
                                    <td className="px-4 py-3">{member.job_title}</td>
                                    <td className="px-4 py-3">{member.phone ?? '—'}</td>
                                    <td className="px-4 py-3">
                                        <Badge variant={member.status === 'active' ? 'default' : 'secondary'}>{member.status}</Badge>
                                    </td>
                                    <td className="px-4 py-3">
                                        {member.practitioner ? (
                                            <Badge>{member.practitioner.practitioner_type}</Badge>
                                        ) : (
                                            <span className="text-muted-foreground">—</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-1">
                                            {can('staff.update') && (
                                                <Button variant="ghost" size="icon" asChild>
                                                    <Link
                                                        href={route('staff.edit', member.id)}
                                                        aria-label={`Edit ${member.first_name} ${member.last_name}`}
                                                    >
                                                        <Pencil className="h-4 w-4" />
                                                    </Link>
                                                </Button>
                                            )}
                                            {member.user && (
                                                <Button variant="ghost" size="icon" asChild>
                                                    <Link
                                                        href={route('users.permissions.edit', member.user.id)}
                                                        title={`Manage permissions for ${member.first_name} ${member.last_name}`}
                                                        aria-label={`Permissions for ${member.first_name} ${member.last_name}`}
                                                    >
                                                        <Shield className="h-4 w-4 text-primary" />
                                                    </Link>
                                                </Button>
                                            )}
                                            {can('staff.delete') && (
                                                <ConfirmDeleteDialog
                                                    title="Delete staff member"
                                                    description={`Are you sure you want to delete "${member.first_name} ${member.last_name}"? Historical records linked to them will not be removed.`}
                                                    deleteUrl={route('staff.destroy', member.id)}
                                                    trigger={
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label={`Delete ${member.first_name} ${member.last_name}`}
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                        </Button>
                                                    }
                                                />
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}

                            {staff.data.length === 0 && (
                                <tr>
                                    <td colSpan={9} className="px-4 py-12 text-center">
                                        <p className="text-muted-foreground mb-3">
                                            {hasFilters ? 'No staff match your search or filters.' : 'No staff found.'}
                                        </p>
                                        {!hasFilters && (
                                            <Can permission="staff.create">
                                                <Button asChild size="sm">
                                                    <Link href={route('staff.create')}>
                                                        <Plus className="h-4 w-4" />
                                                        Add Staff
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

                <Pagination links={staff.links} />
            </div>
        </AppLayout>
    );
}
