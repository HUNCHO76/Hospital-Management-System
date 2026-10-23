import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { prescriptionVerificationStatusLabel, prescriptionVerificationStatusVariant } from '@/lib/prescription-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import {
    type PrescriptionVerificationFilters,
    type PrescriptionVerificationOrder,
    type PrescriptionVerificationStatus,
} from '@/types/prescription';
import { Head, Link, router } from '@inertiajs/react';
import { AlertCircle, ShieldCheck } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pharmacy', href: '/pharmacy' },
    { title: 'Verification', href: '/pharmacy/prescriptions/verification' },
];

interface PharmacyVerificationIndexProps {
    prescriptions: Paginated<PrescriptionVerificationOrder>;
    filters: PrescriptionVerificationFilters;
    statuses: PrescriptionVerificationStatus[];
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

function medicationSummary(order: PrescriptionVerificationOrder): string {
    return order.items.map((item) => item.medication_name_snapshot).join(', ') || '—';
}

/**
 * The pharmacy verification worklist (Phase 6, Step 47, spec §10): prescription
 * orders awaiting independent pharmacy review, sorted by submission time.
 * Defaults to the actionable PENDING_VERIFICATION queue; the status filter
 * can widen it to review already-decided prescriptions (VERIFIED/RETURNED)
 * for reference. Mirrors RadiologyVerificationIndex's structure exactly.
 */
export default function PharmacyVerificationIndex({ prescriptions, filters, statuses }: PharmacyVerificationIndexProps) {
    const applyFilters = (overrides: Partial<PrescriptionVerificationFilters>) => {
        const params: Record<string, string> = { ...filters, ...overrides };
        router.get(route('pharmacy-verification.index'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(filters.search || filters.status || filters.date_from || filters.date_to);

    const patientName = (order: PrescriptionVerificationOrder): string =>
        order.patient ? [order.patient.first_name, order.patient.middle_name, order.patient.last_name].filter(Boolean).join(' ') : '—';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Prescription Verification" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Prescription Verification</h1>
                    <p className="text-muted-foreground text-sm">Prescriptions awaiting independent pharmacy review before dispensing.</p>
                </div>

                <div className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="button" size="sm" variant="outline" onClick={() => applyFilters({ status: '' })}>
                            Pending Verification
                        </Button>
                        <Button type="button" size="sm" variant="outline" onClick={() => applyFilters({ status: 'verified' })}>
                            Verified
                        </Button>
                        <Button type="button" size="sm" variant="outline" onClick={() => applyFilters({ status: 'returned' })}>
                            Returned
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
                                <SelectValue placeholder="Pending Verification" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Pending Verification</SelectItem>
                                {statuses.map((status) => (
                                    <SelectItem key={status} value={status}>
                                        {prescriptionVerificationStatusLabel[status]}
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
                            <Button variant="ghost" size="sm" onClick={() => router.get(route('pharmacy-verification.index'))}>
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
                            <TableHead>Submitted</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Payment</TableHead>
                            <TableHead className="text-right">Action</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {prescriptions.data.map((order) => {
                            const paymentRequired = order.financial_status === 'payment_required';
                            const isNhifPending = order.financial_status === 'nhif_pending_request';
                            const isNhifSentNotIssued = order.financial_status === 'nhif_sent_not_issued';
                            const isBlocked = paymentRequired || isNhifPending || isNhifSentNotIssued;

                            return (
                            <TableRow key={order.id} className={isBlocked ? 'bg-amber-500/5' : undefined}>
                                <TableCell>{order.prescription_number}</TableCell>
                                <TableCell>
                                    <div className="flex flex-col">
                                        <span>{patientName(order)}</span>
                                        {order.patient && <span className="text-muted-foreground text-xs">{order.patient.patient_number}</span>}
                                    </div>
                                </TableCell>
                                <TableCell>{order.prescriber?.name ?? '—'}</TableCell>
                                <TableCell>{medicationSummary(order)}</TableCell>
                                <TableCell>{formatDateTime(order.verification?.submitted_at ?? null)}</TableCell>
                                <TableCell>
                                    {order.verification && (
                                        <Badge variant={prescriptionVerificationStatusVariant[order.verification.status]}>
                                            {prescriptionVerificationStatusLabel[order.verification.status]}
                                        </Badge>
                                    )}
                                </TableCell>
                                <TableCell>
                                    {isNhifSentNotIssued ? (
                                        <Badge variant="outline" className="gap-1 border-amber-500 bg-amber-500/10 text-amber-700 dark:text-amber-400 font-medium whitespace-nowrap">
                                            <AlertCircle className="h-3 w-3" />
                                            Request sent but not issued
                                        </Badge>
                                    ) : isNhifPending ? (
                                        <Badge variant="outline" className="gap-1 border-amber-500 bg-amber-500/10 text-amber-700 dark:text-amber-400 font-medium whitespace-nowrap">
                                            <AlertCircle className="h-3 w-3" />
                                            Pre-approval Required
                                        </Badge>
                                    ) : paymentRequired ? (
                                        <Badge variant="destructive" className="gap-1 bg-amber-600 dark:bg-amber-700">
                                            <AlertCircle className="h-3 w-3" />
                                            Unpaid
                                        </Badge>
                                    ) : (
                                        <Badge variant="outline" className="border-green-500/50 text-green-700 dark:text-green-400">
                                            Cleared
                                        </Badge>
                                    )}
                                </TableCell>
                                <TableCell className="text-right">
                                    <Button asChild size="sm" variant={isBlocked ? 'outline' : 'default'}>
                                        <Link href={route('pharmacy-verification.review', order.id)}>
                                            {order.verification?.status === 'pending_verification' && !isBlocked ? 'Review' : 'View'}
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
                                        icon={ShieldCheck}
                                        title={hasFilters ? 'No prescriptions match your search or filters' : 'Nothing awaiting verification'}
                                        description={
                                            hasFilters
                                                ? 'Try a different search term or clear your filters.'
                                                : 'Submitted prescriptions will appear here for independent review.'
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
