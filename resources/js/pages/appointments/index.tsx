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
import { appointmentStatusLabel, appointmentStatusVariant } from '@/lib/appointment-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type Appointment, type AppointmentDepartment, type AppointmentFilters, type AppointmentPractitioner } from '@/types/appointment';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ArrowUpDown, CalendarDays, CalendarPlus, Search } from 'lucide-react';
import { type FormEventHandler, useEffect, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Appointments', href: '/appointments' }];

const sortableColumns: { key: string; label: string }[] = [
    { key: 'scheduled_start', label: 'Date & Time' },
    { key: 'status', label: 'Status' },
];

interface AppointmentsIndexProps {
    appointments: Paginated<Appointment>;
    filters: AppointmentFilters;
    departments: AppointmentDepartment[];
    practitioners: AppointmentPractitioner[];
}

export default function AppointmentsIndex({ appointments, filters, departments, practitioners }: AppointmentsIndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [isSearching, setIsSearching] = useState(false);
    const debouncedSearch = useDebouncedValue(search, 350);
    const isFirstRender = useRef(true);

    const allFilterKeys: (keyof AppointmentFilters)[] = ['search', 'department_id', 'practitioner_id', 'status', 'date_from', 'date_to', 'sort'];

    const applyFilters = (overrides: Partial<AppointmentFilters>) => {
        const params: Record<string, string> = {};
        allFilterKeys.forEach((key) => {
            params[key] = (filters[key] as string) ?? '';
        });
        params.search = search;
        Object.assign(params, overrides);

        setIsSearching(true);
        router.get(route('appointments.index'), params, {
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
        router.get(route('appointments.index'), {}, { onFinish: () => setIsSearching(false) });
    };

    const toggleSort = (column: string) => {
        const current = filters.sort ?? '';
        const next = current === column ? `-${column}` : column;
        applyFilters({ sort: next });
    };

    const sortIcon = (column: string) => {
        if (filters.sort === column) {
            return <ArrowUp className="h-3.5 w-3.5" />;
        }
        if (filters.sort === `-${column}`) {
            return <ArrowDown className="h-3.5 w-3.5" />;
        }

        return <ArrowUpDown className="text-muted-foreground/50 h-3.5 w-3.5" />;
    };

    const hasFilters = Boolean(
        filters.search || filters.department_id || filters.practitioner_id || filters.status || filters.date_from || filters.date_to,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Appointments" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Appointments</h1>
                        <p className="text-muted-foreground text-sm">Search, filter, and manage patient appointments.</p>
                    </div>

                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href={route('appointments.calendar')}>
                                <CalendarDays className="h-4 w-4" />
                                Calendar
                            </Link>
                        </Button>
                        <Can permission="appointments.create">
                            <Button asChild>
                                <Link href={route('appointments.create')}>
                                    <CalendarPlus className="h-4 w-4" />
                                    Book Appointment
                                </Link>
                            </Button>
                        </Can>
                    </div>
                </div>

                <div className="flex flex-col gap-3">
                    <form onSubmit={submitSearch} className="flex gap-2">
                        <div className="relative flex-1">
                            <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search by appointment number, patient name, number, or phone"
                                className="pl-9"
                                aria-label="Search appointments"
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

                        <Select value={filters.status || 'all'} onValueChange={(value) => applyFilters({ status: value === 'all' ? '' : value })}>
                            <SelectTrigger className="w-[160px]">
                                <SelectValue placeholder="All statuses" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All statuses</SelectItem>
                                {Object.entries(appointmentStatusLabel).map(([value, label]) => (
                                    <SelectItem key={value} value={value}>
                                        {label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

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
                        {appointments.total > 0
                            ? `Showing ${appointments.from ?? 0}–${appointments.to ?? 0} of ${appointments.total} appointment${appointments.total === 1 ? '' : 's'}`
                            : null}
                    </p>

                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Appointment No</TableHead>
                                <TableHead>Patient</TableHead>
                                {sortableColumns.map((column) => (
                                    <TableHead key={column.key}>
                                        <button
                                            type="button"
                                            onClick={() => toggleSort(column.key)}
                                            className="hover:text-foreground flex items-center gap-1 font-medium"
                                        >
                                            {column.label}
                                            {sortIcon(column.key)}
                                        </button>
                                    </TableHead>
                                ))}
                                <TableHead>Practitioner</TableHead>
                                <TableHead>Department</TableHead>
                                <TableHead>Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {appointments.data.map((appointment) => (
                                <TableRow key={appointment.id}>
                                    <TableCell>
                                        <Link href={route('appointments.show', appointment.id)} className="font-medium hover:underline">
                                            {appointment.appointment_number}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        {appointment.patient
                                            ? [appointment.patient.first_name, appointment.patient.middle_name, appointment.patient.last_name]
                                                  .filter(Boolean)
                                                  .join(' ')
                                            : '—'}
                                    </TableCell>
                                    <TableCell>
                                        {new Date(appointment.scheduled_start).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}
                                    </TableCell>
                                    <TableCell>
                                        <Badge variant={appointmentStatusVariant[appointment.status]}>
                                            {appointmentStatusLabel[appointment.status]}
                                        </Badge>
                                    </TableCell>
                                    <TableCell>
                                        {appointment.practitioner?.staff
                                            ? `${appointment.practitioner.staff.first_name} ${appointment.practitioner.staff.last_name}`
                                            : '—'}
                                    </TableCell>
                                    <TableCell>{appointment.department?.name ?? '—'}</TableCell>
                                    <TableCell>
                                        <Button variant="ghost" size="sm" asChild>
                                            <Link href={route('appointments.show', appointment.id)}>View</Link>
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}

                            {appointments.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={7} className="p-0">
                                        <EmptyState
                                            icon={CalendarDays}
                                            title={hasFilters ? 'No appointments match your search or filters' : 'No appointments found'}
                                            description={
                                                hasFilters
                                                    ? 'Try a different search term or clear your filters.'
                                                    : 'Get started by booking the first appointment.'
                                            }
                                            action={
                                                !hasFilters ? (
                                                    <Can permission="appointments.create">
                                                        <Button asChild size="sm">
                                                            <Link href={route('appointments.create')}>
                                                                <CalendarPlus className="h-4 w-4" />
                                                                Book Appointment
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

                <Pagination links={appointments.links} />
            </div>
        </AppLayout>
    );
}
