import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NHIF', href: '/nhif/service-mappings' },
    { title: 'Service Mappings', href: '/nhif/service-mappings' },
    { title: 'View Mapping', href: '#' },
];

const coverageColors: Record<string, string> = {
    covered: 'bg-green-100 text-green-800',
    partially_covered: 'bg-yellow-100 text-yellow-800',
    excluded: 'bg-red-100 text-red-800',
    not_mapped: 'bg-gray-100 text-gray-800',
    unknown: 'bg-gray-100 text-gray-600',
};

interface Price {
    id: number;
    allowable_amount: number;
    currency: string;
    effective_from: string | null;
    effective_to: string | null;
    status: string;
}

interface Mapping {
    id: number;
    service: { id: number; name: string } | null;
    hospital: { id: number; name: string } | null;
    nhif_item_code: string;
    nhif_item_name: string;
    nhif_package_code: string | null;
    nhif_package_name: string | null;
    coverage_status: string;
    requires_preauth: boolean;
    mapping_source: string;
    is_active: boolean;
    effective_from: string | null;
    effective_to: string | null;
    notes: string | null;
    current_allowable_amount: number | null;
    prices: Price[];
    created_by: string | null;
    updated_by: string | null;
    created_at: string;
    updated_at: string;
}

interface Props {
    mapping: Mapping;
}

export default function ServiceMappingShow({ mapping }: Props) {
    const deactivate = () => {
        if (confirm('Deactivate this mapping?')) {
            router.post(route('nhif.service-mappings.deactivate', mapping.id));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Mapping: ${mapping.nhif_item_code}`} />

            <div className="mx-auto max-w-2xl p-4">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-xl font-semibold">
                        {mapping.nhif_item_name}
                        <span className="ml-2 font-mono text-sm text-muted-foreground">({mapping.nhif_item_code})</span>
                    </h1>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <Link href={route('nhif.service-mappings.edit', mapping.id)}>Edit</Link>
                        </Button>
                        {mapping.is_active && (
                            <Button variant="destructive" size="sm" onClick={deactivate}>Deactivate</Button>
                        )}
                    </div>
                </div>

                <dl className="divide-y rounded-md border text-sm">
                    <Row label="HMS Service" value={mapping.service?.name ?? '—'} />
                    <Row label="Facility" value={mapping.hospital?.name ?? '—'} />
                    <Row label="NHIF Item Code" value={<span className="font-mono">{mapping.nhif_item_code}</span>} />
                    <Row label="NHIF Item Name" value={mapping.nhif_item_name} />
                    <Row label="Package" value={mapping.nhif_package_code ? `${mapping.nhif_package_code} — ${mapping.nhif_package_name ?? ''}` : '—'} />
                    <Row label="Coverage" value={
                        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${coverageColors[mapping.coverage_status] ?? ''}`}>
                            {mapping.coverage_status.replace('_', ' ')}
                        </span>
                    } />
                    <Row label="Requires Pre-auth" value={mapping.requires_preauth ? 'Yes' : 'No'} />
                    <Row label="Current Allowable (TZS)" value={mapping.current_allowable_amount != null ? Number(mapping.current_allowable_amount).toLocaleString() : '—'} />
                    <Row label="Source" value={<span className="capitalize">{mapping.mapping_source}</span>} />
                    <Row label="Status" value={<Badge variant={mapping.is_active ? 'default' : 'secondary'}>{mapping.is_active ? 'Active' : 'Inactive'}</Badge>} />
                    <Row label="Effective" value={mapping.effective_from || mapping.effective_to ? `${mapping.effective_from ?? '∞'} → ${mapping.effective_to ?? '∞'}` : 'Always'} />
                    {mapping.notes && <Row label="Notes" value={mapping.notes} />}
                    <Row label="Created by" value={`${mapping.created_by ?? '—'} on ${new Date(mapping.created_at).toLocaleDateString()}`} />
                    <Row label="Updated by" value={`${mapping.updated_by ?? '—'} on ${new Date(mapping.updated_at).toLocaleDateString()}`} />
                </dl>

                {mapping.prices.length > 0 && (
                    <div className="mt-6">
                        <h2 className="mb-2 text-sm font-medium">Price History</h2>
                        <div className="rounded-md border">
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/40">
                                    <tr>
                                        <th className="px-4 py-2 text-right font-medium">Amount</th>
                                        <th className="px-4 py-2 text-left font-medium">From</th>
                                        <th className="px-4 py-2 text-left font-medium">To</th>
                                        <th className="px-4 py-2 text-left font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {mapping.prices.map((p) => (
                                        <tr key={p.id}>
                                            <td className="px-4 py-2 text-right">{Number(p.allowable_amount).toLocaleString()} {p.currency}</td>
                                            <td className="px-4 py-2">{p.effective_from ?? '—'}</td>
                                            <td className="px-4 py-2">{p.effective_to ?? '—'}</td>
                                            <td className="px-4 py-2 capitalize">{p.status}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                <div className="mt-4">
                    <Button variant="ghost" asChild>
                        <Link href={route('nhif.service-mappings.index')}>← Back to mappings</Link>
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}

function Row({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="grid grid-cols-3 px-4 py-3">
            <dt className="font-medium text-muted-foreground">{label}</dt>
            <dd className="col-span-2">{value}</dd>
        </div>
    );
}
