import { ReportExportButton } from '@/components/pharmacy/report-export-button';
import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { prescriptionOrderStatusLabel, prescriptionOrderStatusVariant, prescriptionVerificationStatusLabel } from '@/lib/prescription-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type PrescriptionReportFilters, type PrescriptionReportRow } from '@/types/pharmacy-report';
import { type PrescriptionOrderStatus, type PrescriptionVerificationStatus } from '@/types/prescription';
import { Head, Link, router } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pharmacy', href: '/pharmacy' },
    { title: 'Reports', href: '/pharmacy/reports' },
    { title: 'Prescriptions', href: '/pharmacy/reports/prescriptions' },
];

interface PrescriptionsReportProps {
    prescriptions: Paginated<PrescriptionReportRow>;
    filters: PrescriptionReportFilters;
    statuses: PrescriptionOrderStatus[];
    verificationStatuses: PrescriptionVerificationStatus[];
}

const dispensingStatusLabel: Record<string, string> = {
    pending: 'Pending',
    partially_dispensed: 'Partially Dispensed',
    dispensed: 'Dispensed',
};

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleDateString() : '—';
}

/**
 * Every prescription with its verification and dispensing status (Phase 6,
 * Step 50, spec §12) — dispensing_status is computed via
 * DispensingService::fulfillmentStatus() server-side (spec §31), never
 * re-derived here.
 */
export default function PrescriptionsReport({ prescriptions, filters, statuses, verificationStatuses }: PrescriptionsReportProps) {
    const applyFilters = (overrides: Partial<PrescriptionReportFilters>) => {
        const params: Record<string, string | undefined> = { ...filters, ...overrides };
        router.get(route('pharmacy-reports.prescriptions'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(filters.search || filters.status || filters.verification_status || filters.date_from || filters.date_to);

    const patientName = (row: PrescriptionReportRow): string =>
        row.patient ? [row.patient.first_name, row.patient.middle_name, row.patient.last_name].filter(Boolean).join(' ') : '—';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Prescription Report" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Prescription Report</h1>
                        <p className="text-muted-foreground text-sm">Every prescription with its verification and dispensing status.</p>
                    </div>
                    <ReportExportButton routeName="pharmacy-reports.prescriptions" filters={filters} />
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        value={filters.search || ''}
                        onChange={(e) => applyFilters({ search: e.target.value })}
                        placeholder="Search by prescription # or patient"
                        className="max-w-sm"
                    />
                    <Select value={filters.status || 'all'} onValueChange={(value) => applyFilters({ status: value === 'all' ? '' : value })}>
                        <SelectTrigger className="w-[150px]">
                            <SelectValue placeholder="All statuses" />
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
                    <Select
                        value={filters.verification_status || 'all'}
                        onValueChange={(value) => applyFilters({ verification_status: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-[180px]">
                            <SelectValue placeholder="All verification" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All verification</SelectItem>
                            {verificationStatuses.map((status) => (
                                <SelectItem key={status} value={status}>
                                    {prescriptionVerificationStatusLabel[status]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Input type="date" value={filters.date_from || ''} onChange={(e) => applyFilters({ date_from: e.target.value })} className="w-[160px]" />
                    <Input type="date" value={filters.date_to || ''} onChange={(e) => applyFilters({ date_to: e.target.value })} className="w-[160px]" />

                    {hasFilters && (
                        <Button variant="ghost" size="sm" onClick={() => router.get(route('pharmacy-reports.prescriptions'))}>
                            Clear filters
                        </Button>
                    )}
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Prescription #</TableHead>
                            <TableHead>Date</TableHead>
                            <TableHead>Patient</TableHead>
                            <TableHead>Prescriber</TableHead>
                            <TableHead>Medications</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Verification</TableHead>
                            <TableHead>Dispensing</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {prescriptions.data.map((row) => (
                            <TableRow key={row.id}>
                                <TableCell>
                                    <Link href={route('pharmacy.prescriptions.show', row.id)} className="hover:underline">
                                        {row.prescription_number}
                                    </Link>
                                </TableCell>
                                <TableCell>{formatDate(row.prescribed_at)}</TableCell>
                                <TableCell>
                                    <div className="flex flex-col">
                                        <span>{patientName(row)}</span>
                                        {row.patient && <span className="text-muted-foreground text-xs">{row.patient.patient_number}</span>}
                                    </div>
                                </TableCell>
                                <TableCell>{row.prescriber?.name ?? '—'}</TableCell>
                                <TableCell>{row.medication_count ?? '—'}</TableCell>
                                <TableCell>
                                    <Badge variant={prescriptionOrderStatusVariant[row.status]}>{prescriptionOrderStatusLabel[row.status]}</Badge>
                                </TableCell>
                                <TableCell>
                                    {row.verification_status
                                        ? prescriptionVerificationStatusLabel[row.verification_status as PrescriptionVerificationStatus]
                                        : '—'}
                                </TableCell>
                                <TableCell>{row.dispensing_status ? dispensingStatusLabel[row.dispensing_status] : '—'}</TableCell>
                            </TableRow>
                        ))}

                        {prescriptions.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={8} className="p-0">
                                    <EmptyState icon={ClipboardList} title="No prescriptions match your filters" description="Try a different search term or clear your filters." />
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
