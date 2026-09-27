import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'NHIF', href: '/nhif/dashboard' },
    { title: 'Pre-Approval Sessions', href: '#' },
];

const statusColors: Record<string, string> = {
    draft: 'bg-gray-100 text-gray-700',
    submitted: 'bg-blue-100 text-blue-700',
    partially_approved: 'bg-yellow-100 text-yellow-700',
    approved: 'bg-green-100 text-green-700',
    rejected: 'bg-red-100 text-red-700',
    issued: 'bg-purple-100 text-purple-700',
    cancelled: 'bg-gray-100 text-gray-500',
};

const itemStatusColors: Record<string, string> = {
    pending: 'text-muted-foreground',
    approved: 'text-green-600 font-medium',
    rejected: 'text-destructive',
    issued: 'text-purple-600 font-medium',
};

interface SessionItem {
    id: number;
    source_type: string | null;
    nhif_item_code: string;
    nhif_item_name: string | null;
    quantity: number;
    unit_price: string;
    approval_reference_no: string | null;
    approval_status: 'pending' | 'approved' | 'rejected' | 'issued';
    quantity_approved: number | null;
    quantity_issued: number | null;
    rejection_reason: string | null;
}

interface Session {
    id: number;
    nhif_authorization_no: string;
    status: string;
    submitted_at: string | null;
    synced_at: string | null;
    issued_at: string | null;
    items: SessionItem[];
}

interface Patient {
    id: number;
    first_name: string;
    last_name: string;
}

interface Insurance {
    id: number;
}

interface Props {
    patient: Patient;
    insurance: Insurance;
    session: Session;
}

export default function Show({ patient, insurance, session }: Props) {
    const postTo = (path: string) => router.post(path);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Pre-Approval Session #${session.id}`} />

            <div className="p-6 space-y-6">
                <div className="flex items-start justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold">Pre-Approval Session #{session.id}</h1>
                        <p className="text-muted-foreground mt-1">{patient.first_name} {patient.last_name}</p>
                    </div>
                    <span className={`px-3 py-1 rounded-full text-sm font-medium ${statusColors[session.status] ?? 'bg-gray-100'}`}>
                        {session.status.replace(/_/g, ' ').toUpperCase()}
                    </span>
                </div>

                <div className="bg-card border rounded-lg p-4 grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <span className="text-muted-foreground text-xs uppercase tracking-wide">NHIF Authorization No</span>
                        <p className="font-mono font-medium mt-0.5">{session.nhif_authorization_no}</p>
                    </div>
                    <div>
                        <span className="text-muted-foreground text-xs uppercase tracking-wide">Submitted At</span>
                        <p className="mt-0.5">{session.submitted_at ?? '—'}</p>
                    </div>
                    <div>
                        <span className="text-muted-foreground text-xs uppercase tracking-wide">Synced At</span>
                        <p className="mt-0.5">{session.synced_at ?? '—'}</p>
                    </div>
                    <div>
                        <span className="text-muted-foreground text-xs uppercase tracking-wide">Issued At</span>
                        <p className="mt-0.5">{session.issued_at ?? '—'}</p>
                    </div>
                </div>

                <div className="flex gap-3 flex-wrap">
                    {session.status === 'draft' && (
                        <Button onClick={() => postTo(route('patients.insurance.nhif.pre-approval-sessions.submit', [patient.id, insurance.id, session.id]))}>
                            Submit Request
                        </Button>
                    )}
                    {session.status === 'submitted' && (
                        <Button variant="outline" onClick={() => postTo(route('patients.insurance.nhif.pre-approval-sessions.sync', [patient.id, insurance.id, session.id]))}>
                            Sync Approval Status
                        </Button>
                    )}
                    {(session.status === 'approved' || session.status === 'partially_approved') && (
                        <Button className="bg-green-600 hover:bg-green-700"
                            onClick={() => postTo(route('patients.insurance.nhif.pre-approval-sessions.issue', [patient.id, insurance.id, session.id]))}>
                            Issue Approved Services
                        </Button>
                    )}
                    {!['issued', 'cancelled'].includes(session.status) && (
                        <Button variant="destructive" onClick={() => {
                            if (confirm('Cancel this pre-approval session?')) {
                                postTo(route('patients.insurance.nhif.pre-approval-sessions.cancel', [patient.id, insurance.id, session.id]));
                            }
                        }}>
                            Cancel
                        </Button>
                    )}
                </div>

                <div className="border rounded-lg overflow-hidden">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 border-b">
                            <tr>
                                <th className="text-left px-4 py-3 font-medium">Service</th>
                                <th className="text-left px-4 py-3 font-medium">NHIF Code</th>
                                <th className="text-right px-4 py-3 font-medium">Qty</th>
                                <th className="text-right px-4 py-3 font-medium">Approved</th>
                                <th className="text-right px-4 py-3 font-medium">Issued</th>
                                <th className="text-right px-4 py-3 font-medium">Unit Price</th>
                                <th className="text-left px-4 py-3 font-medium">Status</th>
                                <th className="text-left px-4 py-3 font-medium">Ref No</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {session.items.map(item => (
                                <tr key={item.id}>
                                    <td className="px-4 py-3">
                                        <div>{item.nhif_item_name ?? '—'}</div>
                                        {item.source_type && (
                                            <div className="text-xs text-muted-foreground capitalize">{item.source_type}</div>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 font-mono text-xs">{item.nhif_item_code}</td>
                                    <td className="px-4 py-3 text-right">{item.quantity}</td>
                                    <td className="px-4 py-3 text-right">{item.quantity_approved ?? '—'}</td>
                                    <td className="px-4 py-3 text-right">{item.quantity_issued ?? '—'}</td>
                                    <td className="px-4 py-3 text-right">
                                        {Number(item.unit_price).toLocaleString()}
                                    </td>
                                    <td className={`px-4 py-3 capitalize ${itemStatusColors[item.approval_status] ?? ''}`}>
                                        {item.approval_status.replace(/_/g, ' ')}
                                        {item.rejection_reason && (
                                            <div className="text-xs text-destructive mt-0.5">{item.rejection_reason}</div>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 font-mono text-xs">
                                        {item.approval_reference_no ?? '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {session.status === 'issued' && (
                    <div className="bg-purple-50 border border-purple-200 rounded-lg p-4 text-purple-800 text-sm">
                        All approved services have been issued. This session is ready for OCS claim submission.
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
