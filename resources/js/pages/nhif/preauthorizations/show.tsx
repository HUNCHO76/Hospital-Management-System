import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NHIF', href: '/nhif/dashboard' },
    { title: 'Pre-authorisation', href: '#' },
];

const statusColors: Record<string, string> = {
    pending: 'bg-yellow-100 text-yellow-700',
    submitted: 'bg-blue-100 text-blue-700',
    approved: 'bg-green-100 text-green-700',
    rejected: 'bg-red-100 text-red-700',
    cancelled: 'bg-gray-100 text-gray-500',
};

interface Document { id: number; original_name: string; mime_type: string | null; file_size: number | null; created_at: string }

interface Preauth {
    id: number;
    status: string;
    source_type: string | null;
    source_id: number | null;
    approved_amount: number | null;
    rejection_reason: string | null;
    request_data: Record<string, unknown> | null;
    response_data: Record<string, unknown> | null;
    submitted_at: string | null;
    resolved_at: string | null;
    hospital: { id: number; name: string } | null;
    patient_insurance: { id: number; membership_number: string; patient: { id: number; name: string } | null } | null;
    created_by: string | null;
    created_at: string;
    updated_at: string;
    documents: Document[];
}

interface Props { preauth: Preauth }

export default function PreauthorizationShow({ preauth }: Props) {
    const submit = () => {
        if (confirm('Submit this pre-authorisation request to NHIF?')) {
            router.post(route('nhif.preauthorization.submit', preauth.id));
        }
    };

    const cancel = () => {
        if (confirm('Cancel this pre-authorisation?')) {
            router.post(route('nhif.preauthorization.cancel', preauth.id));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Pre-auth #${preauth.id}`} />

            <div className="mx-auto max-w-2xl p-4">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">Pre-authorisation #{preauth.id}</h1>
                        <span className={`mt-1 inline-block rounded-full px-2 py-0.5 text-xs font-medium ${statusColors[preauth.status] ?? ''}`}>
                            {preauth.status}
                        </span>
                    </div>
                    <div className="flex gap-2">
                        {(preauth.status === 'pending' || preauth.status === 'rejected') && (
                            <Button size="sm" onClick={submit}>Submit to NHIF</Button>
                        )}
                        {preauth.status !== 'cancelled' && preauth.status !== 'approved' && (
                            <Button size="sm" variant="destructive" onClick={cancel}>Cancel</Button>
                        )}
                    </div>
                </div>

                <dl className="divide-y rounded-md border text-sm">
                    <Row label="Patient" value={preauth.patient_insurance?.patient?.name ?? '—'} />
                    <Row label="Membership #" value={preauth.patient_insurance?.membership_number ?? '—'} />
                    <Row label="Hospital" value={preauth.hospital?.name ?? '—'} />
                    <Row label="Source" value={preauth.source_type ? `${preauth.source_type} #${preauth.source_id}` : '—'} />
                    <Row label="Approved Amount" value={preauth.approved_amount != null ? `TZS ${Number(preauth.approved_amount).toLocaleString()}` : '—'} />
                    {preauth.rejection_reason && <Row label="Rejection Reason" value={preauth.rejection_reason} />}
                    <Row label="Submitted At" value={preauth.submitted_at ? new Date(preauth.submitted_at).toLocaleString() : 'Not submitted'} />
                    <Row label="Resolved At" value={preauth.resolved_at ? new Date(preauth.resolved_at).toLocaleString() : '—'} />
                    <Row label="Created By" value={`${preauth.created_by ?? '—'} on ${new Date(preauth.created_at).toLocaleDateString()}`} />
                </dl>

                {preauth.documents.length > 0 && (
                    <div className="mt-6">
                        <h2 className="mb-2 text-sm font-medium">Documents</h2>
                        <ul className="divide-y rounded-md border">
                            {preauth.documents.map((d) => (
                                <li key={d.id} className="flex items-center justify-between px-4 py-2 text-sm">
                                    <span>{d.original_name}</span>
                                    <span className="text-xs text-muted-foreground">{new Date(d.created_at).toLocaleDateString()}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className="mt-4">
                    <Button variant="ghost" asChild>
                        <Link href={route('nhif.dashboard')}>← Dashboard</Link>
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
