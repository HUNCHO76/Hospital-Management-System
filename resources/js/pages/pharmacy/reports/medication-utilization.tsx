import { ReportExportButton } from '@/components/pharmacy/report-export-button';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type MedicationUtilizationFilters, type MedicationUtilizationRow } from '@/types/pharmacy-report';
import { Head, router } from '@inertiajs/react';
import { LineChart } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pharmacy', href: '/pharmacy' },
    { title: 'Reports', href: '/pharmacy/reports' },
    { title: 'Medication Utilization', href: '/pharmacy/reports/medication-utilization' },
];

interface MedicationUtilizationReportProps {
    rows: MedicationUtilizationRow[];
    filters: MedicationUtilizationFilters;
}

/**
 * Medication consumption over a selected period (Phase 6, Step 50, spec
 * §11) — quantity actually dispensed (never merely prescribed), grouped by
 * medication and by day/week/month. A pure read of the dispensing ledger;
 * no clinical-effectiveness claim is made or implied.
 */
export default function MedicationUtilizationReport({ rows, filters }: MedicationUtilizationReportProps) {
    const applyFilters = (overrides: Partial<MedicationUtilizationFilters>) => {
        const params: Record<string, string | undefined> = { ...filters, ...overrides };
        router.get(route('pharmacy-reports.medication-utilization'), params, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(filters.date_from || filters.date_to);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Medication Utilization Report" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Medication Utilization</h1>
                        <p className="text-muted-foreground text-sm">Quantity actually dispensed, grouped by medication and period.</p>
                    </div>
                    <ReportExportButton routeName="pharmacy-reports.medication-utilization" filters={filters} />
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Select value={filters.group_by} onValueChange={(value) => applyFilters({ group_by: value as MedicationUtilizationFilters['group_by'] })}>
                        <SelectTrigger className="w-[140px]">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="day">By Day</SelectItem>
                            <SelectItem value="week">By Week</SelectItem>
                            <SelectItem value="month">By Month</SelectItem>
                        </SelectContent>
                    </Select>
                    <Input type="date" value={filters.date_from || ''} onChange={(e) => applyFilters({ date_from: e.target.value })} className="w-[160px]" />
                    <Input type="date" value={filters.date_to || ''} onChange={(e) => applyFilters({ date_to: e.target.value })} className="w-[160px]" />

                    {hasFilters && (
                        <Button variant="ghost" size="sm" onClick={() => applyFilters({ date_from: '', date_to: '' })}>
                            Clear dates
                        </Button>
                    )}
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Medication</TableHead>
                            <TableHead>Period</TableHead>
                            <TableHead>Total Quantity</TableHead>
                            <TableHead>Dispensing Events</TableHead>
                            <TableHead>Prescriptions</TableHead>
                            <TableHead>Patients</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row, index) => (
                            <TableRow key={`${row.medication_name}-${row.period}-${index}`}>
                                <TableCell>{row.medication_name}</TableCell>
                                <TableCell>{row.period}</TableCell>
                                <TableCell className="font-medium">{row.total_quantity}</TableCell>
                                <TableCell>{row.dispensing_events}</TableCell>
                                <TableCell>{row.prescription_count}</TableCell>
                                <TableCell>{row.patient_count}</TableCell>
                            </TableRow>
                        ))}

                        {rows.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={6} className="p-0">
                                    <EmptyState icon={LineChart} title="No dispensing recorded" description="Nothing was dispensed in the selected period." />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </div>
        </AppLayout>
    );
}
