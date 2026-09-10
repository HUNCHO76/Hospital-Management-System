import { Can } from '@/components/authorization/can';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { licenseStatus, licenseStatusLabel } from '@/lib/license-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type DepartmentHospital } from '@/types/department';
import { type Practitioner, type PractitionerFilters } from '@/types/practitioner';
import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Practitioners', href: '/practitioners' }];

interface PractitionersIndexProps {
    practitioners: Paginated<Practitioner>;
    filters: PractitionerFilters;
    hospitals: DepartmentHospital[];
    practitionerTypes: string[];
}

export default function PractitionersIndex({ practitioners, filters, hospitals, practitionerTypes }: PractitionersIndexProps) {
    const { can } = usePermissions();
    const [search, setSearch] = useState(filters.search ?? '');

    const applyFilters = (overrides: Partial<PractitionerFilters>) => {
        router.get(
            route('practitioners.index'),
            {
                search,
                hospital_id: filters.hospital_id ?? '',
                practitioner_type: filters.practitioner_type ?? '',
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

    const hasFilters = Boolean(filters.search || filters.hospital_id || filters.practitioner_type || filters.status);

    const statusVariant: Record<string, 'default' | 'secondary' | 'destructive'> = {
        valid: 'default',
        'expiring-soon': 'secondary',
        expired: 'destructive',
        unknown: 'secondary',
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Practitioners" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-semibold">Practitioners</h1>

                    <Can permission="practitioners.create">
                        <Button asChild>
                            <Link href={route('practitioners.create')}>
                                <Plus className="h-4 w-4" />
                                Add Practitioner
                            </Link>
                        </Button>
                    </Can>
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    <form onSubmit={submitSearch} className="flex min-w-[240px] flex-1 gap-2">
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search by name, practitioner number, registration or license number"
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

                    <Select
                        value={filters.practitioner_type || 'all'}
                        onValueChange={(value) => applyFilters({ practitioner_type: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-[180px]">
                            <SelectValue placeholder="Type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All types</SelectItem>
                            {practitionerTypes.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {type.replace('-', ' ')}
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

                    {hasFilters && (
                        <Button
                            variant="ghost"
                            onClick={() => {
                                setSearch('');
                                router.get(route('practitioners.index'));
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
                                <th className="px-4 py-3 font-medium">Practitioner Number</th>
                                <th className="px-4 py-3 font-medium">Name</th>
                                <th className="px-4 py-3 font-medium">Type</th>
                                <th className="px-4 py-3 font-medium">Specialization</th>
                                <th className="px-4 py-3 font-medium">License</th>
                                <th className="px-4 py-3 font-medium">Hospital</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                                <th className="px-4 py-3 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {practitioners.data.map((practitioner) => {
                                const license = licenseStatus(practitioner.license_expiry_date);

                                return (
                                    <tr
                                        key={practitioner.id}
                                        className="border-sidebar-border/70 dark:border-sidebar-border border-b last:border-0"
                                    >
                                        <td className="px-4 py-3">{practitioner.practitioner_number}</td>
                                        <td className="px-4 py-3">
                                            <Link href={route('practitioners.show', practitioner.id)} className="font-medium hover:underline">
                                                {practitioner.staff ? `${practitioner.staff.first_name} ${practitioner.staff.last_name}` : '—'}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3 capitalize">{practitioner.practitioner_type.replace('-', ' ')}</td>
                                        <td className="px-4 py-3">{practitioner.specialization ?? '—'}</td>
                                        <td className="px-4 py-3">
                                            <Badge variant={statusVariant[license]}>{licenseStatusLabel[license]}</Badge>
                                        </td>
                                        <td className="px-4 py-3">{practitioner.staff?.hospital?.name ?? '—'}</td>
                                        <td className="px-4 py-3">
                                            <Badge variant={practitioner.status === 'active' ? 'default' : 'secondary'}>
                                                {practitioner.status}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-1">
                                                {can('practitioners.update') && (
                                                    <Button variant="ghost" size="icon" asChild>
                                                        <Link href={route('practitioners.edit', practitioner.id)} aria-label="Edit practitioner">
                                                            <Pencil className="h-4 w-4" />
                                                        </Link>
                                                    </Button>
                                                )}
                                                {can('practitioners.delete') && (
                                                    <ConfirmDeleteDialog
                                                        title="Delete practitioner profile"
                                                        description="Are you sure you want to delete this practitioner profile? The underlying staff record will not be affected."
                                                        deleteUrl={route('practitioners.destroy', practitioner.id)}
                                                        trigger={
                                                            <Button variant="ghost" size="icon" aria-label="Delete practitioner">
                                                                <Trash2 className="h-4 w-4" />
                                                            </Button>
                                                        }
                                                    />
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}

                            {practitioners.data.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="px-4 py-12 text-center">
                                        <p className="text-muted-foreground mb-3">
                                            {hasFilters ? 'No practitioners match your search or filters.' : 'No practitioners found.'}
                                        </p>
                                        {!hasFilters && (
                                            <Can permission="practitioners.create">
                                                <Button asChild size="sm">
                                                    <Link href={route('practitioners.create')}>
                                                        <Plus className="h-4 w-4" />
                                                        Add Practitioner
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

                <Pagination links={practitioners.links} />
            </div>
        </AppLayout>
    );
}
