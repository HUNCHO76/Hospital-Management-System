import { Can } from '@/components/authorization/can';
import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { medicationFormLabel, medicationStatusVariant } from '@/lib/medication-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type Medication, type MedicationFilters, type MedicationForm } from '@/types/medication';
import { Head, Link, router } from '@inertiajs/react';
import { Pill, Plus } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pharmacy', href: '/pharmacy' },
    { title: 'Medications', href: '/pharmacy/medications' },
];

interface MedicationsIndexProps {
    medications: Paginated<Medication>;
    filters: MedicationFilters;
    categories: string[];
    forms: MedicationForm[];
}

export default function MedicationsIndex({ medications, filters, categories, forms }: MedicationsIndexProps) {
    const { can } = usePermissions();
    const [search, setSearch] = useState(filters.search ?? '');

    const applyFilters = (overrides: Partial<MedicationFilters>) => {
        router.get(
            route('medications.index'),
            {
                search,
                category: filters.category ?? '',
                form: filters.form ?? '',
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

    const hasFilters = Boolean(filters.search || filters.category || filters.form || filters.status);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Medication Catalog" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Medication Catalog</h1>
                        <p className="text-muted-foreground text-sm">The hospital's authoritative medication definitions.</p>
                    </div>

                    <Can permission="medications.create">
                        <Button asChild>
                            <Link href={route('medications.create')}>
                                <Plus className="h-4 w-4" />
                                Add Medication
                            </Link>
                        </Button>
                    </Can>
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    <form onSubmit={submitSearch} className="flex min-w-[240px] flex-1 gap-2">
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search by code, generic, or brand name" />
                        <Button type="submit" variant="secondary">
                            Search
                        </Button>
                    </form>

                    <Select value={filters.category || 'all'} onValueChange={(value) => applyFilters({ category: value === 'all' ? '' : value })}>
                        <SelectTrigger className="w-[180px]">
                            <SelectValue placeholder="All categories" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All categories</SelectItem>
                            {categories.map((category) => (
                                <SelectItem key={category} value={category}>
                                    {category}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select value={filters.form || 'all'} onValueChange={(value) => applyFilters({ form: value === 'all' ? '' : value })}>
                        <SelectTrigger className="w-[160px]">
                            <SelectValue placeholder="All forms" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All forms</SelectItem>
                            {forms.map((form) => (
                                <SelectItem key={form} value={form}>
                                    {medicationFormLabel[form]}
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
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                        </SelectContent>
                    </Select>

                    {hasFilters && (
                        <Button
                            variant="ghost"
                            onClick={() => {
                                setSearch('');
                                router.get(route('medications.index'));
                            }}
                        >
                            Clear filters
                        </Button>
                    )}
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Code</TableHead>
                            <TableHead>Generic Name</TableHead>
                            <TableHead>Brand Name</TableHead>
                            <TableHead>Strength</TableHead>
                            <TableHead>Form</TableHead>
                            <TableHead>Category</TableHead>
                            <TableHead>Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {medications.data.map((medication) => (
                            <TableRow key={medication.id}>
                                <TableCell>
                                    <Link href={route('medications.show', medication.id)} className="font-medium hover:underline">
                                        {medication.code}
                                    </Link>
                                </TableCell>
                                <TableCell>{medication.generic_name}</TableCell>
                                <TableCell>{medication.brand_name || '—'}</TableCell>
                                <TableCell>{medication.strength || '—'}</TableCell>
                                <TableCell>{medication.form ? medicationFormLabel[medication.form] : '—'}</TableCell>
                                <TableCell>{medication.category || '—'}</TableCell>
                                <TableCell>
                                    <Badge variant={medicationStatusVariant[medication.status]}>
                                        {medication.status === 'active' ? 'Active' : 'Inactive'}
                                    </Badge>
                                </TableCell>
                            </TableRow>
                        ))}

                        {medications.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={7} className="p-0">
                                    <EmptyState
                                        icon={Pill}
                                        title={hasFilters ? 'No medications match your search or filters' : 'No medications configured yet'}
                                        description={
                                            hasFilters
                                                ? 'Try a different search term or clear your filters.'
                                                : "Add the hospital's first medication to the catalog."
                                        }
                                        action={
                                            !hasFilters && can('medications.create') ? (
                                                <Button asChild size="sm">
                                                    <Link href={route('medications.create')}>
                                                        <Plus className="h-4 w-4" />
                                                        Add Medication
                                                    </Link>
                                                </Button>
                                            ) : undefined
                                        }
                                    />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>

                <Pagination links={medications.links} />
            </div>
        </AppLayout>
    );
}
