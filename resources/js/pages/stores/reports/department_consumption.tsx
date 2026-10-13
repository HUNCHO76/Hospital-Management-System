import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, BarChart3, Filter, Printer } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Store & Inventory', href: '/stores' },
    { title: 'Reports', href: '/stores/reports' },
    { title: 'Department Consumption', href: '/stores/reports/department-consumption' },
];

interface ConsumptionRecord {
    department_id: number;
    department_name: string;
    procurement_item_id: number;
    item_name: string;
    category: string;
    total_quantity_issued: number;
    total_cost: number;
}

interface Props {
    report: {
        records: ConsumptionRecord[];
        total_consumption_value: number;
        total_items_issued: number;
    };
    departments: { id: number; name: string }[];
    filters: {
        department_id: number | null;
        start_date: string | null;
        end_date: string | null;
    };
}

export default function DepartmentConsumptionReport({ report, departments, filters }: Props) {
    const [deptId, setDeptId] = useState(filters.department_id ? String(filters.department_id) : '');
    const [startDate, setStartDate] = useState(filters.start_date || '');
    const [endDate, setEndDate] = useState(filters.end_date || '');

    const handleFilter = () => {
        router.get('/stores/reports/department-consumption', {
            department_id: deptId || undefined,
            start_date: startDate || undefined,
            end_date: endDate || undefined,
        }, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Department Consumption Report - Stores" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <PageHeader
                        title="Department Stock Consumption Report"
                        description="Track consumption patterns across clinical wards, operating theaters, and diagnostic units by inventory cost value."
                    />
                    <div className="flex items-center gap-2">
                        <Link href="/stores/reports">
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="mr-2 h-4 w-4" /> Back to Reports
                            </Button>
                        </Link>
                        <Button size="sm" variant="outline" onClick={() => window.print()}>
                            <Printer className="mr-2 h-4 w-4" /> Print
                        </Button>
                    </div>
                </div>

                {/* Summary */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs uppercase text-muted-foreground">Total Department Consumption</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold text-primary">
                                TZS {Number(report.total_consumption_value).toLocaleString()}
                            </div>
                            <p className="text-xs text-muted-foreground mt-1">Cost of medicines and supplies issued</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-xs uppercase text-muted-foreground">Total Physical Units Issued</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold">
                                {Number(report.total_items_issued).toLocaleString()}
                            </div>
                            <p className="text-xs text-muted-foreground mt-1">Dispatched to clinical departments</p>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <Card>
                    <CardContent className="p-4 flex flex-col md:flex-row gap-3 items-end">
                        <div className="flex-1 space-y-1">
                            <label className="text-xs text-muted-foreground">Department</label>
                            <select
                                value={deptId}
                                onChange={(e) => setDeptId(e.target.value)}
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            >
                                <option value="">All Departments</option>
                                {departments.map((d) => (
                                    <option key={d.id} value={d.id}>{d.name}</option>
                                ))}
                            </select>
                        </div>

                        <div className="space-y-1">
                            <label className="text-xs text-muted-foreground">Start Date</label>
                            <Input
                                type="date"
                                value={startDate}
                                onChange={(e) => setStartDate(e.target.value)}
                            />
                        </div>

                        <div className="space-y-1">
                            <label className="text-xs text-muted-foreground">End Date</label>
                            <Input
                                type="date"
                                value={endDate}
                                onChange={(e) => setEndDate(e.target.value)}
                            />
                        </div>

                        <Button onClick={handleFilter} variant="secondary">
                            <Filter className="mr-2 h-4 w-4" /> Filter
                        </Button>
                    </CardContent>
                </Card>

                {/* Consumption Table */}
                <Card>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted text-xs uppercase text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3">Department</th>
                                        <th className="px-4 py-3">Item Description</th>
                                        <th className="px-4 py-3">Category</th>
                                        <th className="px-4 py-3">Total Qty Consumed</th>
                                        <th className="px-4 py-3 text-right">Total Cost (TZS)</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {report.records.length === 0 ? (
                                        <tr>
                                            <td colSpan={5} className="py-8 text-center text-muted-foreground">
                                                No department consumption records found for the selected period.
                                            </td>
                                        </tr>
                                    ) : (
                                        report.records.map((rec, idx) => (
                                            <tr key={idx} className="hover:bg-muted/40">
                                                <td className="px-4 py-3 font-semibold">{rec.department_name}</td>
                                                <td className="px-4 py-3 font-medium text-primary">{rec.item_name}</td>
                                                <td className="px-4 py-3 capitalize text-muted-foreground">{rec.category.replace('_', ' ')}</td>
                                                <td className="px-4 py-3 font-bold">{Number(rec.total_quantity_issued).toLocaleString()}</td>
                                                <td className="px-4 py-3 text-right font-semibold">
                                                    TZS {Number(rec.total_cost).toLocaleString()}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                                <tfoot>
                                    <tr className="bg-muted/50 font-bold text-primary">
                                        <td colSpan={4} className="px-4 py-3 text-right text-base">Total Department Expenditure:</td>
                                        <td className="px-4 py-3 text-right text-base">
                                            TZS {Number(report.total_consumption_value).toLocaleString()}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
