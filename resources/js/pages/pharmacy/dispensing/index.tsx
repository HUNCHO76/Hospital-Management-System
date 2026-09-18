import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { fulfillmentStatusLabel, fulfillmentStatusVariant } from '@/lib/dispensing-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type DispensingFilters, type DispensingPrescription } from '@/types/dispensing';
import { Head, Link, router } from '@inertiajs/react';
import { AlertCircle, PillBottle } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pharmacy', href: '/pharmacy' },
    { title: 'Dispensing', href: '/pharmacy/dispensing' },
];

interface PharmacyDispensingIndexProps {
    prescriptions: Paginated<DispensingPrescription>;
    filters: DispensingFilters;
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

function medicationSummary(prescription: DispensingPrescription): string {
    return prescription.items.map((item) => item.medication_name_snapshot).join(', ') || '—';
}

/**
 * The pharmacy dispensing queue (Phase 6, Step 48, spec §12): verified
 * prescriptions still awaiting full fulfillment, sorted by verification
 * time. Defaults to the actionable queue (anything not yet fully
 * dispensed); the status filter can widen it to review completed
 * dispensing for reference. Mirrors PharmacyVerificationIndex's structure
 * exactly.
 */
export default function PharmacyDispensingIndex({ prescriptions, filters }: PharmacyDispensingIndexProps) {
    const applyFilters = (overrides: Partial<DispensingFilters>) => {
        const params: Record<string, string> = { ...filters, ...overrides };
        router.get(route('pharmacy-dispensing.index'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(filters.search || filters.status || filters.date_from || filters.date_to);

    const patientName = (prescription: DispensingPrescription): string =>
        prescription.patient
            ? [prescription.patient.first_name, prescription.patient.middle_name, prescription.patient.last_name].filter(Boolean).join(' ')
            : '—';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pharmacy Dispensing" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Dispensing</h1>
                    <p className="text-muted-foreground text-sm">Verified prescriptions ready for pharmacy fulfillment.</p>
                </div>

                <div className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="button" size="sm" variant="outline" onClick={() => applyFilters({ status: '' })}>
                            Awaiting Dispensing
                        </Button>
                        <Button type="button" size="sm" variant="outline" onClick={() => applyFilters({ status: 'dispensed' })}>
                            Fully Dispensed
                        </Button>
                    </div>

                    <Input
                        value={filters.search || ''}
                        onChange={(e) => applyFilters({ search: e.target.value })}
                        placeholder="Search by prescription number, medication, or patient"
                        aria-label="Search prescriptions"
                        className="max-w-sm"
                    />

                    <div className="flex flex-wrap items-center gap-2">
                        <Select value={filters.status || 'all'} onValueChange={(value) => applyFilters({ status: value === 'all' ? '' : value })}>
                            <SelectTrigger className="w-[180px]">
                                <SelectValue placeholder="Awaiting Dispensing" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Awaiting Dispensing</SelectItem>
                                <SelectItem value="dispensed">Fully Dispensed</SelectItem>
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
                            <Button variant="ghost" size="sm" onClick={() => router.get(route('pharmacy-dispensing.index'))}>
                                Clear filters
                            </Button>
                        )}
                    </div>
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Prescription #</TableHead>
                            <TableHead>Patient</TableHead>
                            <TableHead>Prescriber</TableHead>
                            <TableHead>Medications</TableHead>
                            <TableHead>Verified</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Payment</TableHead>
                            <TableHead className="text-right">Action</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {prescriptions.data.map((prescription) => {
                            const paymentRequired = prescription.financial_status === 'payment_required';
                            return (
                            <TableRow key={prescription.id} className={paymentRequired ? 'bg-amber-500/5' : undefined}>
                                <TableCell>{prescription.prescription_number}</TableCell>
                                <TableCell>
                                    <div className="flex flex-col">
                                        <span>{patientName(prescription)}</span>
                                        {prescription.patient && (
                                            <span className="text-muted-foreground text-xs">{prescription.patient.patient_number}</span>
                                        )}
                                    </div>
                                </TableCell>
                                <TableCell>{prescription.prescriber?.name ?? '—'}</TableCell>
                                <TableCell>{medicationSummary(prescription)}</TableCell>
                                <TableCell>{formatDateTime(prescription.verification?.verified_at ?? null)}</TableCell>
                                <TableCell>
                                    <Badge variant={fulfillmentStatusVariant[prescription.fulfillment_status]}>
                                        {fulfillmentStatusLabel[prescription.fulfillment_status]}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    {paymentRequired ? (
                                        <Badge variant="destructive" className="gap-1 bg-amber-600 dark:bg-amber-700">
                                            <AlertCircle className="h-3 w-3" />
                                            Unpaid
                                        </Badge>
                                    ) : (
                                        <Badge variant="outline" className="text-green-700 border-green-500/50 dark:text-green-400">
                                            Cleared
                                        </Badge>
                                    )}
                                </TableCell>
                                <TableCell className="text-right">
                                    <Button asChild size="sm" variant={paymentRequired ? 'outline' : 'default'}>
                                        <Link href={route('pharmacy-dispensing.show', prescription.id)}>
                                            {prescription.fulfillment_status === 'dispensed' ? 'View' : paymentRequired ? 'View' : 'Dispense'}
                                        </Link>
                                    </Button>
                                </TableCell>
                            </TableRow>
                            );
                        })}

                        {prescriptions.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={8} className="p-0">
                                    <EmptyState
                                        icon={PillBottle}
                                        title={hasFilters ? 'No prescriptions match your search or filters' : 'Nothing awaiting dispensing'}
                                        description={
                                            hasFilters
                                                ? 'Try a different search term or clear your filters.'
                                                : 'Verified prescriptions will appear here ready for pharmacy fulfillment.'
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
