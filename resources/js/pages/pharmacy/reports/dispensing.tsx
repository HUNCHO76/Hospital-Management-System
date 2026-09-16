import { ReportExportButton } from '@/components/pharmacy/report-export-button';
import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type DispensingReportFilters, type DispensingReportRow } from '@/types/pharmacy-report';
import { Head, Link, router } from '@inertiajs/react';
import { PillBottle } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pharmacy', href: '/pharmacy' },
    { title: 'Reports', href: '/pharmacy/reports' },
    { title: 'Dispensing', href: '/pharmacy/reports/dispensing' },
];

interface DispensingReportProps {
    items: Paginated<DispensingReportRow>;
    filters: DispensingReportFilters;
    statuses: string[];
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

/**
 * Medications actually dispensed (Phase 6, Step 50, spec §13) — one row per
 * DispensingItem, so partial dispensing is represented correctly (each
 * event's own contribution, never a running total). Cancelled dispensing
 * events never appear here (they have no items); see the dashboard's own
 * "cancelled dispensing" KPI for those.
 */
export default function DispensingReport({ items, filters, statuses }: DispensingReportProps) {
    const applyFilters = (overrides: Partial<DispensingReportFilters>) => {
        const params: Record<string, string | undefined> = { ...filters, ...overrides };
        router.get(route('pharmacy-reports.dispensing'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(filters.search || filters.status || filters.date_from || filters.date_to);

    const patientName = (row: DispensingReportRow): string =>
        row.patient ? [row.patient.first_name, row.patient.middle_name, row.patient.last_name].filter(Boolean).join(' ') : '—';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dispensing Report" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Dispensing Report</h1>
                        <p className="text-muted-foreground text-sm">Every medication actually dispensed, with prescribed/dispensed quantities.</p>
                    </div>
                    <ReportExportButton routeName="pharmacy-reports.dispensing" filters={filters} />
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        value={filters.search || ''}
                        onChange={(e) => applyFilters({ search: e.target.value })}
                        placeholder="Search by prescription #, medication, or patient"
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
                                    {status === 'dispensed' ? 'Dispensed' : 'Unavailable'}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Input type="date" value={filters.date_from || ''} onChange={(e) => applyFilters({ date_from: e.target.value })} className="w-[160px]" />
                    <Input type="date" value={filters.date_to || ''} onChange={(e) => applyFilters({ date_to: e.target.value })} className="w-[160px]" />

                    {hasFilters && (
                        <Button variant="ghost" size="sm" onClick={() => router.get(route('pharmacy-reports.dispensing'))}>
                            Clear filters
                        </Button>
                    )}
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Dispensing #</TableHead>
                            <TableHead>Prescription</TableHead>
                            <TableHead>Patient</TableHead>
                            <TableHead>Medication</TableHead>
                            <TableHead>Prescribed</TableHead>
                            <TableHead>Dispensed</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Dispenser</TableHead>
                            <TableHead>When</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {items.data.map((row) => (
                            <TableRow key={row.id}>
                                <TableCell>{row.dispensing?.dispensing_number ?? '—'}</TableCell>
                                <TableCell>
                                    {row.prescription ? (
                                        <Link href={route('pharmacy.prescriptions.show', row.prescription.id)} className="hover:underline">
                                            {row.prescription.prescription_number}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </TableCell>
                                <TableCell>
                                    <div className="flex flex-col">
                                        <span>{patientName(row)}</span>
                                        {row.patient && <span className="text-muted-foreground text-xs">{row.patient.patient_number}</span>}
                                    </div>
                                </TableCell>
                                <TableCell>{row.medication_name ?? '—'}</TableCell>
                                <TableCell>{row.prescribed_quantity ?? '—'}</TableCell>
                                <TableCell>{row.dispensed_quantity}</TableCell>
                                <TableCell>
                                    <Badge variant={row.status === 'dispensed' ? 'default' : 'secondary'}>
                                        {row.status === 'dispensed' ? 'Dispensed' : 'Unavailable'}
                                    </Badge>
                                </TableCell>
                                <TableCell>{row.dispensing?.dispensed_by?.name ?? '—'}</TableCell>
                                <TableCell>{formatDateTime(row.dispensing?.completed_at ?? null)}</TableCell>
                            </TableRow>
                        ))}

                        {items.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={9} className="p-0">
                                    <EmptyState icon={PillBottle} title="No dispensing matches your filters" description="Try a different search term or clear your filters." />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>

                <Pagination links={items.links} />
            </div>
        </AppLayout>
    );
}
