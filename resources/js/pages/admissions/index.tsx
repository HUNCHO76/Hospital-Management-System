import { Can } from '@/components/authorization/can';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { admissionStatusLabel, admissionStatusVariant, admissionTypeLabel } from '@/lib/admission-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type Admission, type AdmissionDepartment, type AdmissionFilters, type AdmissionType } from '@/types/admission';
import { Head, Link, router } from '@inertiajs/react';
import { BedDouble, Plus } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Admissions', href: '/admissions' }];

interface AdmissionsIndexProps {
    admissions: Paginated<Admission>;
    filters: AdmissionFilters;
    departments: AdmissionDepartment[];
    admissionTypes: AdmissionType[];
    statuses: Admission['status'][];
}

export default function AdmissionsIndex({ admissions, filters, departments, admissionTypes, statuses }: AdmissionsIndexProps) {
    const applyFilters = (overrides: Partial<AdmissionFilters>) => {
        const params: Record<string, string> = { ...filters, ...overrides };
        router.get(route('admissions.index'), params, { preserveState: true, replace: true });
    };

    const clearFilters = () => {
        router.get(route('admissions.index'));
    };

    const hasFilters = Boolean(filters.status || filters.department_id || filters.admission_type);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Admissions" />

            <div className="flex flex-col gap-6 p-4">
                <PageHeader
                    title="Admissions"
                    description="Inpatient admission requests and their status."
                    actions={
                        <Can permission="admission.create">
                            <Button asChild>
                                <Link href={route('admissions.create')}>
                                    <Plus className="h-4 w-4" />
                                    New Admission Request
                                </Link>
                            </Button>
                        </Can>
                    }
                />

                <div className="flex flex-wrap items-center gap-2">
                    <Select value={filters.department_id || 'all'} onValueChange={(value) => applyFilters({ department_id: value === 'all' ? '' : value })}>
                        <SelectTrigger className="w-[200px]">
                            <SelectValue placeholder="All departments" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All departments</SelectItem>
                            {departments.map((department) => (
                                <SelectItem key={department.id} value={String(department.id)}>
                                    {department.name}
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
                            {statuses.map((status) => (
                                <SelectItem key={status} value={status}>
                                    {admissionStatusLabel[status]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select value={filters.admission_type || 'all'} onValueChange={(value) => applyFilters({ admission_type: value === 'all' ? '' : value })}>
                        <SelectTrigger className="w-[160px]">
                            <SelectValue placeholder="All types" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All types</SelectItem>
                            {admissionTypes.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {admissionTypeLabel[type] ?? type}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    {hasFilters && (
                        <Button variant="ghost" size="sm" onClick={clearFilters}>
                            Clear filters
                        </Button>
                    )}
                </div>

                <div>
                    <p className="text-muted-foreground mb-2 text-sm">
                        {admissions.total > 0
                            ? `Showing ${admissions.from ?? 0}–${admissions.to ?? 0} of ${admissions.total} admission${admissions.total === 1 ? '' : 's'}`
                            : null}
                    </p>

                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Admission No</TableHead>
                                <TableHead>Patient</TableHead>
                                <TableHead>Hospital</TableHead>
                                <TableHead>Department</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Created</TableHead>
                                <TableHead>Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {admissions.data.map((admission) => (
                                <TableRow key={admission.id}>
                                    <TableCell>
                                        <Link href={route('admissions.show', admission.id)} className="font-medium hover:underline">
                                            {admission.admission_number}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        {admission.patient
                                            ? [admission.patient.first_name, admission.patient.middle_name, admission.patient.last_name]
                                                  .filter(Boolean)
                                                  .join(' ')
                                            : '—'}
                                    </TableCell>
                                    <TableCell>{admission.hospital?.name ?? '—'}</TableCell>
                                    <TableCell>{admission.department?.name ?? '—'}</TableCell>
                                    <TableCell>{admission.admission_type ? (admissionTypeLabel[admission.admission_type] ?? admission.admission_type) : '—'}</TableCell>
                                    <TableCell>
                                        <Badge variant={admissionStatusVariant[admission.status]}>{admissionStatusLabel[admission.status]}</Badge>
                                    </TableCell>
                                    <TableCell>
                                        {new Date(admission.created_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}
                                    </TableCell>
                                    <TableCell>
                                        <Button variant="ghost" size="sm" asChild>
                                            <Link href={route('admissions.show', admission.id)}>View</Link>
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}

                            {admissions.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={8} className="p-0">
                                        <EmptyState
                                            icon={BedDouble}
                                            title={hasFilters ? 'No admissions match your filters' : 'No admissions found'}
                                            description={hasFilters ? 'Try a different filter or clear your filters.' : undefined}
                                        />
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>

                <Pagination links={admissions.links} />
            </div>
        </AppLayout>
    );
}
