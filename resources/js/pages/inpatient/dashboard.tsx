import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { admissionTypeLabel, clinicalStatusLabel, clinicalStatusVariant } from '@/lib/admission-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type Admission, type AdmissionDepartment, type ClinicalStatus } from '@/types/admission';
import { Head, Link, router } from '@inertiajs/react';
import { Activity, BedDouble, Stethoscope } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inpatient Ward', href: '/inpatient' },
];

interface InpatientDashboardProps {
    admissions: Paginated<Admission>;
    filters: {
        ward_type?: string;
        department_id?: string;
        hospital_id?: string;
        clinical_status?: string;
        date_from?: string;
    };
    departments: AdmissionDepartment[];
    clinicalStatuses: ClinicalStatus[];
    canUpdateClinicalStatus?: boolean;
}

function daysSince(dateStr: string | null): string {
    if (!dateStr) {
        return '—';
    }
    const days = Math.floor((Date.now() - new Date(dateStr).getTime()) / 86_400_000);
    return days === 0 ? 'Today' : `${days}d`;
}

function practitionerName(admission: Admission): string {
    const staff = admission.practitioner?.staff;
    if (!staff) {
        return admission.practitioner?.practitioner_number ?? '—';
    }
    return `${staff.first_name} ${staff.last_name}`;
}

export default function InpatientDashboard({ admissions, filters, departments, clinicalStatuses, canUpdateClinicalStatus }: InpatientDashboardProps) {
    const applyFilters = (overrides: Record<string, string>) => {
        router.get(route('inpatient.dashboard'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(filters.department_id || filters.ward_type || filters.clinical_status);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Inpatient Ward" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <PageHeader
                        title="Inpatient Ward"
                        description="Active admissions and their current clinical status."
                    />
                    <Button variant="outline" className="border-emerald-600/30 text-emerald-700 hover:bg-emerald-50 dark:hover:bg-emerald-950/40" asChild>
                        <Link href={route('inpatient.nursing.dashboard')}>
                            <Activity className="mr-2 h-4 w-4 text-emerald-600" />
                            Nursing Station
                        </Link>
                    </Button>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Select
                        value={filters.department_id || 'all'}
                        onValueChange={(value) => applyFilters({ department_id: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-[180px]">
                            <SelectValue placeholder="All Departments" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Departments</SelectItem>
                            {departments.map((dept) => (
                                <SelectItem key={dept.id} value={String(dept.id)}>
                                    {dept.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.clinical_status || 'all'}
                        onValueChange={(value) => applyFilters({ clinical_status: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-[180px]">
                            <SelectValue placeholder="All Clinical Statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Clinical Statuses</SelectItem>
                            {clinicalStatuses.map((status) => (
                                <SelectItem key={status} value={status}>
                                    {clinicalStatusLabel[status]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    {hasFilters && (
                        <Button variant="ghost" size="sm" onClick={() => router.get(route('inpatient.dashboard'))}>
                            Clear filters
                        </Button>
                    )}
                </div>

                {admissions.data.length === 0 ? (
                    <EmptyState icon={BedDouble} title="No active admissions" description="There are no active inpatient admissions at the moment." />
                ) : (
                    <>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Admission #</TableHead>
                                    <TableHead>Patient</TableHead>
                                    <TableHead>Ward / Room / Bed</TableHead>
                                    <TableHead>Department</TableHead>
                                    <TableHead>Attending</TableHead>
                                    <TableHead>Admitted</TableHead>
                                    <TableHead>LOS</TableHead>
                                    <TableHead>Clinical Status</TableHead>
                                    <TableHead>Encounters</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {admissions.data.map((admission) => {
                                    const patientName = admission.patient
                                        ? `${admission.patient.first_name} ${admission.patient.last_name}`
                                        : '—';

                                    const currentStatus = (admission.clinical_status ?? 'under_observation') as ClinicalStatus;

                                    return (
                                        <TableRow key={admission.id}>
                                            <TableCell className="font-mono text-sm">{admission.admission_number}</TableCell>
                                            <TableCell>
                                                <div>{patientName}</div>
                                                <div className="text-muted-foreground text-xs">{admission.patient?.patient_number}</div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="text-sm">
                                                    {[admission.ward_type, admission.room_type].filter(Boolean).join(' / ') || '—'}
                                                </div>
                                            </TableCell>
                                            <TableCell>{admission.department?.name ?? '—'}</TableCell>
                                            <TableCell className="text-sm">{practitionerName(admission)}</TableCell>
                                            <TableCell className="text-sm">
                                                {admission.admitted_at ? new Date(admission.admitted_at).toLocaleDateString() : '—'}
                                            </TableCell>
                                            <TableCell className="text-sm">{daysSince(admission.admitted_at)}</TableCell>
                                            <TableCell>
                                                {canUpdateClinicalStatus ? (
                                                    <Select
                                                        value={currentStatus}
                                                        onValueChange={(newStatus) => {
                                                            router.post(
                                                                route('inpatient.update-clinical-status', admission.id),
                                                                { clinical_status: newStatus },
                                                                { preserveScroll: true },
                                                            );
                                                        }}
                                                    >
                                                        <SelectTrigger className="h-7 w-[150px] border-0 bg-transparent p-0 shadow-none hover:bg-muted/50 focus:ring-0">
                                                            <Badge
                                                                variant={clinicalStatusVariant[currentStatus]}
                                                                className="cursor-pointer text-[11px]"
                                                            >
                                                                {clinicalStatusLabel[currentStatus]}
                                                            </Badge>
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {clinicalStatuses.map((s) => (
                                                                <SelectItem key={s} value={s} className="text-xs">
                                                                    {clinicalStatusLabel[s]}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                ) : admission.clinical_status ? (
                                                    <Badge variant={clinicalStatusVariant[admission.clinical_status as ClinicalStatus]}>
                                                        {clinicalStatusLabel[admission.clinical_status as ClinicalStatus]}
                                                    </Badge>
                                                ) : (
                                                    <span className="text-muted-foreground text-xs">Not set</span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-7 px-2 text-xs font-normal hover:bg-muted"
                                                    asChild
                                                >
                                                    <Link href={route('inpatient.clinical-profile', admission.id)}>
                                                        <Stethoscope className="mr-1 h-3.5 w-3.5 text-muted-foreground" />
                                                        <span>{admission.inpatient_encounters_count ?? 0}</span>
                                                    </Link>
                                                </Button>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex justify-end gap-1.5">
                                                    <Button variant="outline" size="sm" className="border-emerald-600/30 text-emerald-700 hover:bg-emerald-50 dark:hover:bg-emerald-950/40" asChild>
                                                        <Link href={route('inpatient.nursing.workspace', admission.id)}>
                                                            <Activity className="mr-1.5 h-3.5 w-3.5 text-emerald-600" />
                                                            Nursing
                                                        </Link>
                                                    </Button>
                                                    <Button variant="outline" size="sm" asChild>
                                                        <Link href={route('inpatient.clinical-profile', admission.id)}>
                                                            Clinical Profile
                                                        </Link>
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>

                        <Pagination links={admissions.links} />
                    </>
                )}
            </div>
        </AppLayout>
    );
}
