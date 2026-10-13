import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/hooks/use-permissions';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Eye, FilePlus2, Filter, Plus, Search } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Procurement', href: '/procurement' },
    { title: 'Purchase Indents', href: '/procurement/indents' },
];

interface Indent {
    id: number;
    indent_number: string;
    department?: { id: number; name: string };
    requested_by?: { UserName: string };
    target_store?: { name: string };
    indent_date: string;
    required_by_date: string | null;
    priority: string;
    status: string;
    notes: string | null;
}

interface PaginationProps {
    data: Indent[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
}

interface Props {
    indents: PaginationProps;
    departments: { id: number; name: string }[];
    filters: {
        status: string;
        department_id: number;
        search: string;
    };
}

function getStatusBadge(status: string) {
    switch (status.toUpperCase()) {
        case 'APPROVED':
        case 'FULFILLED':
            return <Badge className="bg-emerald-600 text-white">{status}</Badge>;
        case 'SUBMITTED':
            return <Badge className="bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300">{status}</Badge>;
        case 'PARTIALLY_FULFILLED':
            return <Badge className="bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300">{status}</Badge>;
        case 'REJECTED':
        case 'CANCELLED':
            return <Badge variant="destructive">{status}</Badge>;
        default:
            return <Badge variant="outline">{status}</Badge>;
    }
}

export default function IndentsIndex({ indents, departments, filters }: Props) {
    const { can } = usePermissions();
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || '');
    const [departmentId, setDepartmentId] = useState(filters.department_id ? String(filters.department_id) : '');

    const handleFilter = () => {
        router.get(
            '/procurement/indents',
            {
                search: search || undefined,
                status: status || undefined,
                department_id: departmentId || undefined,
            },
            { preserveState: true }
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Purchase Indents & Requisitions" />

            <div className="flex flex-col gap-6 p-4">
                <PageHeader
                    title="Department Purchase Indents"
                    description="Requisitions submitted by clinical and administrative departments for procurement."
                    actions={
                        can('procurement-indents.create') && (
                            <Button asChild>
                                <Link href="/procurement/indents/create">
                                    <Plus className="mr-1.5 h-4 w-4" /> New Requisition Indent
                                </Link>
                            </Button>
                        )
                    }
                />

                {/* Filters */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="grid gap-3 sm:grid-cols-4">
                            <div className="sm:col-span-2 relative">
                                <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search by indent number, notes..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && handleFilter()}
                                    className="pl-9"
                                />
                            </div>

                            <select
                                className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                value={departmentId}
                                onChange={(e) => {
                                    setDepartmentId(e.target.value);
                                    router.get('/procurement/indents', { search, status, department_id: e.target.value || undefined }, { preserveState: true });
                                }}
                            >
                                <option value="">All Departments</option>
                                {departments.map((d) => (
                                    <option key={d.id} value={d.id}>
                                        {d.name}
                                    </option>
                                ))}
                            </select>

                            <select
                                className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                value={status}
                                onChange={(e) => {
                                    setStatus(e.target.value);
                                    router.get('/procurement/indents', { search, status: e.target.value || undefined, department_id: departmentId }, { preserveState: true });
                                }}
                            >
                                <option value="">All Statuses</option>
                                <option value="DRAFT">Draft</option>
                                <option value="SUBMITTED">Submitted</option>
                                <option value="APPROVED">Approved</option>
                                <option value="PARTIALLY_FULFILLED">Partially Fulfilled</option>
                                <option value="FULFILLED">Fulfilled</option>
                                <option value="REJECTED">Rejected</option>
                                <option value="CANCELLED">Cancelled</option>
                            </select>
                        </div>
                    </CardContent>
                </Card>

                {/* Indents List */}
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Indent No</TableHead>
                                    <TableHead>Department</TableHead>
                                    <TableHead>Requested By</TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Required By</TableHead>
                                    <TableHead>Priority</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Action</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {indents.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={8} className="text-center py-8 text-muted-foreground">
                                            No indents match the criteria.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    indents.data.map((indent) => (
                                        <TableRow key={indent.id}>
                                            <TableCell className="font-medium">
                                                <Link href={`/procurement/indents/${indent.id}`} className="text-primary hover:underline">
                                                    {indent.indent_number}
                                                </Link>
                                            </TableCell>
                                            <TableCell>{indent.department?.name || '—'}</TableCell>
                                            <TableCell className="text-sm">{indent.requested_by?.UserName || '—'}</TableCell>
                                            <TableCell className="text-sm">{indent.indent_date}</TableCell>
                                            <TableCell className="text-sm">{indent.required_by_date || '—'}</TableCell>
                                            <TableCell className="capitalize text-xs font-semibold">{indent.priority}</TableCell>
                                            <TableCell>{getStatusBadge(indent.status)}</TableCell>
                                            <TableCell className="text-right">
                                                <Button asChild variant="outline" size="sm">
                                                    <Link href={`/procurement/indents/${indent.id}`}>
                                                        <Eye className="h-3.5 w-3.5 mr-1" /> View
                                                    </Link>
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {indents.links && indents.links.length > 3 && (
                    <div className="flex justify-center gap-1">
                        {indents.links.map((link, idx) => (
                            <Button
                                key={idx}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!link.url}
                                onClick={() => link.url && router.visit(link.url)}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
