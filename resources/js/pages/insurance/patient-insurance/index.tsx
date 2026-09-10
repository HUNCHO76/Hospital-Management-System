import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Pagination } from '@/components/pagination';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { effectiveInsuranceStatus, insuranceStatusLabel } from '@/lib/insurance-status';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type InsuranceProviderSummary } from '@/types/insurance-provider';
import { type PatientInsurance, type PatientInsuranceFilters } from '@/types/patient-insurance';
import { Head, Link, router } from '@inertiajs/react';
import { type FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Patient Insurance', href: '/insurance/patient-insurance' }];

interface PatientInsuranceIndexProps {
    records: Paginated<PatientInsurance>;
    filters: PatientInsuranceFilters;
    providers: InsuranceProviderSummary[];
}

export default function PatientInsuranceIndex({ records, filters, providers }: PatientInsuranceIndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');

    const applyFilters = (overrides: Partial<PatientInsuranceFilters>) => {
        router.get(
            route('insurance.patient-insurance.index'),
            {
                search,
                insurance_provider_id: filters.insurance_provider_id ?? '',
                status: filters.status ?? '',
                verification_status: filters.verification_status ?? '',
                ...overrides,
            },
            { preserveState: true, replace: true },
        );
    };

    const submitSearch: FormEventHandler = (e) => {
        e.preventDefault();
        applyFilters({ search });
    };

    const hasFilters = Boolean(filters.search || filters.insurance_provider_id || filters.status || filters.verification_status);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Patient Insurance" />

            <div className="flex flex-col gap-6 p-4">
                <h1 className="text-2xl font-semibold">Patient Insurance</h1>

                <div className="flex flex-wrap items-end gap-3">
                    <form onSubmit={submitSearch} className="flex min-w-[260px] flex-1 gap-2">
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search by patient, membership, policy or card number"
                        />
                        <Button type="submit" variant="secondary">
                            Search
                        </Button>
                    </form>

                    <Select
                        value={filters.insurance_provider_id || 'all'}
                        onValueChange={(value) => applyFilters({ insurance_provider_id: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-[200px]">
                            <SelectValue placeholder="Provider" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All providers</SelectItem>
                            {providers.map((provider) => (
                                <SelectItem key={provider.id} value={String(provider.id)}>
                                    {provider.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select value={filters.status || 'all'} onValueChange={(value) => applyFilters({ status: value === 'all' ? '' : value })}>
                        <SelectTrigger className="w-[150px]">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All statuses</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                            <SelectItem value="expired">Expired</SelectItem>
                            <SelectItem value="suspended">Suspended</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.verification_status || 'all'}
                        onValueChange={(value) => applyFilters({ verification_status: value === 'all' ? '' : value })}
                    >
                        <SelectTrigger className="w-[170px]">
                            <SelectValue placeholder="Verification" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All verification</SelectItem>
                            <SelectItem value="pending">Pending</SelectItem>
                            <SelectItem value="verified">Verified</SelectItem>
                            <SelectItem value="failed">Failed</SelectItem>
                            <SelectItem value="expired">Expired</SelectItem>
                        </SelectContent>
                    </Select>

                    {hasFilters && (
                        <Button
                            variant="ghost"
                            onClick={() => {
                                setSearch('');
                                router.get(route('insurance.patient-insurance.index'));
                            }}
                        >
                            Clear filters
                        </Button>
                    )}
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                    <table className="w-full text-left text-sm">
                        <thead className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b">
                            <tr>
                                <th className="px-4 py-3 font-medium">Patient</th>
                                <th className="px-4 py-3 font-medium">Provider</th>
                                <th className="px-4 py-3 font-medium">Membership Number</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                                <th className="px-4 py-3 font-medium">Verification</th>
                            </tr>
                        </thead>
                        <tbody>
                            {records.data.map((record) => {
                                const displayStatus = effectiveInsuranceStatus(record.status, record.effective_to);

                                return (
                                    <tr key={record.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b last:border-0">
                                        <td className="px-4 py-3">
                                            {record.patient ? (
                                                <Link href={route('patients.show', record.patient.id)} className="font-medium hover:underline">
                                                    {record.patient.patient_number} —{' '}
                                                    {[record.patient.first_name, record.patient.middle_name, record.patient.last_name]
                                                        .filter(Boolean)
                                                        .join(' ')}
                                                </Link>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                        <td className="px-4 py-3">{record.insurance_provider?.name ?? '—'}</td>
                                        <td className="px-4 py-3">{record.membership_number ?? '—'}</td>
                                        <td className="px-4 py-3">
                                            <Badge variant={displayStatus === 'active' ? 'default' : 'secondary'}>
                                                {insuranceStatusLabel[displayStatus]}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 capitalize">{record.verification_status}</td>
                                    </tr>
                                );
                            })}

                            {records.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="px-4 py-12 text-center">
                                        <p className="text-muted-foreground">
                                            {hasFilters ? 'No records match your search or filters.' : 'No patient insurance records found.'}
                                        </p>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination links={records.links} />
            </div>
        </AppLayout>
    );
}
