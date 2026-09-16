import { ReportExportButton } from '@/components/pharmacy/report-export-button';
import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type PaginationLink } from '@/types';
import { type ReconciliationRow } from '@/types/pharmacy-report';
import { Head, router } from '@inertiajs/react';
import { Scale } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pharmacy', href: '/pharmacy' },
    { title: 'Reports', href: '/pharmacy/reports' },
    { title: 'Stock Reconciliation', href: '/pharmacy/reports/reconciliation' },
];

interface ReconciliationReportProps {
    rows: ReconciliationRow[];
    links: PaginationLink[];
    filters: { search?: string };
}

/**
 * Stock reconciliation (Phase 6, Step 50, spec §15/§47) — each batch's
 * recorded quantity checked against its own immutable transaction ledger,
 * via StockService::reconcile() (never a second, parallel calculation of
 * the same check). A mismatch is only ever flagged, never silently
 * corrected — by construction it can only mean something outside
 * StockService wrote to a batch's quantity directly.
 */
export default function ReconciliationReport({ rows, links, filters }: ReconciliationReportProps) {
    const applyFilters = (search: string) => {
        router.get(route('pharmacy-reports.reconciliation'), { search }, { preserveState: true, replace: true });
    };

    const mismatches = rows.filter((row) => !row.reconciled).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stock Reconciliation Report" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Stock Reconciliation</h1>
                        <p className="text-muted-foreground text-sm">Each batch's recorded quantity checked against its own transaction ledger.</p>
                    </div>
                    <ReportExportButton routeName="pharmacy-reports.reconciliation" filters={filters} />
                </div>

                {mismatches > 0 && (
                    <div className="border-destructive/50 bg-destructive/5 rounded-md border p-3 text-sm">
                        <span className="text-destructive font-medium">{mismatches}</span> batch{mismatches === 1 ? '' : 'es'} on this page do not
                        reconcile — flagged for investigation, never auto-corrected.
                    </div>
                )}

                <Input
                    value={filters.search || ''}
                    onChange={(e) => applyFilters(e.target.value)}
                    placeholder="Search by medication or batch number"
                    className="max-w-sm"
                />

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Medication</TableHead>
                            <TableHead>Batch</TableHead>
                            <TableHead>Ledger Balance</TableHead>
                            <TableHead>Recorded Quantity</TableHead>
                            <TableHead>Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => (
                            <TableRow key={row.batch.id}>
                                <TableCell>{row.batch.medication?.generic_name ?? '—'}</TableCell>
                                <TableCell>{row.batch.batch_number}</TableCell>
                                <TableCell>{row.ledger_balance}</TableCell>
                                <TableCell>{row.recorded_quantity}</TableCell>
                                <TableCell>
                                    <Badge variant={row.reconciled ? 'default' : 'destructive'}>{row.reconciled ? 'OK' : 'Mismatch'}</Badge>
                                </TableCell>
                            </TableRow>
                        ))}

                        {rows.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={5} className="p-0">
                                    <EmptyState icon={Scale} title="No batches match your filters" description="Try a different search term." />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>

                <Pagination links={links} />
            </div>
        </AppLayout>
    );
}
