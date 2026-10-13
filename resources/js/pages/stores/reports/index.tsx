import { PageHeader } from '@/components/page-header';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    Clock,
    DollarSign,
    FileSpreadsheet,
    ShieldAlert,
    TrendingUp,
} from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Store & Inventory', href: '/stores' },
    { title: 'Reports & Analytics', href: '/stores/reports' },
];

export default function ReportsIndex() {
    const reportCards = [
        {
            title: 'Inventory Stock Valuation Report',
            description: 'Item-by-item and store-by-store stock valuations based on actual unit purchase costs and FEFO batch balances.',
            href: '/stores/reports/stock-valuation',
            icon: DollarSign,
            color: 'text-emerald-600 bg-emerald-50',
        },
        {
            title: 'Stock Expiry Tracking (FEFO)',
            description: 'Critical batch monitoring identifying expired items and batches expiring within 30, 60, and 90 days.',
            href: '/stores/reports/expiry',
            icon: Clock,
            color: 'text-amber-600 bg-amber-50',
        },
        {
            title: 'Reorder Level & Stockout Alerts',
            description: 'Inventory items falling below defined safety buffers and critical replenishment reorder thresholds.',
            href: '/stores/reports/reorder-alerts',
            icon: AlertTriangle,
            color: 'text-red-600 bg-red-50',
        },
        {
            title: '3-Way Matching Discrepancies',
            description: 'Audit report of price variances and quantity over-billings detected between POs, GRNs, and Vendor Invoices.',
            href: '/stores/reports/discrepancies',
            icon: ShieldAlert,
            color: 'text-purple-600 bg-purple-50',
        },
        {
            title: 'Department Consumption Analysis',
            description: 'Clinical unit consumption patterns (ICU, Maternity, Wards, OPD) and total monthly cost allocations.',
            href: '/stores/reports/department-consumption',
            icon: BarChart3,
            color: 'text-blue-600 bg-blue-50',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Procurement & Stores Reports" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <PageHeader
                    title="Procurement & Inventory Analytics"
                    description="Standard financial, operational, and clinical consumption reports adhering to MySQL full group by compliance."
                />

                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    {reportCards.map((rc, idx) => {
                        const Icon = rc.icon;
                        return (
                            <Link key={idx} href={rc.href}>
                                <Card className="h-full hover:shadow-md hover:border-primary/50 transition cursor-pointer">
                                    <CardHeader className="flex flex-row items-start space-y-0 gap-4">
                                        <div className={`p-3 rounded-lg ${rc.color}`}>
                                            <Icon className="h-6 w-6" />
                                        </div>
                                        <div className="space-y-1">
                                            <CardTitle className="text-base">{rc.title}</CardTitle>
                                            <CardDescription className="text-xs">
                                                {rc.description}
                                            </CardDescription>
                                        </div>
                                    </CardHeader>
                                </Card>
                            </Link>
                        );
                    })}
                </div>
            </div>
        </AppLayout>
    );
}
