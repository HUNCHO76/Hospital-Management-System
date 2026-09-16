import { StatCard } from '@/components/dashboard/stat-card';
import { EmptyState } from '@/components/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { prescriptionOrderStatusLabel, prescriptionOrderStatusVariant } from '@/lib/prescription-status';
import { type BreadcrumbItem } from '@/types';
import { type PharmacyDashboardMetrics } from '@/types/pharmacy-report';
import { type PrescriptionOrder } from '@/types/prescription';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, BarChart3, Boxes, CalendarClock, ClipboardList, PackageX, Pill, PillBottle, ShieldAlert, ShieldCheck } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Pharmacy', href: '/pharmacy' }];

interface PharmacyIndexProps {
    activePrescriptionCount: number;
    activeMedicationCount: number;
    totalMedicationCount: number;
    recentPrescriptions: PrescriptionOrder[];
    /** Phase 6, Step 50 — only present for a user holding 'pharmacy-reports.view'. */
    metrics: PharmacyDashboardMetrics | null;
}

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}

function medicationSummary(order: PrescriptionOrder): string {
    return order.items.map((item) => item.medication_name_snapshot).join(', ') || '—';
}

/**
 * The Pharmacy dashboard (Phase 6, Step 44, extended in Step 46) — a landing
 * page with real counts (never fabricated statistics) and a short
 * recent-prescriptions preview, reading the first-class PrescriptionOrder
 * table. No "Pending Verification"/"Ready for Dispensing" widgets are shown
 * here — those live on their own dedicated worklists/dashboards
 * (pharmacy-verification, pharmacy-dispensing, and, since Step 49,
 * pharmacy-stock's own dashboard for low-stock/expiring/expired widgets).
 */
export default function PharmacyIndex({
    activePrescriptionCount,
    activeMedicationCount,
    totalMedicationCount,
    recentPrescriptions,
    metrics,
}: PharmacyIndexProps) {
    const { can } = usePermissions();

    const patientName = (prescription: PrescriptionOrder): string =>
        prescription.patient
            ? [prescription.patient.first_name, prescription.patient.middle_name, prescription.patient.last_name].filter(Boolean).join(' ')
            : '—';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pharmacy" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">Pharmacy</h1>
                        <p className="text-muted-foreground text-sm">Prescriptions written across all clinical encounters.</p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button asChild variant="outline">
                            <Link href={route('pharmacy.prescriptions.index')}>
                                <ClipboardList className="h-4 w-4" />
                                Prescriptions
                            </Link>
                        </Button>

                        {can('pharmacy-verification.view') && (
                            <Button asChild variant="outline">
                                <Link href={route('pharmacy-verification.index')}>
                                    <ShieldCheck className="h-4 w-4" />
                                    Verification
                                </Link>
                            </Button>
                        )}

                        {can('pharmacy-dispensing.view') && (
                            <Button asChild variant="outline">
                                <Link href={route('pharmacy-dispensing.index')}>
                                    <PillBottle className="h-4 w-4" />
                                    Dispensing
                                </Link>
                            </Button>
                        )}

                        {can('pharmacy-stock.view') && (
                            <Button asChild variant="outline">
                                <Link href={route('pharmacy-stock.index')}>
                                    <Boxes className="h-4 w-4" />
                                    Stock
                                </Link>
                            </Button>
                        )}

                        {can('medications.view') && (
                            <Button asChild variant="outline">
                                <Link href={route('medications.index')}>
                                    <PillBottle className="h-4 w-4" />
                                    Medications
                                </Link>
                            </Button>
                        )}

                        {can('pharmacy-reports.view') && (
                            <Button asChild variant="outline">
                                <Link href={route('pharmacy-reports.index')}>
                                    <BarChart3 className="h-4 w-4" />
                                    Reports
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <StatCard label="Active Prescriptions" value={activePrescriptionCount} icon={Pill} />
                    <StatCard label="Active Medications in Catalog" value={activeMedicationCount} icon={PillBottle} />
                    <StatCard label="Total Medications in Catalog" value={totalMedicationCount} icon={PillBottle} />
                </div>

                {metrics && (
                    <>
                        <div>
                            <h2 className="mb-3 text-lg font-semibold">Verification &amp; Dispensing</h2>
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <StatCard label="Pending Verification" value={metrics.verification.pending} icon={ShieldCheck} />
                                <StatCard label="Pending Dispensing" value={metrics.dispensing.pending} icon={PillBottle} />
                                <StatCard label="Partially Dispensed" value={metrics.dispensing.partially_dispensed} icon={PillBottle} />
                                <StatCard label="Fully Dispensed" value={metrics.dispensing.dispensed} icon={PillBottle} />
                            </div>
                        </div>

                        <div>
                            <h2 className="mb-3 text-lg font-semibold">Stock</h2>
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <StatCard label="Low Stock" value={metrics.stock.low_stock} icon={AlertTriangle} />
                                <StatCard label="Out of Stock" value={metrics.stock.out_of_stock} icon={PackageX} />
                                <StatCard label="Expiring Soon" value={metrics.stock.expiring_soon} icon={CalendarClock} />
                                <StatCard label="Expired" value={metrics.stock.expired} icon={ShieldAlert} />
                            </div>
                        </div>
                    </>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Recent Prescriptions</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Number</TableHead>
                                    <TableHead>Patient</TableHead>
                                    <TableHead>Medications</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Encounter</TableHead>
                                    <TableHead>Prescribed</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {recentPrescriptions.map((prescription) => (
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

                                {recentPrescriptions.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="p-0">
                                            <EmptyState
                                                icon={Pill}
                                                title="No prescriptions yet"
                                                description="Prescriptions appear here once a doctor prescribes medication from a clinical encounter."
                                            />
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
