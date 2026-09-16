import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { FileText, CreditCard, LayoutDashboard, RotateCcw } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing', href: '/billing' },
    { title: 'Reports', href: '/billing/reports' },
];

const reports = [
    {
        title: 'Invoices',
        description: 'All posted and draft invoices with totals and status.',
        href: 'billing.reports.invoices',
        icon: FileText,
    },
    {
        title: 'Payments',
        description: 'All recorded payments, filterable by method and date.',
        href: 'billing.reports.payments',
        icon: CreditCard,
    },
    {
        title: 'Cashier Sessions',
        description: 'Session reconciliation report with opening/closing balances and variance.',
        href: 'billing.reports.cashier-sessions',
        icon: LayoutDashboard,
    },
    {
        title: 'Corrections',
        description: 'Refunds, reversals, and billing adjustments.',
        href: 'billing.reports.corrections',
        icon: RotateCcw,
    },
];

export default function BillingReportsIndex() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Billing Reports" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Billing Reports</h1>
                    <p className="text-muted-foreground text-sm">Financial reporting across invoices, payments, sessions, and corrections.</p>
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
