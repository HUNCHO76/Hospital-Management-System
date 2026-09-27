import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NHIF', href: '/nhif/dashboard' },
    { title: 'Utilizations', href: '/nhif/utilizations' },
    { title: 'View', href: '#' },
];

const statusColors: Record<string, string> = {
    pending: 'bg-gray-100 text-gray-700',
    submitted: 'bg-blue-100 text-blue-700',
    accepted: 'bg-green-100 text-green-700',
    rejected: 'bg-red-100 text-red-700',
    reconciled: 'bg-purple-100 text-purple-700',
    failed: 'bg-orange-100 text-orange-700',
    excluded: 'bg-gray-100 text-gray-500',
};

interface Utilization {
    id: number;
    utilization_number: string;
    module: string;
    status: string;
    nhif_item_code: string;
    nhif_item_name: string;
    quantity: number;
    hms_unit_price: number | null;
    hms_amount: number | null;
    nhif_allowable_unit_price: number | null;
    nhif_approved_amount: number | null;
    service_date: string | null;
    attempt_count: number;
    error_message: string | null;
    hospital: { name: string } | null;
    patient: { id: number; name: string } | null;
    patient_insurance: { id: number; membership_number: string } | null;
    service_mapping: { id: number; nhif_item_code: string } | null;
    has_preauthorization: boolean;
    in_claim: boolean;
    created_at: string;
    updated_at: string;
}

interface Props { utilization: Utilization }

export default function UtilizationShow({ utilization }: Props) {
    const submit = () => {
        if (confirm('Mark this utilization as submitted?')) {
            router.post(route('nhif.utilization.submit', utilization.id));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Utilization ${utilization.utilization_number}`} />

            <div className="mx-auto max-w-2xl p-4">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold font-mono">{utilization.utilization_number}</h1>
                        <span className={`mt-1 inline-block rounded-full px-2 py-0.5 text-xs font-medium ${statusColors[utilization.status] ?? ''}`}>
                            {utilization.status}
                        </span>
                    </div>
                    {utilization.status === 'pending' && (
                        <Button size="sm" onClick={submit}>Submit</Button>
                    )}
                </div>

                <dl className="divide-y rounded-md border text-sm">
                    <Row label="Module" value={<span className="capitalize">{utilization.module}</span>} />
                    <Row label="NHIF Code" value={<span className="font-mono">{utilization.nhif_item_code}</span>} />
                    <Row label="NHIF Name" value={utilization.nhif_item_name} />
                    <Row label="Patient" value={utilization.patient?.name ?? '—'} />
                    <Row label="Membership #" value={utilization.patient_insurance?.membership_number ?? '—'} />
                    <Row label="Hospital" value={utilization.hospital?.name ?? '—'} />
                    <Row label="Service Date" value={utilization.service_date ?? '—'} />
                    <Row label="Quantity" value={utilization.quantity} />
                    <Row label="HMS Unit Price" value={utilization.hms_unit_price != null ? `TZS ${Number(utilization.hms_unit_price).toLocaleString()}` : '—'} />
                    <Row label="HMS Amount" value={utilization.hms_amount != null ? `TZS ${Number(utilization.hms_amount).toLocaleString()}` : '—'} />
                    <Row label="NHIF Allowable/unit" value={utilization.nhif_allowable_unit_price != null ? `TZS ${Number(utilization.nhif_allowable_unit_price).toLocaleString()}` : '—'} />
                    <Row label="NHIF Approved" value={utilization.nhif_approved_amount != null ? `TZS ${Number(utilization.nhif_approved_amount).toLocaleString()}` : '—'} />
                    <Row label="Attempt Count" value={utilization.attempt_count} />
                    {utilization.error_message && <Row label="Error" value={<span className="text-red-600">{utilization.error_message}</span>} />}
                    <Row label="Pre-auth" value={utilization.has_preauthorization ? 'Yes' : 'None'} />
                    <Row label="In Claim" value={utilization.in_claim ? 'Yes' : 'No'} />
                </dl>

                <div className="mt-4">
                    <Button variant="ghost" asChild>
                        <Link href={route('nhif.utilization.index')}>← Back to utilizations</Link>
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
