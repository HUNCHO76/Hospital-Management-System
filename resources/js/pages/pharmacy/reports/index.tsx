import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Boxes,
    CalendarClock,
    ClipboardList,
    LineChart,
    PackageX,
    PillBottle,
    Scale,
} from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Pharmacy', href: '/pharmacy' },
    { title: 'Reports', href: '/pharmacy/reports' },
];

const reports = [
    { title: 'Prescriptions', description: 'Every prescription with its verification and dispensing status.', href: 'pharmacy-reports.prescriptions', icon: ClipboardList },
    { title: 'Dispensing', description: 'Medications actually dispensed, with prescribed/dispensed/remaining quantities.', href: 'pharmacy-reports.dispensing', icon: PillBottle },
    { title: 'Medication Utilization', description: 'Consumption trends by medication over a selected period.', href: 'pharmacy-reports.medication-utilization', icon: LineChart },
    { title: 'Stock Batches', description: 'Every batch across every medication, filterable by expiry and status.', href: 'pharmacy-reports.stock-batches', icon: Boxes },
    { title: 'Low Stock', description: 'Medications at or below their reorder level.', href: 'pharmacy-reports.low-stock', icon: AlertTriangle },
    { title: 'Out of Stock', description: 'Medications with no dispensable stock remaining.', href: 'pharmacy-reports.out-of-stock', icon: PackageX },
    { title: 'Expiry', description: 'Expired and expiring-soon batches.', href: 'pharmacy-reports.expiry', icon: CalendarClock },
    { title: 'Stock Movements', description: 'The full, immutable stock transaction ledger.', href: 'pharmacy-reports.stock-movements', icon: Boxes },
    { title: 'Stock Reconciliation', description: 'Each batch\'s recorded quantity checked against its own ledger.', href: 'pharmacy-reports.reconciliation', icon: Scale },
];

/**
 * The pharmacy reports hub (Phase 6, Step 50) — links to every report page.
 * Live KPIs belong on the enriched /pharmacy dashboard itself (spec §5),
 * not duplicated here; this page is purely navigation.
 */
export default function PharmacyReportsIndex() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pharmacy Reports" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Pharmacy Reports</h1>
                    <p className="text-muted-foreground text-sm">Operational reporting across prescriptions, dispensing, and stock.</p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {reports.map((report) => (
                        <Link key={report.href} href={route(report.href)}>
                            <Card className="hover:border-primary/50 h-full transition-colors">
                                <CardHeader>
                                    <report.icon className="text-muted-foreground mb-2 h-5 w-5" />
                                    <CardTitle className="text-base">{report.title}</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-muted-foreground text-sm">{report.description}</p>
                                </CardContent>
                            </Card>
                        </Link>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
