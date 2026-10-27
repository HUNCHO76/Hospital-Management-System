import { Can } from '@/components/authorization/can';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { PatientSearchFilters } from '@/components/patients/patient-search-filters';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { calculateAge, formatDate } from '@/lib/date';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type PatientFilters, type PatientListItem } from '@/types/patient';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ArrowUpDown, Plus, Search, UserRoundSearch, UserX } from 'lucide-react';
import { type FormEventHandler, useEffect, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Patients', href: '/patients' }];

interface PatientsIndexProps {
    patients: Paginated<PatientListItem>;
    filters: PatientFilters;
}

const statusVariant: Record<string, 'default' | 'secondary' | 'destructive'> = {
    active: 'default',
    inactive: 'secondary',
    deceased: 'destructive',
};

const sortableColumns: { key: string; label: string }[] = [
    { key: 'patient_number', label: 'Patient Number' },
    { key: 'last_name', label: 'Name' },
    { key: 'date_of_birth', label: 'Date of Birth' },
];

export default function PatientsIndex({ patients, filters }: PatientsIndexProps) {
    const { can } = usePermissions();
    const [search, setSearch] = useState(filters.search ?? '');
    const [isSearching, setIsSearching] = useState(false);
    const debouncedSearch = useDebouncedValue(search, 350);
    const isFirstRender = useRef(true);

    const allFilterKeys: (keyof PatientFilters)[] = [
        'search',
        'patient_number',
        'national_id',
        'passport_number',
        'phone',
        'date_of_birth',
        'gender',
        'status',
        'region',
        'district',
        'sort',
    ];

    const applyFilters = (overrides: Partial<PatientFilters>) => {
        const params: Record<string, string> = {};
        allFilterKeys.forEach((key) => {
            params[key] = (filters[key] as string) ?? '';
        });
        params.search = search;
        Object.assign(params, overrides);

        setIsSearching(true);
        router.get(route('patients.index'), params, {
            preserveState: true,
            replace: true,
            onFinish: () => setIsSearching(false),
        });
    };

    // Debounced auto-search as the user types, so results update without a
    // request on every keystroke.
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
        router.get(
            route('patients.index'),
            {},
            {
                onFinish: () => setIsSearching(false),
            },
        );
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
        filters.search ||
            filters.patient_number ||
            filters.national_id ||
            filters.passport_number ||
            filters.phone ||
            filters.date_of_birth ||
            filters.gender ||
            filters.status ||
            filters.region ||
            filters.district,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Patients" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Patients</h1>
                        <p className="text-muted-foreground text-sm">Search and identify registered patients.</p>
                    </div>

                    <Can permission="patients.create">
                        <Button asChild>
                            <Link href={route('patients.create')}>
                                <Plus className="h-4 w-4" />
                                Register Patient
                            </Link>
                        </Button>
                    </Can>
                </div>

                <div className="flex flex-col gap-3">
                    <form onSubmit={submitSearch} className="flex gap-2">
                        <div className="relative flex-1">
                            <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search by name, patient number, phone, or national ID"
                                className="pl-9"
                                aria-label="Search patients"
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

                    <PatientSearchFilters filters={filters} onApply={applyFilters} onClear={clearFilters} hasActiveFilters={hasFilters} />
                </div>

                <div>
                    <p className="text-muted-foreground mb-2 text-sm">
                        {patients.total > 0
                            ? `Showing ${patients.from ?? 0}–${patients.to ?? 0} of ${patients.total} patient${patients.total === 1 ? '' : 's'}`
                            : null}
                    </p>

                    <Table>
                        <TableHeader>
                            <TableRow>
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
                                <TableHead>Age</TableHead>
                                <TableHead>Gender</TableHead>
                                <TableHead>Phone</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Registered</TableHead>
                                <TableHead>Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {patients.data.map((patient) => (
                                <TableRow key={patient.id}>
                                    <TableCell>{patient.patient_number}</TableCell>
                                    <TableCell>
                                        <Link href={route('patients.show', patient.id)} className="font-medium hover:underline">
                                            {[patient.first_name, patient.middle_name, patient.last_name].filter(Boolean).join(' ')}
                                        </Link>
                                    </TableCell>
                                    <TableCell>{formatDate(patient.date_of_birth)}</TableCell>
                                    <TableCell>{calculateAge(patient.date_of_birth)}</TableCell>
                                    <TableCell className="capitalize">{patient.gender}</TableCell>
                                    <TableCell>{patient.phone ?? '—'}</TableCell>
                                    <TableCell>
                                        <Badge variant={statusVariant[patient.status]}>{patient.status}</Badge>
                                    </TableCell>
                                    <TableCell>{formatDate(patient.created_at)}</TableCell>
                                    <TableCell>
                                        <div className="flex items-center gap-1">
                                            <Button variant="ghost" size="icon" asChild>
                                                <Link href={route('patients.show', patient.id)} aria-label="View patient">
                                                    <UserRoundSearch className="h-4 w-4" />
                                                </Link>
                                            </Button>
                                            {can('patients.delete') && patient.status !== 'inactive' && (
                                                <ConfirmDeleteDialog
                                                    title="Deactivate patient"
                                                    description={`Are you sure you want to deactivate "${patient.first_name} ${patient.last_name}"? Their record and history will be preserved but they will no longer appear as an active patient.`}
                                                    deleteUrl={route('patients.destroy', patient.id)}
                                                    confirmLabel="Deactivate"
                                                    trigger={
                                                        <Button variant="ghost" size="icon" aria-label="Deactivate patient">
                                                            <UserX className="h-4 w-4" />
                                                        </Button>
                                                    }
                                                />
                                            )}
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}

                            {patients.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={9} className="p-0">
                                        <EmptyState
                                            icon={UserRoundSearch}
                                            title={hasFilters ? 'No patients match your search or filters' : 'No patients found'}
                                            description={
                                                hasFilters
                                                    ? 'Try a different name, patient number, phone, or identifier — or clear your filters.'
                                                    : 'Get started by registering the first patient.'
                                            }
                                            action={
                                                !hasFilters ? (
                                                    <Can permission="patients.create">
                                                        <Button asChild size="sm">
                                                            <Link href={route('patients.create')}>
                                                                <Plus className="h-4 w-4" />
                                                                Register Patient
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

                <Pagination links={patients.links} />
            </div>
        </AppLayout>
    );
}
