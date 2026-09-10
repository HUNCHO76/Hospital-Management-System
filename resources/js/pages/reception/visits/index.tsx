import { Can } from '@/components/authorization/can';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import AppLayout from '@/layouts/app-layout';
import { receptionStatusLabel, receptionStatusVariant } from '@/lib/reception-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type AppointmentDepartment } from '@/types/appointment';
import { type ReceptionVisit, type ReceptionVisitFilters } from '@/types/reception';
import { Head, Link, router } from '@inertiajs/react';
import { ClipboardList, Search, UserPlus } from 'lucide-react';
import { type FormEventHandler, useEffect, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Reception', href: '/reception' },
    { title: 'Visits', href: '/reception/visits' },
];

interface VisitsIndexProps {
    visits: Paginated<ReceptionVisit>;
    filters: ReceptionVisitFilters;
    departments: AppointmentDepartment[];
}

const VISIT_TYPE_LABELS: Record<string, string> = {
    appointment: 'Appointment',
    walk_in: 'Walk-in',
    follow_up: 'Follow-up',
    other: 'Other',
};

export default function VisitsIndex({ visits, filters, departments }: VisitsIndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [isSearching, setIsSearching] = useState(false);
    const debouncedSearch = useDebouncedValue(search, 350);
    const isFirstRender = useRef(true);

    const allFilterKeys: (keyof ReceptionVisitFilters)[] = ['date', 'department_id', 'status', 'visit_type', 'search'];

    const applyFilters = (overrides: Partial<ReceptionVisitFilters>) => {
        const params: Record<string, string> = {};
        allFilterKeys.forEach((key) => {
            params[key] = (filters[key] as string) ?? '';
        });
        params.search = search;
        Object.assign(params, overrides);

        setIsSearching(true);
        router.get(route('reception.visits.index'), params, {
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
        router.get(route('reception.visits.index'), {}, { onFinish: () => setIsSearching(false) });
    };

    const hasFilters = Boolean(filters.date || filters.department_id || filters.status || filters.visit_type || filters.search);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Reception Visits" />

            <div className="flex flex-col gap-6 p-4">
                <PageHeader
                    title="Reception Visits"
                    description="Search, filter, and manage front-desk visits."
                    actions={
                        <Can permission="reception.create_walkin">
                            <Button asChild>
                                <Link href={route('reception.walk-in.create')}>
                                    <UserPlus className="h-4 w-4" />
                                    Register Walk-in
                                </Link>
                            </Button>
                        </Can>
                    }
                />

                <div className="flex flex-col gap-3">
                    <form onSubmit={submitSearch} className="flex gap-2">
                        <div className="relative flex-1">
                            <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search by visit number, patient name, or number"
                                className="pl-9"
                                aria-label="Search visits"
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
                        <Input
                            type="date"
                            className="w-[160px]"
                            value={filters.date || ''}
                            onChange={(e) => applyFilters({ date: e.target.value })}
                            aria-label="Date"
                        />

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
                                {Object.entries(receptionStatusLabel).map(([value, label]) => (
                                    <SelectItem key={value} value={value}>
                                        {label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.visit_type || 'all'}
                            onValueChange={(value) => applyFilters({ visit_type: value === 'all' ? '' : value })}
                        >
                            <SelectTrigger className="w-[160px]">
                                <SelectValue placeholder="All types" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All types</SelectItem>
                                {Object.entries(VISIT_TYPE_LABELS).map(([value, label]) => (
                                    <SelectItem key={value} value={value}>
                                        {label}
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
                </div>

                <div>
                    <p className="text-muted-foreground mb-2 text-sm">
                        {visits.total > 0
                            ? `Showing ${visits.from ?? 0}–${visits.to ?? 0} of ${visits.total} visit${visits.total === 1 ? '' : 's'}`
                            : null}
                    </p>

                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Visit No</TableHead>
                                <TableHead>Patient</TableHead>
                                <TableHead>Department</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Created</TableHead>
                                <TableHead>Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {visits.data.map((visit) => (
                                <TableRow key={visit.id}>
                                    <TableCell>
                                        <Link href={route('reception.visits.show', visit.id)} className="font-medium hover:underline">
                                            {visit.visit_number}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        {visit.patient
                                            ? [visit.patient.first_name, visit.patient.middle_name, visit.patient.last_name].filter(Boolean).join(' ')
                                            : '—'}
                                    </TableCell>
                                    <TableCell>{visit.department?.name ?? '—'}</TableCell>
                                    <TableCell>{VISIT_TYPE_LABELS[visit.visit_type] ?? visit.visit_type}</TableCell>
                                    <TableCell>
                                        <Badge variant={receptionStatusVariant[visit.status]}>{receptionStatusLabel[visit.status]}</Badge>
                                    </TableCell>
                                    <TableCell>
                                        {new Date(visit.created_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}
                                    </TableCell>
                                    <TableCell>
                                        <Button variant="ghost" size="sm" asChild>
                                            <Link href={route('reception.visits.show', visit.id)}>View</Link>
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}

                            {visits.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={7} className="p-0">
                                        <EmptyState
                                            icon={ClipboardList}
                                            title={hasFilters ? 'No visits match your search or filters' : 'No visits found'}
                                            description={hasFilters ? 'Try a different search term or clear your filters.' : undefined}
                                        />
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>

                <Pagination links={visits.links} />
            </div>
        </AppLayout>
    );
}
