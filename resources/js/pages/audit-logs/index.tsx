import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type AuditLogFilterOptions, type AuditLogFilters, type AuditLogListItem } from '@/types/audit-log';
import { Head, Link, router } from '@inertiajs/react';
import { ScrollText } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Audit Logs', href: '/audit-logs' }];

interface AuditLogsIndexProps {
    logs: Paginated<AuditLogListItem>;
    filters: AuditLogFilters;
    filterOptions: AuditLogFilterOptions;
}

function formatDateTime(value: string): string {
    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}

export default function AuditLogsIndex({ logs, filters, filterOptions }: AuditLogsIndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');

    const applyFilters = (overrides: Partial<AuditLogFilters>) => {
        router.get(
            route('audit-logs.index'),
            {
                search,
                action: filters.action ?? '',
                module: filters.module ?? '',
                date_from: filters.date_from ?? '',
                date_to: filters.date_to ?? '',
                ...overrides,
            },
            { preserveState: true, replace: true },
        );
    };

    const submitSearch: FormEventHandler = (e) => {
        e.preventDefault();
        applyFilters({ search });
    };

    const hasFilters = Boolean(filters.search || filters.action || filters.module || filters.date_from || filters.date_to);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Audit Logs" />

            <div className="flex flex-col gap-6 p-4">
                <PageHeader title="Audit Logs" description="A record of important system activity. Entries are read-only." />

                <div className="flex flex-wrap items-end gap-3">
                    <form onSubmit={submitSearch} className="flex min-w-[240px] flex-1 gap-2">
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search user, action, record or IP" />
                        <Button type="submit" variant="secondary">
                            Search
                        </Button>
                    </form>

                    <Select value={filters.action || 'all'} onValueChange={(value) => applyFilters({ action: value === 'all' ? '' : value })}>
                        <SelectTrigger className="w-[190px]">
                            <SelectValue placeholder="Action" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All actions</SelectItem>
                            {filterOptions.actions.map((action) => (
                                <SelectItem key={action} value={action}>
                                    {action}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select value={filters.module || 'all'} onValueChange={(value) => applyFilters({ module: value === 'all' ? '' : value })}>
                        <SelectTrigger className="w-[170px]">
                            <SelectValue placeholder="Module" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All modules</SelectItem>
                            {filterOptions.modules.map((module) => (
                                <SelectItem key={module} value={module}>
                                    {module}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <div className="flex flex-col gap-1">
                        <label className="text-muted-foreground text-xs">From</label>
                        <Input
                            type="date"
                            className="w-[150px]"
                            value={filters.date_from ?? ''}
                            onChange={(e) => applyFilters({ date_from: e.target.value })}
                        />
                    </div>

                    <div className="flex flex-col gap-1">
                        <label className="text-muted-foreground text-xs">To</label>
                        <Input
                            type="date"
                            className="w-[150px]"
                            value={filters.date_to ?? ''}
                            onChange={(e) => applyFilters({ date_to: e.target.value })}
                        />
                    </div>

                    {hasFilters && (
                        <Button
                            variant="ghost"
                            onClick={() => {
                                setSearch('');
                                router.get(route('audit-logs.index'));
                            }}
                        >
                            Clear filters
                        </Button>
                    )}
                </div>

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Date/Time</TableHead>
                            <TableHead>User</TableHead>
                            <TableHead>Action</TableHead>
                            <TableHead>Module</TableHead>
                            <TableHead>Record</TableHead>
                            <TableHead>IP Address</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {logs.data.map((log) => (
                            <TableRow key={log.id}>
                                <TableCell className="whitespace-nowrap">
                                    <Link href={route('audit-logs.show', log.id)} className="font-medium hover:underline">
                                        {formatDateTime(log.created_at)}
                                    </Link>
                                </TableCell>
                                <TableCell>{log.user ? log.user.email : <span className="text-muted-foreground">System</span>}</TableCell>
                                <TableCell>
                                    <Badge variant="secondary">{log.action}</Badge>
                                </TableCell>
                                <TableCell>{log.module}</TableCell>
                                <TableCell>{log.record_id ?? '—'}</TableCell>
                                <TableCell>{log.ip_address ?? '—'}</TableCell>
                            </TableRow>
                        ))}

                        {logs.data.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={6} className="p-0">
                                    <EmptyState
                                        icon={ScrollText}
                                        title={hasFilters ? 'No matching audit entries' : 'No audit entries found'}
                                        description={
                                            hasFilters
                                                ? 'No audit entries match your search or filters.'
                                                : 'Important system activity will appear here as it happens.'
                                        }
                                    />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>

                <Pagination links={logs.links} />
            </div>
        </AppLayout>
    );
}
