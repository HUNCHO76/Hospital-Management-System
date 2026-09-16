import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { prescriptionOrderStatusLabel, prescriptionOrderStatusVariant } from '@/lib/prescription-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type PrescriptionOrder, type PrescriptionOrderFilters, type PrescriptionOrderStatus } from '@/types/prescription';
import { Head, Link, router } from '@inertiajs/react';
import { Pill } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pharmacy', href: '/pharmacy' },
    { title: 'Prescriptions', href: '/pharmacy/prescriptions' },
];

interface PharmacyPrescriptionsIndexProps {
    prescriptions: Paginated<PrescriptionOrder>;
    filters: PrescriptionOrderFilters;
    statuses: PrescriptionOrderStatus[];
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

function medicationSummary(order: PrescriptionOrder): string {
    return order.items.map((item) => item.medication_name_snapshot).join(', ') || '—';
}

/**
 * The pharmacy's cross-encounter prescriptions worklist (Phase 6, Step 44,
 * extended in Step 46) — reads the first-class PrescriptionOrder table.
 * Read-only at this step — no verify/dispense actions exist yet (Steps
 * 47-48).
 */
export default function PharmacyPrescriptionsIndex({ prescriptions, filters, statuses }: PharmacyPrescriptionsIndexProps) {
    const applyFilters = (overrides: Partial<PrescriptionOrderFilters>) => {
        const params: Record<string, string> = { ...filters, ...overrides };
        router.get(route('pharmacy.prescriptions.index'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(filters.search || filters.status || filters.date_from || filters.date_to);

    const patientName = (prescription: PrescriptionOrder): string =>
        prescription.patient
            ? [prescription.patient.first_name, prescription.patient.middle_name, prescription.patient.last_name].filter(Boolean).join(' ')
            : '—';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pharmacy Prescriptions" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Prescriptions</h1>
                    <p className="text-muted-foreground text-sm">Every prescription written across all clinical encounters.</p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        value={filters.search || ''}
                        onChange={(e) => applyFilters({ search: e.target.value })}
                        placeholder="Search by patient, medication, prescription, or encounter number"
                        aria-label="Search prescriptions"
                        className="max-w-sm"
                    />
                    <Select value={filters.status || 'all'} onValueChange={(value) => applyFilters({ status: value === 'all' ? '' : value })}>
                        <SelectTrigger className="w-[160px]">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            {statuses.map((status) => (
                                <SelectItem key={status} value={status}>
                                    {prescriptionOrderStatusLabel[status]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Input
                        type="date"
                        value={filters.date_from || ''}
                        onChange={(e) => applyFilters({ date_from: e.target.value })}
                        className="w-[160px]"
                        aria-label="From date"
                    />
                    <Input
                        type="date"
                        value={filters.date_to || ''}
                        onChange={(e) => applyFilters({ date_to: e.target.value })}
                        className="w-[160px]"
                        aria-label="To date"
                    />

                    {hasFilters && (
                        <Button variant="ghost" size="sm" onClick={() => router.get(route('pharmacy.prescriptions.index'))}>
                            Clear filters
                        </Button>
                    )}
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Number</TableHead>
                            <TableHead>Patient</TableHead>
                            <TableHead>Medications</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Department</TableHead>
                            <TableHead>Encounter</TableHead>
                            <TableHead>Prescribed</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {prescriptions.data.map((prescription) => (
                            <TableRow key={prescription.id}>
                                <TableCell>
                                    <Link href={route('pharmacy.prescriptions.show', prescription.id)} className="hover:underline">
                                        {prescription.prescription_number}
                                    </Link>
                                </TableCell>
                                <TableCell>
                                    <div className="flex flex-col">
                                        <span>{patientName(prescription)}</span>
                                        {prescription.patient && (
                                            <span className="text-muted-foreground text-xs">{prescription.patient.patient_number}</span>
                                        )}
                                    </div>
                                </TableCell>
                                <TableCell>{medicationSummary(prescription)}</TableCell>
                                <TableCell>
                                    <Badge variant={prescriptionOrderStatusVariant[prescription.status]}>
                                        {prescriptionOrderStatusLabel[prescription.status]}
                                    </Badge>
                                </TableCell>
                                <TableCell>{prescription.department?.name ?? '—'}</TableCell>
                                <TableCell>
                                    {prescription.encounter ? (
                                        <Link href={route('clinical-encounters.show', prescription.encounter.id)} className="hover:underline">
                                            {prescription.encounter.encounter_number}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </TableCell>
                                <TableCell>{formatDateTime(prescription.prescribed_at)}</TableCell>
                            </TableRow>
                        ))}

                        {prescriptions.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={7} className="p-0">
                                    <EmptyState
                                        icon={Pill}
                                        title={hasFilters ? 'No prescriptions match your search or filters' : 'No prescriptions yet'}
                                        description={
                                            hasFilters
                                                ? 'Try a different search term or clear your filters.'
                                                : 'Prescriptions appear here once a doctor prescribes medication from a clinical encounter.'
                                        }
                                    />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>

                <Pagination links={prescriptions.links} />
            </div>
        </AppLayout>
    );
}
