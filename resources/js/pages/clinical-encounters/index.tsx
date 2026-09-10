import { Can } from '@/components/authorization/can';
import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import AppLayout from '@/layouts/app-layout';
import { clinicalEncounterStatusLabel, clinicalEncounterStatusVariant } from '@/lib/clinical-encounter-status';
import { consultationStatusLabel, consultationStatusVariant } from '@/lib/consultation-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import {
    type ClinicalEncounter,
    type ClinicalEncounterDepartment,
    type ClinicalEncounterFilters,
    type ClinicalEncounterPractitioner,
    type ClinicalEncounterWorkflowStage,
} from '@/types/clinical-encounter';
import { type ConsultationStatus } from '@/types/consultation';
import { Head, Link, router } from '@inertiajs/react';
import { ClipboardPlus, Stethoscope, UserRound } from 'lucide-react';
import { type FormEventHandler, useEffect, useRef, useState } from 'react';

const WORKFLOW_STAGE_LABELS: Record<ClinicalEncounterWorkflowStage, string> = {
    waiting_for_triage: 'Waiting for Triage',
    ready_for_consultation: 'Ready for Consultation',
    in_consultation: 'In Consultation',
    completed: 'Completed',
};

const WORKFLOW_STAGES: ClinicalEncounterWorkflowStage[] = ['waiting_for_triage', 'ready_for_consultation', 'in_consultation', 'completed'];

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Clinical Encounters', href: '/clinical-encounters' }];

const ENCOUNTER_TYPE_LABELS: Record<string, string> = {
    opd: 'OPD',
    walk_in: 'Walk-in',
    scheduled: 'Scheduled',
    follow_up: 'Follow-up',
};

interface ClinicalEncountersIndexProps {
    encounters: Paginated<ClinicalEncounter>;
    filters: ClinicalEncounterFilters;
    departments: ClinicalEncounterDepartment[];
    encounterTypes: string[];
    practitioners: ClinicalEncounterPractitioner[];
    myPractitionerId: number | null;
}

export default function ClinicalEncountersIndex({
    encounters,
    filters,
    departments,
    encounterTypes,
    practitioners,
    myPractitionerId,
}: ClinicalEncountersIndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [isSearching, setIsSearching] = useState(false);
    const debouncedSearch = useDebouncedValue(search, 350);
    const isFirstRender = useRef(true);

    const allFilterKeys: (keyof ClinicalEncounterFilters)[] = [
        'search',
        'department_id',
        'practitioner_id',
        'status',
        'encounter_type',
        'date_from',
        'date_to',
        'workflow_stage',
    ];

    const applyFilters = (overrides: Partial<ClinicalEncounterFilters>) => {
        const params: Record<string, string> = {};
        allFilterKeys.forEach((key) => {
            params[key] = (filters[key] as string) ?? '';
        });
        params.search = search;
        Object.assign(params, overrides);

        setIsSearching(true);
        router.get(route('clinical-encounters.index'), params, {
            preserveState: true,
            replace: true,
            onFinish: () => setIsSearching(false),
        });
    };

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;

            return;
        }

        if (debouncedSearch === (filters.search ?? '')) {
            return;
        }

        applyFilters({ search: debouncedSearch });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedSearch]);

    const submitSearch: FormEventHandler = (e) => {
        e.preventDefault();
        applyFilters({ search });
    };

    const clearFilters = () => {
        setSearch('');
        setIsSearching(true);
        router.get(route('clinical-encounters.index'), {}, { onFinish: () => setIsSearching(false) });
    };

    const hasFilters = Boolean(
        filters.search ||
            filters.department_id ||
            filters.practitioner_id ||
            filters.status ||
            filters.encounter_type ||
            filters.date_from ||
            filters.date_to ||
            filters.workflow_stage,
    );

    const patientName = (encounter: ClinicalEncounter): string =>
        encounter.patient
            ? [encounter.patient.first_name, encounter.patient.middle_name, encounter.patient.last_name].filter(Boolean).join(' ')
            : '—';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Clinical Encounters" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Clinical Encounters</h1>
                        <p className="text-muted-foreground text-sm">The clinical episode of care for each patient's visit to the service.</p>
                    </div>

                    <Can permission="clinical-encounters.create">
                        <Button asChild>
                            <Link href={route('clinical-encounters.create')}>
                                <ClipboardPlus className="h-4 w-4" />
                                New Encounter
                            </Link>
                        </Button>
                    </Can>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    {WORKFLOW_STAGES.map((stage) => (
                        <Button
                            key={stage}
                            type="button"
                            size="sm"
                            variant={filters.workflow_stage === stage ? 'default' : 'outline'}
                            onClick={() => applyFilters({ workflow_stage: filters.workflow_stage === stage ? '' : stage })}
                        >
                            {WORKFLOW_STAGE_LABELS[stage]}
                        </Button>
                    ))}
                </div>

                <div className="flex flex-col gap-3">
                    <form onSubmit={submitSearch} className="flex gap-2">
                        <div className="relative flex-1">
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search by encounter number, patient name, or number"
                                aria-label="Search clinical encounters"
                            />
                            {isSearching && (
                                <span className="absolute top-1/2 right-3 -translate-y-1/2">
                                    <Spinner label="Searching" />
                                </span>
                            )}
                        </div>
                        <Button type="submit" variant="secondary">
                            Search
                        </Button>
                    </form>

                    <div className="flex flex-wrap items-center gap-2">
                        <Select
                            value={filters.department_id || 'all'}
                            onValueChange={(value) => applyFilters({ department_id: value === 'all' ? '' : value })}
                        >
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
                                {Object.entries(clinicalEncounterStatusLabel).map(([value, label]) => (
                                    <SelectItem key={value} value={value}>
                                        {label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.encounter_type || 'all'}
                            onValueChange={(value) => applyFilters({ encounter_type: value === 'all' ? '' : value })}
                        >
                            <SelectTrigger className="w-[160px]">
                                <SelectValue placeholder="All types" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All types</SelectItem>
                                {encounterTypes.map((type) => (
                                    <SelectItem key={type} value={type}>
                                        {ENCOUNTER_TYPE_LABELS[type] ?? type}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.practitioner_id || 'all'}
                            onValueChange={(value) => applyFilters({ practitioner_id: value === 'all' ? '' : value })}
                        >
                            <SelectTrigger className="w-[200px]">
                                <SelectValue placeholder="All practitioners" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All practitioners</SelectItem>
                                {practitioners.map((practitioner) => (
                                    <SelectItem key={practitioner.id} value={String(practitioner.id)}>
                                        {practitioner.staff
                                            ? `${practitioner.staff.first_name} ${practitioner.staff.last_name}`
                                            : practitioner.practitioner_number}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        {myPractitionerId && (
                            <Button
                                type="button"
                                variant={filters.practitioner_id === String(myPractitionerId) ? 'default' : 'outline'}
                                size="sm"
                                onClick={() =>
                                    applyFilters({
                                        practitioner_id: filters.practitioner_id === String(myPractitionerId) ? '' : String(myPractitionerId),
                                    })
                                }
                            >
                                <UserRound className="h-4 w-4" />
                                My Encounters
                            </Button>
                        )}

                        <Input
                            type="date"
                            className="w-[160px]"
                            value={filters.date_from || ''}
                            onChange={(e) => applyFilters({ date_from: e.target.value })}
                            aria-label="From date"
                        />
                        <Input
                            type="date"
                            className="w-[160px]"
                            value={filters.date_to || ''}
                            onChange={(e) => applyFilters({ date_to: e.target.value })}
                            aria-label="To date"
                        />

                        {hasFilters && (
                            <Button variant="ghost" size="sm" onClick={clearFilters}>
                                Clear filters
                            </Button>
                        )}
                    </div>
                </div>

                <div>
                    <p className="text-muted-foreground mb-2 text-sm">
                        {encounters.total > 0
                            ? `Showing ${encounters.from ?? 0}–${encounters.to ?? 0} of ${encounters.total} encounter${encounters.total === 1 ? '' : 's'}`
                            : null}
                    </p>

                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Encounter No</TableHead>
                                <TableHead>Patient</TableHead>
                                <TableHead>Department</TableHead>
                                <TableHead>Practitioner</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Consultation</TableHead>
                                <TableHead>Created</TableHead>
                                <TableHead>Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {encounters.data.map((encounter) => (
                                <TableRow key={encounter.id}>
                                    <TableCell>
                                        <Link href={route('clinical-encounters.show', encounter.id)} className="font-medium hover:underline">
                                            {encounter.encounter_number}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex flex-col">
                                            <span>{patientName(encounter)}</span>
                                            {encounter.patient && (
                                                <span className="text-muted-foreground text-xs">{encounter.patient.patient_number}</span>
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell>{encounter.department?.name ?? '—'}</TableCell>
                                    <TableCell>
                                        {encounter.practitioner?.staff
                                            ? `${encounter.practitioner.staff.first_name} ${encounter.practitioner.staff.last_name}`
                                            : '—'}
                                    </TableCell>
                                    <TableCell>{ENCOUNTER_TYPE_LABELS[encounter.encounter_type] ?? encounter.encounter_type}</TableCell>
                                    <TableCell>
                                        <Badge variant={clinicalEncounterStatusVariant[encounter.status]}>
                                            {clinicalEncounterStatusLabel[encounter.status]}
                                        </Badge>
                                    </TableCell>
                                    <TableCell>
                                        {encounter.consultation ? (
                                            <Badge variant={consultationStatusVariant[encounter.consultation.status as ConsultationStatus]}>
                                                {consultationStatusLabel[encounter.consultation.status as ConsultationStatus]}
                                            </Badge>
                                        ) : (
                                            <span className="text-muted-foreground">—</span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {new Date(encounter.created_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}
                                    </TableCell>
                                    <TableCell>
                                        <Button variant="ghost" size="sm" asChild>
                                            <Link href={route('clinical-encounters.show', encounter.id)}>View</Link>
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}

                            {encounters.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={9} className="p-0">
                                        <EmptyState
                                            icon={Stethoscope}
                                            title={hasFilters ? 'No encounters match your search or filters' : 'No clinical encounters found'}
                                            description={
                                                hasFilters
                                                    ? 'Try a different search term or clear your filters.'
                                                    : 'Clinical encounters appear here once a patient is ready for clinical service.'
                                            }
                                            action={
                                                !hasFilters ? (
                                                    <Can permission="clinical-encounters.create">
                                                        <Button asChild size="sm">
                                                            <Link href={route('clinical-encounters.create')}>
                                                                <ClipboardPlus className="h-4 w-4" />
                                                                New Encounter
                                                            </Link>
                                                        </Button>
                                                    </Can>
                                                ) : undefined
                                            }
                                        />
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>

                <Pagination links={encounters.links} />
            </div>
        </AppLayout>
    );
}
