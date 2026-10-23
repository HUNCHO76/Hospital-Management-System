import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { clinicalStatusLabel, clinicalStatusVariant } from '@/lib/admission-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type Admission, type AdmissionDepartment, type ClinicalStatus } from '@/types/admission';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    BedDouble,
    Clock,
    Search,
    ShieldAlert,
    Stethoscope,
    UserCheck,
} from 'lucide-react';
import * as React from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inpatient Care', href: '/inpatient' },
    { title: 'Nursing Workspace', href: '/inpatient/nursing' },
];

interface NursingDashboardProps {
    admissions: Paginated<Admission>;
    filters: {
        ward_type?: string;
        room_type?: string;
        department_id?: string;
        clinical_status?: string;
        search?: string;
    };
    departments: AdmissionDepartment[];
    clinicalStatuses: ClinicalStatus[];
    openEscalations: Record<number, number>;
    activeAllergiesCount: Record<number, number>;
    latestVitals: Record<number, any>;
    canViewAllWards: boolean;
}

function daysSince(dateStr: string | null): string {
    if (!dateStr) return '—';
    const days = Math.floor((Date.now() - new Date(dateStr).getTime()) / 86_400_000);
    return days === 0 ? 'Today' : `${days}d`;
}

export default function NursingDashboard({
    admissions,
    filters,
    departments,
    clinicalStatuses,
    openEscalations,
    activeAllergiesCount,
    latestVitals,
    canViewAllWards,
}: NursingDashboardProps) {
    const [searchTerm, setSearchTerm] = React.useState(filters.search || '');

    const applyFilters = (overrides: Record<string, string>) => {
        router.get(
            route('inpatient.nursing.dashboard'),
            { ...filters, search: searchTerm, ...overrides },
            { preserveState: true, replace: true },
        );
    };

    const handleSearchSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        applyFilters({ search: searchTerm });
    };

    const totalOpenEscalations = Object.values(openEscalations).reduce((acc, curr) => acc + curr, 0);
    const totalWithAllergies = Object.keys(activeAllergiesCount).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Nursing Ward Dashboard" />

            <div className="flex flex-col gap-6 p-4">
                <PageHeader
                    title="Inpatient Nursing Workspace"
                    description={
                        canViewAllWards
                            ? 'Ward census, clinical observations, shift handovers, and bedside nursing tasks across all units.'
                            : 'Ward census, clinical observations, shift handovers, and bedside nursing tasks in your assigned unit.'
                    }
                />

                {/* KPI Metrics */}
                <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                    <Card className="border-border/60 bg-gradient-to-br from-card to-card/50">
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Active Patients</CardTitle>
                            <BedDouble className="h-4 w-4 text-primary" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{admissions.total}</div>
                            <p className="text-xs text-muted-foreground">Admitted in authorized unit</p>
                        </CardContent>
                    </Card>

                    <Card className="border-border/60 bg-gradient-to-br from-card to-card/50">
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Open Escalations</CardTitle>
                            <AlertTriangle className={`h-4 w-4 ${totalOpenEscalations > 0 ? 'text-destructive animate-pulse' : 'text-muted-foreground'}`} />
                        </CardHeader>
                        <CardContent>
                            <div className={`text-2xl font-bold ${totalOpenEscalations > 0 ? 'text-destructive' : ''}`}>
                                {totalOpenEscalations}
                            </div>
                            <p className="text-xs text-muted-foreground">Awaiting clinician review</p>
                        </CardContent>
                    </Card>

                    <Card className="border-border/60 bg-gradient-to-br from-card to-card/50">
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Documented Allergies</CardTitle>
                            <ShieldAlert className="h-4 w-4 text-amber-500" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-amber-600 dark:text-amber-400">
                                {totalWithAllergies}
                            </div>
                            <p className="text-xs text-muted-foreground">Patients with active allergy alerts</p>
                        </CardContent>
                    </Card>

                    <Card className="border-border/60 bg-gradient-to-br from-card to-card/50">
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Ward Access</CardTitle>
                            <UserCheck className="h-4 w-4 text-emerald-500" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-sm font-semibold text-emerald-600 dark:text-emerald-400">
                                {canViewAllWards ? 'All Hospital Wards' : 'Assigned Unit'}
                            </div>
                            <p className="text-xs text-muted-foreground">Scoped by staff authorization</p>
                        </CardContent>
                    </Card>
                </div>

                {/* Filter Controls */}
                <div className="flex flex-wrap items-center gap-3">
                    <form onSubmit={handleSearchSubmit} className="relative min-w-[240px] flex-1 md:max-w-xs">
                        <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                        <Input
                            placeholder="Search patient, bed, or admission..."
                            className="pl-8"
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                        />
                    </form>

                    {canViewAllWards && (
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
                    )}

                    <Select
                        value={filters.clinical_status || 'all'}
                        onValueChange={(value) => applyFilters({ clinical_status: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-[180px]">
                            <SelectValue placeholder="Clinical Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            {clinicalStatuses.map((status) => (
                                <SelectItem key={status} value={status}>
                                    {clinicalStatusLabel[status]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Button variant="ghost" size="sm" onClick={() => router.get(route('inpatient.nursing.dashboard'))}>
                        Reset
                    </Button>
                </div>

                {/* Admissions Table */}
                {admissions.data.length === 0 ? (
                    <EmptyState
                        icon={BedDouble}
                        title="No admitted patients found"
                        description="There are currently no active inpatients matching the selected ward or filters."
                    />
                ) : (
                    <>
                        <div className="rounded-md border bg-card">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Ward / Bed</TableHead>
                                        <TableHead>Patient & Number</TableHead>
                                        <TableHead>Clinical Status</TableHead>
                                        <TableHead>Safety Alerts</TableHead>
                                        <TableHead>Latest Vitals</TableHead>
                                        <TableHead>LOS</TableHead>
                                        <TableHead>Attending Doctor</TableHead>
                                        <TableHead className="text-right">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {admissions.data.map((admission) => {
                                        const patientName = admission.patient
                                            ? `${admission.patient.first_name} ${admission.patient.last_name}`
                                            : '—';
                                        const escalationsCount = openEscalations[admission.id] ?? 0;
                                        const allergiesCount = activeAllergiesCount[admission.patient_id] ?? 0;
                                        const vitals = latestVitals[admission.id] ?? null;

                                        return (
                                            <TableRow key={admission.id} className="hover:bg-muted/40">
                                                <TableCell className="font-medium">
                                                    <div className="flex items-center gap-1.5 font-semibold text-primary">
                                                        <BedDouble className="h-4 w-4 text-muted-foreground" />
                                                        {[admission.ward_type, admission.room_type].filter(Boolean).join(' · ') || 'Ward Bed'}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {admission.department?.name ?? 'General'}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="font-medium">{patientName}</div>
                                                    <div className="text-xs text-muted-foreground font-mono">
                                                        {admission.patient?.patient_number} · #{admission.admission_number}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    {admission.clinical_status ? (
                                                        <Badge variant={clinicalStatusVariant[admission.clinical_status as ClinicalStatus]}>
                                                            {clinicalStatusLabel[admission.clinical_status as ClinicalStatus]}
                                                        </Badge>
                                                    ) : (
                                                        <span className="text-xs text-muted-foreground">—</span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex flex-col gap-1 items-start">
                                                        {escalationsCount > 0 && (
                                                            <Badge variant="destructive" className="gap-1 text-[11px] animate-pulse">
                                                                <AlertTriangle className="h-3 w-3" />
                                                                {escalationsCount} Escalation{escalationsCount > 1 ? 's' : ''}
                                                            </Badge>
                                                        )}
                                                        {allergiesCount > 0 ? (
                                                            <Badge variant="outline" className="gap-1 border-amber-500/50 bg-amber-500/10 text-amber-700 dark:text-amber-300 text-[11px]">
                                                                <ShieldAlert className="h-3 w-3" />
                                                                {allergiesCount} Allergy Alert{allergiesCount > 1 ? 's' : ''}
                                                            </Badge>
                                                        ) : (
                                                            <span className="text-xs text-muted-foreground">No allergy recorded</span>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    {vitals ? (
                                                        <div className="text-xs space-y-0.5">
                                                            <div>
                                                                <span className="font-medium">BP:</span> {vitals.systolic_mmhg ?? '—'}/{vitals.diastolic_mmhg ?? '—'} · <span className="font-medium">HR:</span> {vitals.pulse_bpm ?? '—'}
                                                            </div>
                                                            <div className="text-muted-foreground">
                                                                <span className="font-medium">SpO₂:</span> {vitals.oxygen_saturation_pct ? `${vitals.oxygen_saturation_pct}%` : '—'} · <span className="font-medium">T:</span> {vitals.temperature_c ? `${vitals.temperature_c}°C` : '—'}
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <span className="text-xs text-muted-foreground">No vitals logged</span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-sm">
                                                    <div className="flex items-center gap-1 text-muted-foreground">
                                                        <Clock className="h-3.5 w-3.5" />
                                                        {daysSince(admission.admitted_at)}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-sm">
                                                    {admission.practitioner?.staff
                                                        ? `Dr. ${admission.practitioner.staff.first_name} ${admission.practitioner.staff.last_name}`
                                                        : '—'}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <Button size="sm" asChild>
                                                            <Link href={route('inpatient.nursing.workspace', admission.id)}>
                                                                Nursing Workspace
                                                            </Link>
                                                        </Button>
                                                        <Button variant="outline" size="sm" asChild>
                                                            <Link href={route('inpatient.clinical-profile', admission.id)}>
                                                                <Stethoscope className="h-3.5 w-3.5" />
                                                            </Link>
                                                        </Button>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>
                        <Pagination links={admissions.links} />
                    </>
                )}
            </div>
        </AppLayout>
    );
}
